<?php

namespace App\Services;

use App\Http\Requests\Tenant\StoreTenantRequest;
use App\Models\Landlord;
use App\Models\Tenant;
use App\Models\TenantImport;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Throwable;
use ZipArchive;

class TenantImportService
{
    public const HEADERS = [
        'Họ và tên' => 'full_name', 'Số điện thoại' => 'phone', 'CCCD/CMND' => 'identity_number',
        'Ngày sinh' => 'date_of_birth', 'Giới tính' => 'gender', 'Địa chỉ thường trú' => 'permanent_address',
        'Email' => 'email', 'Nơi cấp' => 'identity_issue_place', 'Ngày cấp' => 'identity_issue_date',
    ];

    public function owner(User $user): Landlord
    {
        $owner = $user->landlord;
        abort_unless($owner !== null, 403, 'Import người thuê hiện chỉ hỗ trợ chủ workspace.');

        return $owner;
    }

    public function preview(User $user, UploadedFile $file): array
    {
        $owner = $this->owner($user);
        TenantImport::query()->where('expires_at', '<=', now())->delete();
        try {
            $rows = $this->parse($file);
        } finally {
            if (is_file($file->getPathname())) {
                unlink($file->getPathname());
            }
        }
        $rows = $this->classify($rows, $owner->id);
        $token = bin2hex(random_bytes(32));
        $session = TenantImport::create([
            'id' => hash('sha256', $token), 'user_id' => $user->id, 'landlord_id' => $owner->id,
            'payload' => $rows, 'expires_at' => now()->addHour(),
        ]);

        return ['token' => $token, 'expires_at' => $session->expires_at->toIso8601String(), 'summary' => $this->summary($rows), 'rows' => $rows];
    }

    public function confirm(User $user, string $token, array $selected, bool $acceptWarnings): array
    {
        $owner = $this->owner($user);

        return DB::transaction(function () use ($user, $owner, $token, $selected, $acceptWarnings): array {
            Landlord::query()->whereKey($owner->id)->lockForUpdate()->firstOrFail();
            $session = TenantImport::query()->whereKey(hash('sha256', $token))->where('user_id', $user->id)
                ->where('landlord_id', $owner->id)->lockForUpdate()->first();
            if (! $session || $session->expires_at->isPast()) {
                throw ValidationException::withMessages(['token' => 'Phiên import đã hết hạn hoặc không hợp lệ. Vui lòng tải file lại.']);
            }
            if ($session->result !== null) {
                return $session->result;
            }
            $rows = $this->classify($session->payload, $owner->id);
            $knownRows = array_column($rows, 'row');
            if (array_diff($selected, $knownRows) !== []) {
                throw ValidationException::withMessages(['selected_rows' => 'Danh sách dòng được chọn không thuộc phiên import.']);
            }
            $created = 0;
            $unselected = 0;
            foreach ($rows as $row) {
                if (in_array($row['status'], ['invalid', 'duplicate'], true)) {
                    continue;
                }
                if (! in_array($row['row'], $selected, true)) {
                    $unselected++;

                    continue;
                }
                if ($row['status'] === 'warning' && ! $acceptWarnings) {
                    throw ValidationException::withMessages(['accept_phone_warnings' => 'Vui lòng xác nhận các dòng cần xem xét hoặc bỏ chọn các dòng đó.']);
                }
                $created++;
            }
            $insert = [];
            foreach ($rows as $row) {
                if (in_array($row['status'], ['valid', 'warning'], true) && in_array($row['row'], $selected, true)) {
                    $insert[] = [...$row['data'], 'landlord_id' => $owner->id, 'created_at' => now(), 'updated_at' => now()];
                }
            }
            foreach (array_chunk($insert, 100) as $chunk) {
                Tenant::query()->insert($chunk);
            }
            $summary = $this->summary($rows);
            $result = ['created' => $created, 'duplicates' => $summary['duplicate'], 'invalid' => $summary['invalid'], 'unselected' => $unselected];
            $session->update(['payload' => null, 'result' => $result]);

            return $result;
        });
    }

    private function parse(UploadedFile $file): array
    {
        $this->checkArchive($file->getPathname());
        $reader = new Xlsx;
        try {
            if (! $reader->canRead($file->getPathname())) {
                $this->fileError('File không phải định dạng Excel .xlsx hợp lệ.');
            }
            $worksheets = $reader->listWorksheetInfo($file->getPathname());
            $info = collect($worksheets)->firstWhere('worksheetName', 'Nguoi thue');
            if (! $info && count($worksheets) === 1) {
                $info = $worksheets[0];
            }
            if (! $info) {
                $this->fileError('Không xác định được sheet dữ liệu. Hãy giữ sheet "Nguoi thue" hoặc chỉ để một sheet có đúng header mẫu BeeHouse.');
            }
            if ($info['totalRows'] > 1001) {
                $this->fileError('File chỉ được chứa tối đa 1.000 người thuê. Hãy xóa các dòng dư khỏi sheet dữ liệu.');
            }
            if ($info['totalColumns'] > count(self::HEADERS)) {
                $this->fileError('Header không khớp file mẫu BeeHouse.');
            }
            $reader->setLoadSheetsOnly([$info['worksheetName']]);
            $reader->setReadEmptyCells(false);
            $reader->setReadFilter(new class implements IReadFilter
            {
                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    return $row <= 1001 && in_array($columnAddress, range('A', 'I'), true);
                }
            });
            $book = $reader->load($file->getPathname());
            $sheet = $book->getSheetByName($info['worksheetName']);
            $columns = [];
            foreach (range('A', 'I') as $column) {
                $header = $this->normalizeHeader($sheet->getCell($column.'1')->getValue());
                if ($header === '') {
                    continue;
                }
                if (! isset(self::HEADERS[$header]) || in_array(self::HEADERS[$header], $columns, true)) {
                    $this->fileError('Header không khớp hoặc bị trùng. Vui lòng sử dụng file mẫu BeeHouse.');
                }
                $columns[$column] = self::HEADERS[$header];
            }
            foreach (array_slice(array_values(self::HEADERS), 0, 6) as $field) {
                if (! in_array($field, $columns, true)) {
                    $this->fileError('Thiếu header bắt buộc. Vui lòng giữ nguyên 6 cột đầu trong file mẫu.');
                }
            }
            $rows = [];
            for ($number = 2; $number <= $sheet->getHighestDataRow(); $number++) {
                $data = array_fill_keys(array_values(self::HEADERS), null);
                $errors = [];
                foreach ($columns as $column => $field) {
                    $cell = $sheet->getCell($column.$number);
                    $value = $cell->getValue();
                    if ($value instanceof RichText) {
                        $value = $value->getPlainText();
                    }
                    if ($value === null || $value === '') {
                        continue;
                    }
                    if ($cell->getDataType() === DataType::TYPE_FORMULA || $cell->getDataType() === DataType::TYPE_ERROR || is_bool($value)) {
                        $errors[] = 'Không chấp nhận công thức, ô lỗi hoặc giá trị logic.';
                        $data[$field] = '[Ô không hợp lệ]';

                        continue;
                    }
                    if (in_array($field, ['phone', 'identity_number'], true) && ! is_string($value)) {
                        $errors[] = 'SĐT và CCCD/CMND phải lưu dưới dạng Text để bảo toàn số 0 đầu.';
                        $data[$field] = '[Cần định dạng Text]';

                        continue;
                    }
                    if (in_array($field, ['date_of_birth', 'identity_issue_date'], true)) {
                        if (is_numeric($value) && ! is_string($value) && Date::isDateTime($cell)) {
                            $calendar = Date::getExcelCalendar();
                            Date::setExcelCalendar($book->getExcelCalendar());
                            try {
                                if ((float) $value < 1 || (float) $value > 80000 || floor((float) $value) !== (float) $value) {
                                    $errors[] = 'Ô ngày Excel không hợp lệ hoặc có phần giờ.';

                                    continue;
                                }
                                $data[$field] = Date::excelToDateTimeObject($value)->format('Y-m-d');
                            } finally {
                                Date::setExcelCalendar($calendar);
                            }
                        } else {
                            $date = DateTimeImmutable::createFromFormat('!d/m/Y', (string) $value);
                            if (! $date || $date->format('d/m/Y') !== $value) {
                                $errors[] = 'Ngày phải đúng định dạng DD/MM/YYYY hoặc ô ngày Excel hợp lệ.';
                            } else {
                                $data[$field] = $date->format('Y-m-d');
                            }
                        }
                    } else {
                        $data[$field] = trim((string) $value);
                    }
                }
                if (! array_filter($data, fn ($value) => $value !== null && $value !== '') && $errors === []) {
                    continue;
                }
                $data = array_map(fn ($value) => $value === '' ? null : $value, $data);
                $data['gender'] = match (mb_strtolower($data['gender'] ?? '')) {
                    'nam', 'male' => 'MALE', 'nữ', 'female' => 'FEMALE', 'khác', 'other' => 'OTHER', '' => null,
                    default => $data['gender'],
                };
                if ($data['phone'] !== null) {
                    $data['phone'] = preg_replace('/[\s().-]/u', '', $data['phone']);
                }
                $rows[] = ['row' => $number, 'data' => $data, 'parse_errors' => array_values(array_unique($errors))];
            }
            $book->disconnectWorksheets();
            if ($rows === []) {
                $this->fileError('File không có dữ liệu người thuê. Hãy điền sheet "Nguoi thue".');
            }

            return $rows;
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->fileError('Không đọc được file Excel. Vui lòng kiểm tra file và định dạng dữ liệu.');
        }
    }

    private function classify(array $rows, int $landlordId): array
    {
        $identities = array_filter(array_column(array_column($rows, 'data'), 'identity_number'));
        $phones = array_filter(array_column(array_column($rows, 'data'), 'phone'));
        $existingIds = Tenant::withTrashed()->where('landlord_id', $landlordId)->whereIn('identity_number', $identities)->pluck('identity_number')->flip();
        $existingTenants = Tenant::query()->where('landlord_id', $landlordId)
            ->get(['full_name', 'phone', 'date_of_birth']);
        $existingTenantsByName = $existingTenants
            ->filter(fn (Tenant $tenant) => $this->normalizeName($tenant->full_name) !== '')
            ->groupBy(fn (Tenant $tenant) => $this->normalizeName($tenant->full_name));
        $existingPhones = $existingTenants
            ->map(fn (Tenant $tenant) => $this->normalizePhone($tenant->phone))
            ->filter()
            ->flip();
        $seenIds = [];
        $phoneCounts = array_count_values($phones);
        $fileTenantsByName = collect($rows)->map(fn (array $row) => [
            'row' => $row['row'],
            'name' => $this->normalizeName($row['data']['full_name']),
            'phone' => $row['data']['phone'],
            'date_of_birth' => $row['data']['date_of_birth'],
        ])->filter(fn (array $row) => $row['name'] !== '')
            ->groupBy('name');
        $rules = (new StoreTenantRequest)->rules();
        foreach ($rows as &$row) {
            $errors = $row['parse_errors'];
            $validation = Validator::make($row['data'], $rules, [
                'full_name.required' => 'Vui lòng nhập họ tên.', '*.max' => ':attribute vượt quá độ dài cho phép.',
                'email.email' => 'Email không hợp lệ.', '*.before_or_equal' => 'Ngày không được nằm trong tương lai.',
                'gender.in' => 'Giới tính chỉ nhận Nam, Nữ hoặc Khác.', '*.date' => 'Ngày không hợp lệ.',
            ], array_flip(self::HEADERS));
            $errors = [...$errors, ...$validation->errors()->all()];
            $identity = $row['data']['identity_number'];
            $phone = $row['data']['phone'];
            if ($identity !== null && ! preg_match('/^(?:[0-9]{9}|[0-9]{12})$/D', $identity)) {
                $errors[] = 'CCCD/CMND phải gồm đúng 9 hoặc 12 chữ số, lưu dạng Text.';
            }
            if ($phone !== null && ! preg_match('/^\+?[0-9]{9,15}$/D', $phone)) {
                $errors[] = 'Số điện thoại không hợp lệ (9–15 chữ số, có thể có dấu + ở đầu).';
            }
            $duplicate = $identity && ($existingIds->has($identity) || isset($seenIds[$identity]));
            $warnings = $this->possibleDuplicateWarnings($row, $existingTenantsByName, $fileTenantsByName);
            if ($phone && ($existingPhones->has($phone) || ($phoneCounts[$phone] ?? 0) > 1)) {
                $warnings[] = 'Số điện thoại trùng trong workspace hoặc file: hãy kiểm tra trước khi chọn nhập.';
            }
            $row['status'] = $errors !== [] ? 'invalid' : ($duplicate ? 'duplicate' : ($warnings !== [] ? 'warning' : 'valid'));
            $row['errors'] = array_values(array_unique($errors));
            $row['warnings'] = $duplicate
                ? ['CCCD/CMND đã tồn tại trong workspace hoặc dòng trước của file.']
                : array_values(array_unique($warnings));
            if ($errors === [] && $identity) {
                $seenIds[$identity] = true;
            }
        }

        return $rows;
    }

    private function summary(array $rows): array
    {
        $counts = array_count_values(array_column($rows, 'status'));

        return ['total' => count($rows), 'valid' => $counts['valid'] ?? 0, 'warning' => $counts['warning'] ?? 0, 'duplicate' => $counts['duplicate'] ?? 0, 'invalid' => $counts['invalid'] ?? 0];
    }

    /**
     * @param  Collection<string, Collection<int, Tenant>>  $existingTenantsByName
     * @param  Collection<string, Collection<int, array{row: int, phone: ?string, date_of_birth: ?string}>>  $fileTenantsByName
     * @return array<int, string>
     */
    private function possibleDuplicateWarnings(array $row, $existingTenantsByName, $fileTenantsByName): array
    {
        $name = $this->normalizeName($row['data']['full_name']);
        if ($name === '') {
            return [];
        }

        $phone = $row['data']['phone'];
        $dateOfBirth = $row['data']['date_of_birth'];
        $warnings = [];
        $existingMatches = $existingTenantsByName->get($name, collect());
        if ($existingMatches->isNotEmpty()) {
            $samePhone = $phone !== null && $existingMatches->contains(fn (Tenant $tenant) => $this->normalizePhone($tenant->phone) === $phone);
            $sameDateOfBirth = $dateOfBirth !== null && $existingMatches->contains(fn (Tenant $tenant) => optional($tenant->date_of_birth)->format('Y-m-d') === $dateOfBirth);
            $warnings[] = $this->nameWarning('workspace', $samePhone, $sameDateOfBirth);
        }

        $fileMatches = $fileTenantsByName->get($name, collect())->where('row', '!=', $row['row']);
        if ($fileMatches->isNotEmpty()) {
            $samePhone = $phone !== null && $fileMatches->contains(fn (array $tenant) => $tenant['phone'] === $phone);
            $sameDateOfBirth = $dateOfBirth !== null && $fileMatches->contains(fn (array $tenant) => $tenant['date_of_birth'] === $dateOfBirth);
            $warnings[] = $this->nameWarning('file', $samePhone, $sameDateOfBirth);
        }

        return $warnings;
    }

    private function nameWarning(string $source, bool $samePhone, bool $sameDateOfBirth): string
    {
        $scope = $source === 'workspace' ? 'hồ sơ trong workspace' : 'dòng khác trong file';
        if ($samePhone && $sameDateOfBirth) {
            return "Họ tên, số điện thoại và ngày sinh trùng với {$scope}: hãy kiểm tra trước khi chọn nhập.";
        }
        if ($samePhone) {
            return "Họ tên và số điện thoại trùng với {$scope}: hãy kiểm tra trước khi chọn nhập.";
        }
        if ($sameDateOfBirth) {
            return "Họ tên và ngày sinh trùng với {$scope}: hãy kiểm tra trước khi chọn nhập.";
        }

        return "Họ tên trùng với {$scope}: hãy kiểm tra trước khi chọn nhập.";
    }

    private function normalizeName(?string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim((string) $value)) ?? '';
        if (class_exists(\Normalizer::class)) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_C) ?: $value;
        }

        return mb_strtolower($value);
    }

    private function normalizePhone(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return preg_replace('/[\s().-]/u', '', $value);
    }

    private function checkArchive(string $path): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            $this->fileError('File không phải định dạng Excel .xlsx hợp lệ.');
        }
        try {
            if ($zip->numFiles > 500 || $zip->locateName('[Content_Types].xml') === false || $zip->locateName('xl/workbook.xml') === false) {
                $this->fileError('File Excel không hợp lệ hoặc cấu trúc quá lớn.');
            }
            $types = $zip->getFromName('[Content_Types].xml', 65536);
            if (! is_string($types) || str_contains($types, 'macroEnabled')) {
                $this->fileError('Chỉ chấp nhận workbook .xlsx không có macro.');
            }
            $size = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                $size += $entry['size'];
                if ($size > 25 * 1024 * 1024 || preg_match('~vbaProject|externalLinks/|embeddings/~i', $entry['name'])) {
                    $this->fileError('File chứa macro/liên kết ngoài hoặc dữ liệu giải nén quá lớn. Vui lòng dùng file mẫu.');
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function normalizeHeader(mixed $value): string
    {
        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }

        return trim((string) $value, " \t\n\r\0\x0B\xEF\xBB\xBF");
    }

    private function fileError(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
