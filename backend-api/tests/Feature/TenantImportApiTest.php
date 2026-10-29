<?php

namespace Tests\Feature;

use App\Models\BoardingHouse;
use App\Models\Landlord;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\TenantImport;
use App\Models\User;
use App\Services\TenantImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class TenantImportApiTest extends TestCase
{
    use RefreshDatabase;

    private array $temporaryPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryPaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function test_owner_imports_ten_rows_only_as_tenant_profiles_and_replay_is_idempotent(): void
    {
        [$user, $owner] = $this->owner();
        $house = BoardingHouse::create(['landlord_id' => $owner->id, 'name' => 'Synthetic', 'address' => 'Synthetic']);
        $room = Room::create(['boarding_house_id' => $house->id, 'room_code' => '101', 'status' => 'AVAILABLE']);
        $file = $this->workbook($this->people(10));
        $preview = $this->actingAs($user, 'sanctum')->postJson('/api/tenants/import/preview', ['file' => $file])
            ->assertOk()->assertJsonPath('data.summary.valid', 10)->assertJsonPath('data.rows.0.data.phone', '0900000000')
            ->assertJsonPath('data.rows.0.data.identity_number', '001000000000')->json('data');
        $this->assertFileDoesNotExist($file->getPathname());
        $raw = DB::table('tenant_imports')->value('payload');
        $this->assertStringNotContainsString('001000000000', $raw);
        $this->assertDatabaseCount('tenants', 0);
        $payload = ['token' => $preview['token'], 'selected_rows' => range(2, 11), 'landlord_id' => 999];
        $this->postJson('/api/tenants/import/confirm', $payload)->assertOk()->assertJsonPath('data.created', 10);
        $this->postJson('/api/tenants/import/confirm', $payload)->assertOk()->assertJsonPath('data.created', 10);
        $this->assertDatabaseCount('tenants', 10);
        $this->assertSame(10, Tenant::where('landlord_id', $owner->id)->count());
        $this->assertDatabaseHas('tenants', ['landlord_id' => $owner->id, 'phone' => '0900000000', 'identity_number' => '001000000000']);
        $this->assertDatabaseCount('contracts', 0);
        $this->assertDatabaseCount('room_tenants', 0);
        $this->assertSame('AVAILABLE', $room->fresh()->status);
        $this->assertNull(TenantImport::first()->payload);
    }

    public function test_hundred_and_thousand_rows_use_batch_queries(): void
    {
        foreach ([100, 1000] as $count) {
            [$user] = $this->owner();
            $started = hrtime(true);
            $preview = app(TenantImportService::class)->preview($user, $this->workbook($this->people($count)));
            $previewMs = (hrtime(true) - $started) / 1e6;
            $this->assertSame($count, $preview['summary']['valid']);
            DB::enableQueryLog();
            DB::flushQueryLog();
            $started = hrtime(true);
            $result = app(TenantImportService::class)->confirm($user, $preview['token'], range(2, $count + 1), false);
            $confirmMs = (hrtime(true) - $started) / 1e6;
            $queryCount = count(DB::getQueryLog());
            DB::disableQueryLog();
            $this->assertSame($count, $result['created']);
            $this->assertLessThanOrEqual(20, $queryCount);
            if (getenv('TENANT_IMPORT_METRICS')) {
                fwrite(STDERR, json_encode(['rows' => $count, 'preview_ms' => round($previewMs, 2), 'confirm_ms' => round($confirmMs, 2), 'confirm_queries' => $queryCount]).PHP_EOL);
            }
        }
    }

    public function test_duplicates_errors_and_phone_warnings_are_explained_and_skipped(): void
    {
        [$user, $owner] = $this->owner();
        Tenant::create(['landlord_id' => $owner->id, 'full_name' => 'Existing', 'identity_number' => '001000000000', 'phone' => '0900000003']);
        $people = $this->people(7);
        $people[2][2] = $people[1][2];
        $people[4][0] = '';
        $people[5][2] = '123';
        $people[6][1] = $people[3][1];
        $preview = app(TenantImportService::class)->preview($user, $this->workbook($people));
        $this->assertSame(['duplicate', 'valid', 'duplicate', 'warning', 'invalid', 'invalid', 'warning'], array_column($preview['rows'], 'status'));
        $this->actingAs($user, 'sanctum')->postJson('/api/tenants/import/confirm', ['token' => $preview['token'], 'selected_rows' => [3, 5]])
            ->assertUnprocessable();
        $this->assertDatabaseCount('tenants', 1);
        $this->postJson('/api/tenants/import/confirm', ['token' => $preview['token'], 'selected_rows' => range(2, 8), 'accept_phone_warnings' => true])
            ->assertOk()->assertJsonPath('data.created', 3)->assertJsonPath('data.duplicates', 2)->assertJsonPath('data.invalid', 2);
        $this->assertDatabaseCount('tenants', 4);
    }

    public function test_exact_name_matches_are_warnings_that_can_be_deselected_or_confirmed(): void
    {
        [$user, $owner] = $this->owner();
        Tenant::create([
            'landlord_id' => $owner->id,
            'full_name' => 'Nguyễn Văn An',
            'phone' => '0900011111',
            'identity_number' => '001000000001',
            'date_of_birth' => '1995-04-23',
        ]);
        Tenant::create([
            'landlord_id' => $owner->id,
            'full_name' => 'Phạm Vũ Thiên Kim',
            'identity_number' => '001000000007',
        ]);

        $people = [
            ['  NGUYỄN   VĂN AN  ', '0900099999', '001000000002', '24/04/1996', null, null, null, null, null],
            ['Nguyễn Văn An', '0900011111', '001000000003', '24/04/1996', null, null, null, null, null],
            ['Nguyễn Văn An', '0900088888', '001000000004', '23/04/1995', null, null, null, null, null],
            ['Phạm Kim Ngân', null, '001000000005', null, null, null, null, null, null],
            ['Trùng trong file', '0900077771', '001000000008', null, null, null, null, null, null],
            ['Trùng   trong file', '0900077772', '001000000009', null, null, null, null, null, null],
        ];

        $preview = $this->actingAs($user, 'sanctum')->postJson('/api/tenants/import/preview', ['file' => $this->workbook($people)])
            ->assertOk()
            ->assertJsonPath('data.summary.warning', 5)
            ->assertJsonPath('data.summary.valid', 1)
            ->assertJsonPath('data.rows.3.status', 'valid')
            ->json('data');
        $this->assertStringContainsString('Họ tên trùng với hồ sơ trong workspace', implode(' ', $preview['rows'][0]['warnings']));
        $this->assertStringContainsString('Họ tên và số điện thoại trùng với hồ sơ trong workspace', implode(' ', $preview['rows'][1]['warnings']));
        $this->assertStringContainsString('Họ tên và ngày sinh trùng với hồ sơ trong workspace', implode(' ', $preview['rows'][2]['warnings']));
        $this->assertStringContainsString('Họ tên trùng với dòng khác trong file', implode(' ', $preview['rows'][4]['warnings']));

        $this->postJson('/api/tenants/import/confirm', ['token' => $preview['token'], 'selected_rows' => [5]])
            ->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.unselected', 5);
        $this->assertDatabaseMissing('tenants', ['identity_number' => '001000000002']);
        $this->assertDatabaseHas('tenants', ['identity_number' => '001000000005']);

        $warningPreview = $this->postJson('/api/tenants/import/preview', ['file' => $this->workbook([$people[0]])])
            ->assertOk()
            ->assertJsonPath('data.rows.0.status', 'warning')
            ->json('data');
        $this->postJson('/api/tenants/import/confirm', ['token' => $warningPreview['token'], 'selected_rows' => [2]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('accept_phone_warnings');
        $this->postJson('/api/tenants/import/confirm', [
            'token' => $warningPreview['token'],
            'selected_rows' => [2],
            'accept_phone_warnings' => true,
        ])->assertOk()->assertJsonPath('data.created', 1);
        $this->assertDatabaseHas('tenants', ['identity_number' => '001000000002']);
    }

    public function test_identity_duplicate_check_is_scoped_and_confirm_rechecks_current_database(): void
    {
        [$user, $owner] = $this->owner();
        [, $other] = $this->owner();
        Tenant::create(['landlord_id' => $other->id, 'full_name' => 'Foreign', 'identity_number' => '001000000000']);
        $preview = app(TenantImportService::class)->preview($user, $this->workbook($this->people(1)));
        $this->assertSame('valid', $preview['rows'][0]['status']);
        Tenant::create(['landlord_id' => $owner->id, 'full_name' => 'Created meanwhile', 'identity_number' => '001000000000']);
        $result = app(TenantImportService::class)->confirm($user, $preview['token'], [2], false);
        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['duplicates']);
    }

    public function test_preview_and_confirm_only_detect_identity_duplicates_within_the_authenticated_landlord(): void
    {
        [$ownerUser, $owner] = $this->owner();
        [$otherUser, $other] = $this->owner();
        Tenant::create([
            'landlord_id' => $owner->id,
            'full_name' => 'Hồ sơ riêng của chủ trọ A',
            'identity_number' => '001000000000',
        ]);

        $otherFile = $this->workbook([['Hồ sơ riêng của chủ trọ A', '0900000000', '001000000000', null, null, null, null, null, null]]);
        $preview = $this->actingAs($otherUser, 'sanctum')->postJson('/api/tenants/import/preview', ['file' => $otherFile])
            ->assertOk()
            ->assertJsonPath('data.summary.valid', 1)
            ->assertJsonPath('data.summary.warning', 0)
            ->assertJsonPath('data.summary.duplicate', 0)
            ->assertJsonPath('data.rows.0.status', 'valid')
            ->json('data');
        $this->postJson('/api/tenants/import/confirm', ['token' => $preview['token'], 'selected_rows' => [2]])
            ->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.duplicates', 0);
        $this->assertDatabaseHas('tenants', ['landlord_id' => $other->id, 'identity_number' => '001000000000']);

        $sameOwner = $this->actingAs($ownerUser, 'sanctum')->postJson('/api/tenants/import/preview', ['file' => $this->workbook($this->people(1))])
            ->assertOk()
            ->assertJsonPath('data.summary.duplicate', 1)
            ->assertJsonPath('data.rows.0.status', 'duplicate')
            ->json('data');
        $this->postJson('/api/tenants/import/confirm', ['token' => $sameOwner['token'], 'selected_rows' => [2]])
            ->assertOk()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.duplicates', 1);

        $withoutIdentity = $this->actingAs($otherUser, 'sanctum')->postJson('/api/tenants/import/preview', ['file' => $this->workbook([
            ['Không CCCD 1', null, null, null, null, null, null, null, null],
            ['Không CCCD 2', null, null, null, null, null, null, null, null],
        ])])
            ->assertOk()
            ->assertJsonPath('data.summary.valid', 2)
            ->assertJsonPath('data.summary.duplicate', 0)
            ->json('data');
        $this->postJson('/api/tenants/import/confirm', ['token' => $withoutIdentity['token'], 'selected_rows' => [2, 3]])
            ->assertOk()
            ->assertJsonPath('data.created', 2);
    }

    public function test_dates_accept_explicit_vietnamese_text_and_excel_serial_and_reject_invalid_values(): void
    {
        [$user] = $this->owner();
        $people = $this->people(4);
        $people[1][3] = Date::PHPToExcel(new \DateTimeImmutable('1997-05-23'));
        $people[2][3] = '31/02/2000';
        $people[3][3] = '2050-01-01';
        $preview = app(TenantImportService::class)->preview($user, $this->workbook($people));
        $this->assertSame('1995-04-23', $preview['rows'][0]['data']['date_of_birth']);
        $this->assertSame('1997-05-23', $preview['rows'][1]['data']['date_of_birth']);
        $this->assertSame(['valid', 'valid', 'invalid', 'invalid'], array_column($preview['rows'], 'status'));
    }

    public function test_formulas_and_numeric_identifiers_are_never_used_as_values(): void
    {
        [$user] = $this->owner();
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('Nguoi thue')->fromArray(array_keys(TenantImportService::HEADERS));
        $book->getActiveSheet()->setCellValue('A2', '=1+1');
        $book->getActiveSheet()->setCellValue('A3', 'Numeric ID');
        $book->getActiveSheet()->setCellValue('C3', 123456789);
        $preview = app(TenantImportService::class)->preview($user, $this->upload($book));
        $this->assertSame(['invalid', 'invalid'], array_column($preview['rows'], 'status'));
        $this->assertStringNotContainsString('=1+1', json_encode($preview));
        $this->assertDatabaseCount('tenants', 0);
    }

    public function test_upload_limits_format_headers_and_empty_file_are_rejected(): void
    {
        [$user] = $this->owner();
        $this->actingAs($user, 'sanctum');
        $this->postJson('/api/tenants/import/preview', ['file' => UploadedFile::fake()->create('large.xlsx', 5121)])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->postJson('/api/tenants/import/preview', ['file' => UploadedFile::fake()->createWithContent('fake.xlsx', 'not an excel file')])->assertUnprocessable();
        $this->postJson('/api/tenants/import/preview', ['file' => UploadedFile::fake()->createWithContent('wrong.csv', 'name,phone')])->assertUnprocessable();
        $this->postJson('/api/tenants/import/preview', ['file' => $this->workbook($this->people(1001))])->assertUnprocessable();
        $this->postJson('/api/tenants/import/preview', ['file' => $this->workbook([])])->assertUnprocessable();
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('Nguoi thue')->setCellValue('A1', 'Wrong header');
        $this->postJson('/api/tenants/import/preview', ['file' => $this->upload($book)])->assertUnprocessable();
        $this->assertDatabaseCount('tenants', 0);
    }

    public function test_single_sheet_workbook_with_vietnamese_headers_is_imported_regardless_of_sheet_name(): void
    {
        [$user] = $this->owner();
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Trang tính');
        $headers = array_keys(TenantImportService::HEADERS);
        $headers[0] = "\xEF\xBB\xBF{$headers[0]} ";
        $sheet->fromArray($headers);
        $sheet->setCellValueExplicit('A2', 'Người thuê thử nghiệm', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('B2', '0900000000', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('C2', '001000000000', DataType::TYPE_STRING);
        $sheet->setCellValue('D2', Date::PHPToExcel(new \DateTimeImmutable('1995-04-23')));
        $sheet->getCell('D2')->getStyle()->getNumberFormat()->setFormatCode('dd/mm/yyyy');

        $preview = $this->actingAs($user, 'sanctum')->postJson('/api/tenants/import/preview', ['file' => $this->upload($book)])
            ->assertOk()
            ->assertJsonPath('data.summary.total', 1)
            ->assertJsonPath('data.summary.valid', 1)
            ->assertJsonPath('data.rows.0.data.phone', '0900000000')
            ->assertJsonPath('data.rows.0.data.identity_number', '001000000000')
            ->assertJsonPath('data.rows.0.data.date_of_birth', '1995-04-23')
            ->json('data');

        $this->postJson('/api/tenants/import/confirm', ['token' => $preview['token'], 'selected_rows' => [2]])
            ->assertOk()
            ->assertJsonPath('data.created', 1);
        $this->assertDatabaseHas('tenants', ['full_name' => 'Người thuê thử nghiệm']);
    }

    public function test_manager_without_owner_workspace_cannot_import_and_tokens_are_user_bound(): void
    {
        [$owner, $landlord] = $this->owner();
        $house = BoardingHouse::create(['landlord_id' => $landlord->id, 'name' => 'Assigned', 'address' => 'Synthetic']);
        $manager = User::factory()->create();
        $house->managers()->attach($manager);
        $preview = app(TenantImportService::class)->preview($owner, $this->workbook($this->people(1)));
        $this->actingAs($manager, 'sanctum')->getJson('/api/tenants/import/template')->assertForbidden();
        $this->postJson('/api/tenants/import/preview', ['file' => $this->workbook($this->people(1))])->assertForbidden();
        $this->postJson('/api/tenants/import/confirm', ['token' => $preview['token'], 'selected_rows' => [2]])->assertForbidden();
        [$other] = $this->owner();
        $this->actingAs($other, 'sanctum')->postJson('/api/tenants/import/confirm', ['token' => $preview['token'], 'selected_rows' => [2]])->assertUnprocessable();
        $this->assertDatabaseCount('tenants', 0);
    }

    public function test_expiry_cancellation_pruning_and_template(): void
    {
        [$user] = $this->owner();
        $this->actingAs($user, 'sanctum')->get('/api/tenants/import/template')->assertOk()->assertDownload('BeeHouse-mau-nguoi-thue.xlsx');
        $preview = app(TenantImportService::class)->preview($user, $this->workbook($this->people(1)));
        $this->travel(61)->minutes();
        $this->postJson('/api/tenants/import/confirm', ['token' => $preview['token'], 'selected_rows' => [2]])->assertUnprocessable();
        $this->artisan('tenant-imports:prune')->assertSuccessful();
        $this->assertDatabaseCount('tenant_imports', 0);
        $preview = app(TenantImportService::class)->preview($user, $this->workbook($this->people(1)));
        $this->postJson('/api/tenants/import/cancel', ['token' => $preview['token']])->assertOk();
        $this->assertDatabaseCount('tenant_imports', 0);
    }

    public function test_template_has_an_empty_data_sheet_and_text_examples_only_in_the_guide(): void
    {
        $book = (new XlsxReader)->load(resource_path('templates/tenant-import.xlsx'));
        $this->assertNull($book->getSheetByName('Nguoi thue')->getCell('A2')->getValue());
        $this->assertSame('0900000001', $book->getSheetByName('Huong dan')->getCell('B15')->getValue());
        $this->assertSame('001000000001', $book->getSheetByName('Huong dan')->getCell('C15')->getValue());
        $this->assertSame('@', $book->getSheetByName('Nguoi thue')->getCell('C2')->getStyle()->getNumberFormat()->getFormatCode());
        $book->disconnectWorksheets();
    }

    public function test_downloaded_template_round_trips_through_preview_and_confirm(): void
    {
        [$user, $owner] = $this->owner();
        $this->actingAs($user, 'sanctum')->get('/api/tenants/import/template')
            ->assertOk()
            ->assertDownload('BeeHouse-mau-nguoi-thue.xlsx');

        $this->postJson('/api/tenants/import/preview', ['file' => $this->templateWorkbook([])])
            ->assertUnprocessable()
            ->assertJsonPath('errors.file.0', 'File không có dữ liệu người thuê. Hãy điền sheet "Nguoi thue".');

        $start = 0;
        foreach ([1, 10, 100] as $count) {
            $preview = $this->postJson('/api/tenants/import/preview', ['file' => $this->templateWorkbook($this->people($count, $start))])
                ->assertOk()
                ->assertJsonPath('data.summary.total', $count)
                ->assertJsonPath('data.summary.valid', $count)
                ->assertJsonPath('data.rows.0.data.phone', '09'.str_pad((string) $start, 8, '0', STR_PAD_LEFT))
                ->assertJsonPath('data.rows.0.data.identity_number', '001'.str_pad((string) $start, 9, '0', STR_PAD_LEFT))
                ->assertJsonPath('data.rows.0.data.date_of_birth', '1995-04-23')
                ->json('data');

            $this->postJson('/api/tenants/import/confirm', ['token' => $preview['token'], 'selected_rows' => range(2, $count + 1)])
                ->assertOk()
                ->assertJsonPath('data.created', $count);
            $start += $count;
        }

        $repeat = $this->postJson('/api/tenants/import/preview', ['file' => $this->templateWorkbook($this->people(1))])
            ->assertOk()
            ->assertJsonPath('data.summary.duplicate', 1)
            ->json('data');
        $this->postJson('/api/tenants/import/confirm', ['token' => $repeat['token'], 'selected_rows' => [2]])
            ->assertOk()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.duplicates', 1);

        $this->assertDatabaseCount('tenants', 111);
        $this->assertSame(111, Tenant::where('landlord_id', $owner->id)->count());
        $this->assertDatabaseCount('contracts', 0);
        $this->assertDatabaseCount('room_tenants', 0);
    }

    public function test_only_name_is_required_and_unselected_rows_are_not_inserted(): void
    {
        [$user] = $this->owner();
        $preview = app(TenantImportService::class)->preview($user, $this->workbook([
            ['Chỉ có họ tên', null, null, null, null, null, null, null, null],
            ['Không chọn', null, null, null, null, null, null, null, null],
        ]));
        $this->assertSame(2, $preview['summary']['valid']);
        $result = app(TenantImportService::class)->confirm($user, $preview['token'], [2], false);
        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['unselected']);
        $this->assertDatabaseMissing('tenants', ['full_name' => 'Không chọn']);
    }

    public function test_macros_and_external_links_are_rejected_and_import_errors_rollback_all_inserts(): void
    {
        [$user] = $this->owner();
        $file = $this->workbook($this->people(1));
        $archive = new \ZipArchive;
        $archive->open($file->getPathname());
        $archive->addFromString('xl/vbaProject.bin', 'macro');
        $archive->close();
        $this->actingAs($user, 'sanctum')->postJson('/api/tenants/import/preview', ['file' => $file])->assertUnprocessable();
        $preview = app(TenantImportService::class)->preview($user, $this->workbook($this->people(110)));
        DB::unprepared("CREATE TRIGGER fail_tenant_import BEFORE INSERT ON tenants WHEN NEW.full_name = 'Người thuê 110' BEGIN SELECT RAISE(ABORT, 'Synthetic failure'); END");
        try {
            $this->postJson('/api/tenants/import/confirm', ['token' => $preview['token'], 'selected_rows' => range(2, 111)])
                ->assertUnprocessable()->assertJsonPath('message', 'Chưa thể nhập dữ liệu. Toàn bộ lượt nhập đã được hoàn tác; vui lòng thử lại.');
            $this->assertDatabaseCount('tenants', 0);
            $this->assertNull(TenantImport::first()->result);
        } finally {
            DB::unprepared('DROP TRIGGER fail_tenant_import');
        }
    }

    private function owner(): array
    {
        $user = User::factory()->create();

        return [$user, Landlord::create(['user_id' => $user->id, 'full_name' => $user->name, 'phone' => '0900000000'])];
    }

    private function people(int $count, int $start = 0): array
    {
        return array_map(fn (int $index): array => ['Người thuê '.($index + 1), '09'.str_pad((string) $index, 8, '0', STR_PAD_LEFT), '001'.str_pad((string) $index, 9, '0', STR_PAD_LEFT), '23/04/1995', 'Nam', 'Địa chỉ thử nghiệm', null, null, null], range($start, $start + $count - 1));
    }

    private function templateWorkbook(array $people): UploadedFile
    {
        $book = (new XlsxReader)->load(resource_path('templates/tenant-import.xlsx'));
        $sheet = $book->getSheetByName('Nguoi thue');
        foreach ($people as $index => $person) {
            foreach ($person as $column => $value) {
                if ($value === null) {
                    continue;
                }
                $cell = $sheet->getCell([$column + 1, $index + 2]);
                if (in_array($column, [1, 2], true)) {
                    $cell->setValueExplicit($value, DataType::TYPE_STRING);

                    continue;
                }
                if (in_array($column, [3, 8], true)) {
                    $cell->setValue(Date::PHPToExcel(\DateTimeImmutable::createFromFormat('!d/m/Y', $value)));

                    continue;
                }
                $cell->setValueExplicit($value, DataType::TYPE_STRING);
            }
        }

        return $this->upload($book);
    }

    private function workbook(array $people): UploadedFile
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Nguoi thue');
        $sheet->fromArray(array_keys(TenantImportService::HEADERS));
        foreach ($people as $index => $person) {
            foreach ($person as $column => $value) {
                if ($value !== null) {
                    $sheet->getCell([$column + 1, $index + 2])->setValueExplicit($value, is_numeric($value) && ! is_string($value) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                }
            }
            $sheet->getCell([4, $index + 2])->getStyle()->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        }

        return $this->upload($book);
    }

    private function upload(Spreadsheet $book): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'beehouse-import-');
        $this->temporaryPaths[] = $path;
        $writer = new Xlsx($book);
        $writer->setPreCalculateFormulas(false);
        $writer->save($path);
        $book->disconnectWorksheets();

        return new UploadedFile($path, 'tenants.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
