<?php

namespace Tests\Feature;

use App\Models\BoardingHouse;
use App\Models\Invoice;
use App\Models\Landlord;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PaymentQrApiTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        Storage::fake('local');
        $user = User::factory()->create();
        Landlord::create(['user_id' => $user->id, 'full_name' => $user->name, 'phone' => '0900000000']);

        return $user;
    }

    private function upload(User $user, array $data = []): TestResponse
    {
        return $this->actingAs($user, 'sanctum')->post('/api/landlord/payment-profile', [
            '_method' => 'PUT', 'bank_name' => 'Vietcombank', 'bank_account_name' => 'Chủ tài khoản',
            'bank_account_number' => '001234567', 'payment_qr' => UploadedFile::fake()->image('user-file.png', 300, 300), ...$data,
        ], ['Accept' => 'application/json']);
    }

    private function invoice(User $owner, string $status = 'DRAFT'): Invoice
    {
        $house = BoardingHouse::create(['landlord_id' => $owner->landlord->id, 'name' => 'Nhà', 'address' => 'Địa chỉ']);
        $room = Room::create(['boarding_house_id' => $house->id, 'room_code' => '101', 'status' => 'AVAILABLE']);

        return Invoice::create(['room_id' => $room->id, 'billing_period' => '2026-10-01', 'status' => $status]);
    }

    public function test_owner_uploads_real_image_and_api_hides_storage_paths(): void
    {
        $owner = $this->owner();
        $response = $this->upload($owner)->assertOk()->assertJsonPath('data.has_payment_qr', true)
            ->assertJsonPath('data.bank_account_number', '001234567')->assertJsonMissingPath('data.payment_qr_path');
        $path = $owner->landlord->paymentProfile()->firstOrFail()->payment_qr_path;

        Storage::disk('local')->assertExists($path);
        $this->assertStringStartsWith('payment-qrs/'.$owner->landlord->id.'/', $path);
        $this->assertStringNotContainsString('user-file', $path);
        $this->assertStringNotContainsString($path, $response->getContent());
        $this->get('/api/landlord/payment-profile/qr')->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_invalid_content_and_oversized_images_are_rejected_without_profile_changes(): void
    {
        $owner = $this->owner();
        $this->upload($owner, ['payment_qr' => UploadedFile::fake()->create('fake.png', 10, 'application/pdf')])
            ->assertUnprocessable()->assertJsonValidationErrors('payment_qr');
        $this->upload($owner, ['payment_qr' => UploadedFile::fake()->image('big.jpg')->size(5121)])
            ->assertUnprocessable()->assertJsonValidationErrors('payment_qr');

        $this->assertDatabaseCount('landlord_payment_profiles', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_replace_uses_new_path_and_remove_preserves_assets_for_historical_bills(): void
    {
        $owner = $this->owner();
        $this->upload($owner)->assertOk();
        $old = $owner->landlord->paymentProfile()->firstOrFail()->payment_qr_path;
        $this->upload($owner)->assertOk();
        $new = $owner->landlord->paymentProfile()->firstOrFail()->payment_qr_path;
        $this->actingAs($owner, 'sanctum')->putJson('/api/landlord/payment-profile', ['remove_payment_qr' => true])
            ->assertOk()->assertJsonPath('data.has_payment_qr', false);

        $this->assertNotSame($old, $new);
        Storage::disk('local')->assertExists([$old, $new]);
        $this->get('/api/landlord/payment-profile/qr')->assertNotFound();
        $this->assertDatabaseHas('landlord_payment_profiles', ['landlord_id' => $owner->landlord->id, 'payment_qr_path' => null, 'bank_account_number' => '001234567']);
    }

    public function test_profiles_and_invoice_qrs_are_owner_scoped_and_manager_cannot_change_settings(): void
    {
        $a = $this->owner();
        $b = $this->owner();
        $this->upload($a, ['landlord_id' => $b->landlord->id])->assertOk();
        $aInvoice = $this->invoice($a, 'UNPAID');
        $this->upload($b, ['bank_name' => 'MB Bank'])->assertOk();
        $bInvoice = $this->invoice($b, 'UNPAID');
        $this->assertStringStartsWith('payment-qrs/'.$a->landlord->id.'/', $aInvoice->payment_snapshot['payment_qr_path']);
        $this->assertStringStartsWith('payment-qrs/'.$b->landlord->id.'/', $bInvoice->payment_snapshot['payment_qr_path']);
        $this->actingAs($a, 'sanctum')->getJson("/api/invoices/{$aInvoice->id}")->assertOk()->assertJsonPath('data.payment_profile.bank_name', 'Vietcombank');
        $this->get("/api/invoices/{$bInvoice->id}/payment-qr")->assertNotFound();
        $this->actingAs($b, 'sanctum')->getJson("/api/invoices/{$bInvoice->id}")->assertOk()->assertJsonPath('data.payment_profile.bank_name', 'MB Bank');
        $this->get("/api/invoices/{$aInvoice->id}/payment-qr")->assertNotFound();
        $aInvoice->room->boardingHouse->managers()->attach($b);
        $this->getJson("/api/invoices/{$aInvoice->id}")->assertOk()->assertJsonPath('data.payment_profile.bank_name', 'Vietcombank');
        $this->get("/api/invoices/{$aInvoice->id}/payment-qr")->assertOk()
            ->assertStreamedContent(Storage::disk('local')->get($aInvoice->payment_snapshot['payment_qr_path']));
        $manager = User::factory()->create();
        $aInvoice->room->boardingHouse->managers()->attach($manager);
        $this->actingAs($manager, 'sanctum')->putJson('/api/landlord/payment-profile', ['remove_payment_qr' => true])->assertForbidden();
        $this->get('/api/landlord/payment-profile/qr')->assertForbidden();
        $this->get("/api/invoices/{$aInvoice->id}/payment-qr")->assertOk();
    }

    public function test_draft_reads_live_profile_and_issued_invoice_keeps_immutable_bank_and_qr(): void
    {
        $owner = $this->owner();
        $this->upload($owner)->assertOk();
        $invoice = $this->invoice($owner);
        $this->assertNull($invoice->payment_snapshot);
        $this->upload($owner, ['bank_name' => 'MB Bank'])->assertOk();
        $this->getJson("/api/invoices/{$invoice->id}")->assertOk()->assertJsonPath('data.payment_profile.bank_name', 'MB Bank');
        $this->get("/api/invoices/{$invoice->id}/payment-qr")->assertOk();

        $invoice->update(['status' => 'UNPAID', 'issued_at' => '2026-10-24']);
        $snapshot = $invoice->fresh()->payment_snapshot;
        $this->upload($owner, ['bank_name' => 'ACB'])->assertOk();
        $newInvoice = $this->invoice($owner, 'UNPAID');
        $this->getJson("/api/invoices/{$newInvoice->id}")->assertOk()->assertJsonPath('data.payment_profile.bank_name', 'ACB');
        $this->assertNotSame($snapshot['payment_qr_path'], $newInvoice->payment_snapshot['payment_qr_path']);
        $this->putJson('/api/landlord/payment-profile', ['remove_payment_qr' => true])->assertOk();
        $this->getJson("/api/invoices/{$invoice->id}")->assertOk()->assertJsonPath('data.payment_profile.bank_name', 'MB Bank')
            ->assertJsonMissingPath('data.payment_snapshot');
        $this->get("/api/invoices/{$invoice->id}/payment-qr")->assertOk()
            ->assertStreamedContent(Storage::disk('local')->get($snapshot['payment_qr_path']));
        $invoice->update(['note' => 'Ghi chú mới']);

        $this->assertSame($snapshot, $invoice->fresh()->payment_snapshot);
    }

    public function test_issued_without_payment_profile_does_not_adopt_bank_details_added_later(): void
    {
        $owner = $this->owner();
        $invoice = $this->invoice($owner, 'UNPAID');
        $this->upload($owner)->assertOk();

        $this->getJson("/api/invoices/{$invoice->id}")->assertOk()->assertJsonPath('data.payment_profile.bank_name', null)
            ->assertJsonPath('data.payment_profile.has_payment_qr', false)
            ->assertJsonPath('data.payment_profile.source', 'missing_snapshot')
            ->assertJsonPath('data.payment_profile.current_profile_configured', true);
        $this->get("/api/invoices/{$invoice->id}/payment-qr")->assertNotFound();
        $this->assertSame($invoice->payment_snapshot, $invoice->fresh()->payment_snapshot);
    }

    public function test_legacy_null_and_empty_snapshots_are_read_only_even_when_invoice_note_is_saved(): void
    {
        $owner = $this->owner();
        $invoice = $this->invoice($owner, 'UNPAID');
        $this->upload($owner)->assertOk();
        foreach ([null, '[]', '{}'] as $snapshot) {
            DB::table('invoices')->where('id', $invoice->id)->update(['payment_snapshot' => $snapshot, 'subtotal' => 120000, 'total_amount' => 120000]);
            $before = DB::table('invoices')->where('id', $invoice->id)->first();

            $this->getJson("/api/invoices/{$invoice->id}")->assertOk()
                ->assertJsonPath('data.payment_profile.source', 'missing_snapshot')
                ->assertJsonPath('data.payment_profile.current_profile_configured', true)
                ->assertJsonPath('data.payment_profile.bank_name', null)
                ->assertJsonPath('data.total_amount', '120000.00');
            $this->get("/api/invoices/{$invoice->id}/payment-qr")->assertNotFound();
            $invoice->fresh()->update(['note' => 'Ghi chú']);

            $after = DB::table('invoices')->where('id', $invoice->id)->first();
            $this->assertSame($before->payment_snapshot, $after->payment_snapshot);
            $this->assertSame($before->total_amount, $after->total_amount);
            $this->assertSame($before->paid_amount, $after->paid_amount);
        }
    }

    public function test_missing_qr_file_preserves_snapshot_bank_details_without_adopting_replacement_qr(): void
    {
        $owner = $this->owner();
        $this->upload($owner)->assertOk();
        $invoice = $this->invoice($owner, 'UNPAID');
        $snapshot = $invoice->payment_snapshot;
        Storage::disk('local')->delete($snapshot['payment_qr_path']);
        $this->upload($owner, ['bank_name' => 'MB Bank'])->assertOk();

        $this->getJson("/api/invoices/{$invoice->id}")->assertOk()
            ->assertJsonPath('data.payment_profile.source', 'snapshot')
            ->assertJsonPath('data.payment_profile.bank_name', 'Vietcombank')
            ->assertJsonPath('data.payment_profile.bank_account_name', 'Chủ tài khoản')
            ->assertJsonPath('data.payment_profile.bank_account_number', '001234567')
            ->assertJsonPath('data.payment_profile.payment_qr_missing', true)
            ->assertJsonPath('data.payment_profile.payment_qr_url', null);
        $this->get("/api/invoices/{$invoice->id}/payment-qr")->assertNotFound();
        $this->assertSame($snapshot, $invoice->fresh()->payment_snapshot);
    }

    public function test_bank_only_profile_can_be_printed_without_qr(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner, 'sanctum')->putJson('/api/landlord/payment-profile', [
            'bank_name' => 'ACB', 'bank_account_name' => 'Chủ tài khoản', 'bank_account_number' => '123456',
        ])->assertOk();
        $invoice = $this->invoice($owner);

        $this->getJson("/api/invoices/{$invoice->id}")->assertOk()
            ->assertJsonPath('data.payment_profile.source', 'current_profile')
            ->assertJsonPath('data.payment_profile.bank_name', 'ACB')
            ->assertJsonPath('data.payment_profile.bank_account_number', '123456')
            ->assertJsonPath('data.payment_profile.payment_qr_url', null)
            ->assertJsonPath('data.payment_profile.payment_qr_missing', false);
    }

    public function test_paid_legacy_invoice_read_does_not_change_snapshot_items_or_payments(): void
    {
        $owner = $this->owner();
        $invoice = $this->invoice($owner, 'PAID');
        $invoice->items()->create(['type' => 'RENT', 'description' => 'Tiền phòng', 'quantity' => 1, 'unit_price' => 120000, 'amount' => 120000, 'source' => 'AUTO']);
        $invoice->payments()->create(['amount' => 120000, 'payment_method' => 'CASH', 'payment_source' => 'MANUAL', 'paid_at' => '2026-10-24 12:00:00']);
        DB::table('invoices')->where('id', $invoice->id)->update(['payment_snapshot' => null, 'subtotal' => 120000, 'total_amount' => 120000, 'paid_amount' => 120000]);
        $this->upload($owner)->assertOk();
        $before = $invoice->fresh()->getAttributes();
        $items = $invoice->items()->get()->toArray();
        $payments = $invoice->payments()->get()->toArray();

        $this->getJson("/api/invoices/{$invoice->id}")->assertOk()
            ->assertJsonPath('data.payment_profile.source', 'missing_snapshot')
            ->assertJsonPath('data.payment_profile.current_profile_configured', true);

        $this->assertSame($before, $invoice->fresh()->getAttributes());
        $this->assertSame($items, $invoice->items()->get()->toArray());
        $this->assertSame($payments, $invoice->payments()->get()->toArray());
    }

    public function test_draft_uses_current_profile_even_if_legacy_snapshot_is_present(): void
    {
        $owner = $this->owner();
        $invoice = $this->invoice($owner);
        DB::table('invoices')->where('id', $invoice->id)->update(['payment_snapshot' => json_encode(['bank_name' => 'Ngân hàng cũ'])]);
        $this->upload($owner)->assertOk();

        $this->getJson("/api/invoices/{$invoice->id}")->assertOk()
            ->assertJsonPath('data.payment_profile.source', 'current_profile')
            ->assertJsonPath('data.payment_profile.bank_name', 'Vietcombank');
        $this->get("/api/invoices/{$invoice->id}/payment-qr")->assertOk();
        $this->assertSame(['bank_name' => 'Ngân hàng cũ'], $invoice->fresh()->payment_snapshot);
    }
}
