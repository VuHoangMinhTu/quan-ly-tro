<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\User;
use App\Services\DormitoryBedBilling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class ContractRenewalApiTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(array $attributes = []): array
    {
        $contract = Contract::factory()->create(array_merge([
            'start_date' => '2026-11-02',
            'end_date' => '2026-12-02',
            'status' => 'ACTIVE',
            'monthly_rent' => 2500000,
        ], $attributes));
        $room = $contract->room;
        $room->update(['room_type' => 'DORMITORY']);
        $bed = $room->beds()->create(['bed_code' => 'G01', 'monthly_rent' => 2500000]);
        $contract->forceFill(['contract_type' => 'DORM_BED', 'bed_id' => $bed->id])->save();
        $owner = $room->boardingHouse->landlord->user;
        return [$contract->fresh(), $owner, $room, $bed];
    }

    private function payload(string $expected = '2026-12-02', string $new = '2027-01-02'): array
    {
        return [
            'expected_end_date' => $expected,
            'new_end_date' => $new,
            'reason' => 'Hai bên đồng ý gia hạn thêm một tháng theo thỏa thuận.',
            'agreement_confirmed' => true,
        ];
    }

    public function test_owner_renews_bed_contract_with_history_and_unblocks_next_cycle(): void
    {
        [$contract, $owner] = $this->fixture();
        $originalSnapshot = $contract->signing_snapshot;
        try {
            app(DormitoryBedBilling::class)->cycle($contract, '2026-12-01');
            $this->fail('Expected expiry guard.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('contract_expired', $e->errors());
        }
        $this->actingAs($owner, 'sanctum')->postJson('/api/contracts/'.$contract->id.'/renewals', $this->payload())
            ->assertOk()->assertJsonPath('data.end_date', '2027-01-02');
        $this->assertDatabaseHas('contracts', ['id' => $contract->id, 'end_date' => '2027-01-02', 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('contract_renewals', ['contract_id' => $contract->id,
            'old_end_date' => '2026-12-02', 'new_end_date' => '2027-01-02', 'extended_by' => $owner->id]);
        $this->assertSame($originalSnapshot, $contract->fresh()->signing_snapshot);
        [$start, $end] = app(DormitoryBedBilling::class)->cycle($contract->fresh(), '2026-12-01');
        $this->assertSame('2026-12-02', $start->toDateString());
        $this->assertSame('2027-01-02', $end->toDateString());
        $this->getJson('/api/contracts/'.$contract->id)->assertOk()
            ->assertJsonPath('data.renewal_history.0.new_end_date', '2027-01-02');
    }

    public function test_renewal_does_not_allow_other_users_or_managers(): void
    {
        [$contract, $owner, $room] = $this->fixture();
        $this->postJson('/api/contracts/'.$contract->id.'/renewals', $this->payload())->assertUnauthorized();
        $outsider = User::factory()->create();
        $this->actingAs($outsider, 'sanctum')->postJson('/api/contracts/'.$contract->id.'/renewals', $this->payload())
            ->assertNotFound();
        $manager = User::factory()->create();
        $room->boardingHouse->managers()->attach($manager);
        $this->actingAs($manager, 'sanctum')->postJson('/api/contracts/'.$contract->id.'/renewals', $this->payload())
            ->assertForbidden();
        $this->assertDatabaseCount('contract_renewals', 0);
    }

    public function test_rejects_stale_or_shorter_end_date_and_duplicate_without_audit(): void
    {
        [$contract, $owner] = $this->fixture();
        $this->actingAs($owner, 'sanctum');
        $uri = '/api/contracts/'.$contract->id.'/renewals';
        $this->postJson($uri, $this->payload('2026-11-02'))->assertUnprocessable()->assertJsonValidationErrors('expected_end_date');
        $this->postJson($uri, $this->payload('2026-12-02', '2026-11-30'))->assertUnprocessable()->assertJsonValidationErrors('new_end_date');
        $this->postJson($uri, $this->payload('2026-12-02', '2026-12-02'))->assertUnprocessable()->assertJsonValidationErrors('new_end_date');
        $this->assertDatabaseCount('contract_renewals', 0);
        $this->assertDatabaseHas('contracts', ['id' => $contract->id, 'end_date' => '2026-12-02']);
    }

    public function test_rejects_without_tenant_agreement_or_reason(): void
    {
        [$contract, $owner] = $this->fixture();
        $this->actingAs($owner, 'sanctum');
        $this->postJson('/api/contracts/'.$contract->id.'/renewals', array_merge($this->payload(), ['agreement_confirmed' => false]))
            ->assertUnprocessable()->assertJsonValidationErrors('agreement_confirmed');
        $this->postJson('/api/contracts/'.$contract->id.'/renewals', array_merge($this->payload(), ['reason' => 'ok']))
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
    }

    public function test_other_contract_on_same_bed_blocks_overlap_but_other_bed_does_not(): void
    {
        [$contract, $owner, $room, $bed] = $this->fixture();
        $other = Contract::factory()->create([
            'room_id' => $room->id, 'contract_code' => 'HD-SECOND-BED',
            'start_date' => '2026-12-15', 'end_date' => '2027-01-15', 'status' => 'ACTIVE',
        ]);
        $other->forceFill(['contract_type' => 'DORM_BED', 'bed_id' => $bed->id])->save();
        $this->actingAs($owner, 'sanctum')->postJson('/api/contracts/'.$contract->id.'/renewals', $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('new_end_date');
        $differentBed = $room->beds()->create(['bed_code' => 'G02']);
        $other->bed_id = $differentBed->id;
        $other->save();
        $this->postJson('/api/contracts/'.$contract->id.'/renewals', $this->payload())
            ->assertOk();
    }

    public function test_terminated_contract_cannot_be_renewed(): void
    {
        [$contract, $owner] = $this->fixture();
        $contract->update(['status' => 'TERMINATED']);
        $this->actingAs($owner, 'sanctum')->postJson('/api/contracts/'.$contract->id.'/renewals', $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertDatabaseCount('contract_renewals', 0);
    }

    public function test_expired_without_checkout_can_be_renewed(): void
    {
        [$contract, $owner] = $this->fixture(['status' => 'EXPIRED']);
        $this->actingAs($owner, 'sanctum')->postJson('/api/contracts/'.$contract->id.'/renewals', $this->payload())
            ->assertOk()->assertJsonPath('data.status', 'ACTIVE');
        $this->assertDatabaseHas('contract_renewals', ['contract_id' => $contract->id, 'status_before' => 'EXPIRED']);
    }
}
