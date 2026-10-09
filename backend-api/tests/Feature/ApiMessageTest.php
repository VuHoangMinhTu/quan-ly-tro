<?php

namespace Tests\Feature;

use App\Exceptions\PayOSReconciliationException;
use App\Http\Middleware\SetApiLocale;
use App\Models\BoardingHouse;
use App\Models\Contract;
use App\Models\Landlord;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class ApiMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_contract_has_the_same_concrete_message_at_both_levels(): void
    {
        [$room, $tenant] = $this->ownedRoomAndTenant();
        Contract::create($this->contractPayload($tenant) + ['room_id' => $room->id]);

        $this->postJson("/api/rooms/{$room->id}/contracts", $this->contractPayload($tenant))
            ->assertUnprocessable()
            ->assertExactJson([
                'success' => false,
                'message' => 'Mã hợp đồng này đã được sử dụng. Vui lòng chọn mã khác.',
                'errors' => ['contract_code' => ['Mã hợp đồng này đã được sử dụng. Vui lòng chọn mã khác.']],
            ]);
    }

    public function test_duplicate_room_code_is_localized_without_changing_the_scope(): void
    {
        [$room] = $this->ownedRoomAndTenant();
        $this->postJson("/api/boarding-houses/{$room->boarding_house_id}/rooms", [
            'room_code' => $room->room_code, 'monthly_rent' => 2000000, 'status' => 'AVAILABLE',
        ])->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Mã phòng này đã tồn tại trong nhà trọ.')
            ->assertJsonPath('errors.room_code.0', 'Mã phòng này đã tồn tại trong nhà trọ.');
    }

    public function test_invalid_tenant_and_required_fields_use_vietnamese_attributes(): void
    {
        [$room, $tenant] = $this->ownedRoomAndTenant();
        $this->postJson("/api/rooms/{$room->id}/contracts", [
            ...$this->contractPayload($tenant), 'tenant_id' => 999999,
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Người thuê đã chọn không hợp lệ.')
            ->assertJsonPath('errors.tenant_id.0', 'Người thuê đã chọn không hợp lệ.');

        $this->postJson("/api/rooms/{$room->id}/contracts", collect($this->contractPayload($tenant))->except('start_date')->all())
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Vui lòng nhập ngày bắt đầu.')
            ->assertJsonPath('errors.start_date.0', 'Vui lòng nhập ngày bắt đầu.');
    }

    public function test_foreign_and_missing_rooms_have_the_same_safe_not_found_message(): void
    {
        [$room] = $this->ownedRoomAndTenant();
        $otherUser = User::factory()->create();
        Landlord::create(['user_id' => $otherUser->id, 'full_name' => 'Chủ trọ khác', 'phone' => '0901234567']);
        $this->withToken($otherUser->createToken('other')->plainTextToken);

        foreach ([$room->id, 999999] as $id) {
            $this->getJson("/api/rooms/{$id}")->assertNotFound()
                ->assertExactJson(['success' => false, 'message' => 'Không tìm thấy phòng.', 'errors' => null]);
        }
    }

    public function test_business_conflict_keeps_the_specific_payos_message(): void
    {
        Route::get('/api/_message-test/conflict', fn () => throw new PayOSReconciliationException(
            'Yêu cầu thanh toán payOS hiện tại đang được đối soát. Vui lòng thử lại sau.', 409,
        ));

        $this->getJson('/api/_message-test/conflict')->assertConflict()
            ->assertExactJson([
                'success' => false,
                'message' => 'Yêu cầu thanh toán payOS hiện tại đang được đối soát. Vui lòng thử lại sau.',
                'errors' => null,
            ]);
    }

    public function test_http_exception_status_and_headers_are_preserved_without_internal_text(): void
    {
        Route::get('/api/_message-test/http/{status}', fn (int $status) => abort($status, 'Internal diagnostic', ['Retry-After' => '60']));
        foreach ([400, 405, 419, 429, 503] as $status) {
            $this->getJson("/api/_message-test/http/{$status}")
                ->assertStatus($status)->assertHeader('Retry-After', '60')
                ->assertJsonPath('success', false)->assertJsonPath('errors', null)
                ->assertDontSee('Internal diagnostic');
        }
    }

    public function test_server_details_are_logged_but_never_exposed_even_with_debug_enabled(): void
    {
        config(['app.debug' => true]);
        Log::spy();
        $detail = 'SQLSTATE secret DB_PASSWORD=hidden /var/www/internal.php';
        Route::get('/api/_message-test/server', fn () => throw new RuntimeException($detail));
        Route::get('/api/_message-test/sql', fn () => throw new QueryException('sqlite', 'select secret', [], new RuntimeException($detail)));

        foreach (['server', 'sql'] as $type) {
            $this->getJson("/api/_message-test/{$type}")->assertServerError()
                ->assertExactJson(['success' => false, 'message' => 'Đã xảy ra lỗi hệ thống. Vui lòng thử lại sau.', 'errors' => null])
                ->assertDontSee('SQLSTATE')->assertDontSee('hidden');
        }
        Log::shouldHaveReceived('error')->with($detail, \Mockery::on(fn (array $context): bool => $context['exception'] instanceof RuntimeException))->once();
    }

    public function test_empty_validation_errors_have_a_vietnamese_fallback(): void
    {
        Route::middleware(SetApiLocale::class)->get('/api/_message-test/empty-validation', fn () => throw ValidationException::withMessages([]));
        $this->getJson('/api/_message-test/empty-validation')->assertUnprocessable()
            ->assertExactJson(['success' => false, 'message' => 'Dữ liệu không hợp lệ.', 'errors' => []]);
    }

    public function test_http_server_exceptions_are_also_logged_and_masked(): void
    {
        config(['app.debug' => true]);
        Log::spy();
        Route::get('/api/_message-test/http-server', fn () => abort(500, 'Internal HTTP failure'));

        $this->getJson('/api/_message-test/http-server')->assertServerError()
            ->assertExactJson(['success' => false, 'message' => 'Đã xảy ra lỗi hệ thống. Vui lòng thử lại sau.', 'errors' => null]);
        Log::shouldHaveReceived('error')->with('Internal HTTP failure', \Mockery::on(fn (array $context): bool => isset($context['exception'])))->once();
    }

    /** @return array{Room, Tenant} */
    private function ownedRoomAndTenant(): array
    {
        $user = User::factory()->create();
        $landlord = Landlord::create(['user_id' => $user->id, 'full_name' => $user->name, 'phone' => '0901234567']);
        $house = BoardingHouse::create(['landlord_id' => $landlord->id, 'name' => 'Nhà trọ', 'address' => 'Địa chỉ']);
        $room = Room::create(['boarding_house_id' => $house->id, 'room_code' => 'P001', 'monthly_rent' => 2000000, 'status' => 'AVAILABLE']);
        $tenant = Tenant::create(['landlord_id' => $landlord->id, 'full_name' => 'Người thuê']);
        $this->withToken($user->createToken('test')->plainTextToken);

        return [$room, $tenant];
    }

    /** @return array<string, mixed> */
    private function contractPayload(Tenant $tenant): array
    {
        return [
            'tenant_id' => $tenant->id, 'contract_code' => 'HD-001', 'start_date' => '2026-10-01',
            'end_date' => '2026-10-31', 'monthly_rent' => 2000000, 'deposit_amount' => 1000000, 'status' => 'DRAFT',
        ];
    }
}
