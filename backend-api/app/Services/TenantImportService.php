<?php

namespace App\Services;

use App\Http\Requests\Tenant\StoreTenantRequest;
use App\Models\Landlord;
use App\Models\Tenant;
use App\Models\TenantImport;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Http\UploadedFile;
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
                    throw ValidationException::withMessages(['accept_phone_warnings' => 'Vui lòng xác nhận các dòng có số điện thoại nghi trùng hoặc bỏ chọn các dòng đó.']);
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
            $info = collect($reader->listWorksheetInfo($file->getPathname()))->firstWhere('worksheetName', 'Nguoi thue');
            if (! $info) {
                $this->fileError('Thiếu sheet "Nguoi thue". Vui lòng sử dụng file mẫu BeeHouse.');
            }
            if ($info['totalRows'] > 1001) {
                $this->fileError('File chỉ được chứa tối đa 1.000 người thuê. Hãy xóa các dòng dư khỏi sheet dữ liệu.');
            }
            if ($info['totalColumns'] > count(self::HEADERS)) {
                $this->fileError('Header không khớp file mẫu BeeHouse.');
            }
            $reader->setLoadSheetsOnly(['Nguoi thue']);
            $reader->setReadEmptyCells(false);
            $reader->setReadFilter(new class implements IReadFilter
            {
                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    return $row <= 1001 && in_array($columnAddress, range('A', 'I'), true);
                }
            });
            $book = $reader->load($file->getPathname());
            $sheet = $book->getSheetByName('Nguoi thue');
            $columns = [];
            foreach (range('A', 'I') as $column) {
                $header = trim((string) $sheet->getCell($column.'1')->getValue());
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
        $existingPhones = Tenant::query()->where('landlord_id', $landlordId)->whereIn('phone', $phones)->pluck('phone')->flip();
        $seenIds = [];
        $phoneCounts = array_count_values($phones);
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
            $warning = $phone && ($existingPhones->has($phone) || ($phoneCounts[$phone] ?? 0) > 1);
            $row['status'] = $errors !== [] ? 'invalid' : ($duplicate ? 'duplicate' : ($warning ? 'warning' : 'valid'));
            $row['errors'] = array_values(array_unique($errors));
            $row['warnings'] = $duplicate ? ['CCCD/CMND đã tồn tại trong workspace hoặc dòng trước của file.'] : ($warning ? ['Số điện thoại trùng: hãy kiểm tra trước khi chọn nhập.'] : []);
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

    private function fileError(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
