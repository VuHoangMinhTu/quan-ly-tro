<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractDocument;
use App\Models\ContractSignature;
use App\Models\ContractSignatureEvent;
use App\Models\ContractSigningRequest;
use App\Models\User;
use App\Notifications\ContractSigningNotification;
use App\Services\ContractSigningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ContractSigningApiTest extends TestCase
{
    use RefreshDatabase;

    private string $proof = '0000000000000000000000000000000000000000000000000000000000000000';

    private function fixture(?User $representative = null): array
    {
        $this->freezeTime();
        Notification::fake();
        Storage::fake('contract_signatures');
        config(['signing.mailer' => 'array']);
        $contract = Contract::factory()->create();
        $owner = $contract->room->boardingHouse->landlord->user;
        $contract->tenant->update(['email' => 'tenant@example.test', 'full_name' => 'Nguyễn Văn A']);
        if ($representative) {
            $contract->room->boardingHouse->managers()->attach($representative);
        }
        $this->actingAs($owner, 'sanctum');
        $this->putJson("/api/contracts/{$contract->id}", ['lessor_representative_user_id' => ($representative ?? $owner)->id])->assertOk();
        $this->postJson("/api/contracts/{$contract->id}/finalize")->assertOk();

        return [$contract->fresh(), $owner, $contract->document()->firstOrFail()];
    }

    private function start(Contract $contract): string
    {
        $this->postJson("/api/contracts/{$contract->id}/signing-requests")->assertOk()->assertJsonPath('data.action', 'sent')->assertJsonPath('data.mail_submitted', true);
        $token = '';
        Notification::assertSentOnDemand(ContractSigningNotification::class, function ($notification) use (&$token): bool {
            if ($notification->kind === 'REQUEST') {
                $token = basename($notification->details['url']);

                return true;
            }

            return false;
        });

        return $token;
    }

    public function test_resend_uses_updated_tenant_email_in_actual_mail_envelope(): void
    {
        [$contract] = $this->fixture();
        $oldToken = $this->start($contract);
        $this->putJson("/api/tenants/{$contract->tenant_id}", ['full_name' => $contract->tenant->full_name, 'email' => 'updated@example.test'])->assertOk();
        Notification::swap(new ChannelManager($this->app));
        $transport = Mail::mailer('array')->getSymfonyTransport();
        $transport->flush();

        $this->postJson("/api/contracts/{$contract->id}/signing-requests/resend", ['expected_tenant_email' => 'updated@example.test'])
            ->assertOk()->assertJsonPath('data.recipient_email', 'updated@example.test')
            ->assertJsonPath('data.tenant_id', $contract->tenant_id)->assertJsonPath('data.mail_submitted', true);
        $this->assertCount(1, $transport->messages());
        $mail = $transport->messages()->last();
        $this->assertSame(['updated@example.test'], array_map(fn ($address) => $address->getAddress(), $mail->getOriginalMessage()->getTo()));
        $this->assertSame(['updated@example.test'], array_map(fn ($address) => $address->getAddress(), $mail->getEnvelope()->getRecipients()));
        $this->getJson('/api/sign/'.$oldToken)->assertUnprocessable();

        preg_match('~/sign/([a-f0-9]{64})~', $mail->getOriginalMessage()->getHtmlBody(), $matches);
        $this->assertNotEmpty($matches[1] ?? null);
        $this->postJson('/api/sign/'.$matches[1].'/request-otp')->assertOk();
        $this->assertCount(2, $transport->messages());
        $this->assertSame('updated@example.test', $transport->messages()->last()->getEnvelope()->getRecipients()[0]->getAddress());
        $this->getJson("/api/contracts/{$contract->id}/signing")->assertOk()
            ->assertJsonPath('data.tenant_contact.email', 'updated@example.test');
    }

    public function test_stale_recipient_confirmation_does_not_send_or_revoke_request(): void
    {
        [$contract] = $this->fixture();
        $this->start($contract);
        $this->postJson("/api/contracts/{$contract->id}/signing-requests/resend", ['expected_tenant_email' => 'old@example.test'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('contract_signing_requests', 2);
        Notification::assertSentOnDemandTimes(ContractSigningNotification::class, 1);
        $this->postJson("/api/contracts/{$contract->id}/signing-requests")->assertOk()
            ->assertJsonPath('data.action', 'reused')->assertJsonPath('data.mail_submitted', false);
        Notification::assertSentOnDemandTimes(ContractSigningNotification::class, 1);
    }

    private function verify(string $prefix): string
    {
        $this->postJson($prefix.'/request-otp')->assertOk();
        $code = '';
        Notification::assertSentOnDemand(ContractSigningNotification::class, function ($notification) use (&$code): bool {
            if ($notification->kind === 'OTP') {
                $code = $notification->details['otp'];

                return true;
            }

            return false;
        });
        $this->proof = $this->postJson($prefix.'/verify-otp', ['otp' => $code])->assertOk()->json('data.verification_token');

        return $code;
    }

    private function signature(bool $blank = false): UploadedFile
    {
        $image = imagecreatetruecolor(400, 160);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        if (! $blank) {
            imageline($image, 20, 130, 330, 25, imagecolorallocate($image, 20, 20, 50));
        }
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return UploadedFile::fake()->createWithContent('signature.png', $bytes);
    }

    private function payload(string $hash): array
    {
        return ['signature' => $this->signature(), 'consent' => '1', 'consent_version' => ContractSigningService::CONSENT_VERSION, 'content_hash' => $hash, 'verification_token' => $this->proof];
    }

    public function test_workflow_creates_two_frozen_signers_and_stores_only_token_hash(): void
    {
        [$contract, , $document] = $this->fixture();
        $token = $this->start($contract);
        $request = ContractSigningRequest::where('signer_role', 'TENANT')->firstOrFail();

        $this->assertSame(64, strlen($token));
        $this->assertSame(hash('sha256', $token), $request->token_hash);
        $this->assertStringNotContainsString($token, json_encode($request->getAttributes()));
        $this->assertDatabaseCount('contract_signing_requests', 2);
        $this->getJson('/api/sign/'.$token)->assertOk()->assertJsonPath('data.signer_name', 'Nguyễn Văn A')
            ->assertJsonPath('data.masked_email', 't***@example.test')->assertJsonMissingPath('data.source_snapshot')
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $this->assertDatabaseHas('contract_signature_events', ['contract_document_id' => $document->id, 'event' => 'DOCUMENT_FINALIZED']);
        $this->postJson("/api/contracts/{$contract->id}/signing-requests")->assertOk();
        $this->assertDatabaseCount('contract_signing_requests', 2);
    }

    #[TestWith(['invalid'])]
    #[TestWith(['expired'])]
    #[TestWith(['revoked'])]
    public function test_invalid_expired_and_revoked_links_do_not_disclose_data(string $kind): void
    {
        [$contract] = $this->fixture();
        $token = $this->start($contract);
        if ($kind === 'invalid') {
            $token = str_repeat('a', 64);
        } elseif ($kind === 'expired') {
            $this->travel(8)->days();
        } else {
            ContractSigningRequest::where('signer_role', 'TENANT')->update(['status' => 'REVOKED', 'revoked_at' => now()]);
        }
        $this->getJson('/api/sign/'.$token)->assertUnprocessable()->assertJsonPath('message', ContractSigningService::INVALID_LINK)
            ->assertJsonMissingPath('data')->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_otp_hash_cooldown_wrong_attempts_and_lock_are_enforced(): void
    {
        [$contract] = $this->fixture();
        $token = $this->start($contract);
        $prefix = '/api/sign/'.$token;
        $this->postJson($prefix.'/request-otp')->assertOk();
        $request = ContractSigningRequest::where('signer_role', 'TENANT')->firstOrFail();
        Notification::assertSentOnDemand(ContractSigningNotification::class, fn ($mail) => $mail->kind === 'OTP' && Hash::check($mail->details['otp'], $request->otp_hash));
        $this->postJson($prefix.'/request-otp')->assertUnprocessable();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson($prefix.'/verify-otp', ['otp' => '000000'])->assertUnprocessable();
        }
        $this->assertSame(5, $request->fresh()->otp_attempts);
        $this->travel(61)->seconds();
        $this->postJson($prefix.'/request-otp')->assertUnprocessable();
        $this->assertDatabaseCount('contract_signatures', 0);
    }

    public function test_expired_otp_cannot_be_verified(): void
    {
        [$contract] = $this->fixture();
        $token = $this->start($contract);
        $prefix = '/api/sign/'.$token;
        $this->postJson($prefix.'/request-otp')->assertOk();
        $this->travel(6)->minutes();

        $this->postJson($prefix.'/verify-otp', ['otp' => '123456'])->assertUnprocessable()->assertJsonValidationErrors('otp');
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_both_signing_orders_complete_once_and_bind_exact_frozen_hash(bool $tenantFirst): void
    {
        [$contract, , $document] = $this->fixture();
        $token = $this->start($contract);
        $public = '/api/sign/'.$token;
        $lessor = "/api/contracts/{$contract->id}/sign/lessor";
        $first = $tenantFirst ? $public : $lessor;
        $second = $tenantFirst ? $lessor : $public;
        $this->verify($first);
        $this->post($first, $this->payload($document->document_hash), ['Accept' => 'application/json'])->assertOk();
        $this->getJson("/api/contracts/{$contract->id}/signing")->assertOk()->assertJsonPath('data.status', 'PARTIALLY_SIGNED');
        $this->verify($second);
        $this->post($second, $this->payload($document->document_hash), ['Accept' => 'application/json'])->assertOk();
        $this->post($second, $this->payload($document->document_hash), ['Accept' => 'application/json'])->assertOk();

        $this->assertDatabaseCount('contract_signatures', 2);
        $this->assertSame(1, ContractSignatureEvent::where('event', 'SIGNING_COMPLETED')->count());
        $workflow = $document->signing()->firstOrFail();
        $this->assertSame('COMPLETED', $workflow->status);
        $this->assertSame(hash('sha256', $workflow->signed_rendered_html), $workflow->signed_document_hash);
        $this->assertStringStartsWith($document->rendered_html, $workflow->signed_rendered_html);
        $this->assertStringContainsString('Nguyễn Văn A', $workflow->signed_rendered_html);
        foreach (ContractSignature::all() as $signature) {
            $this->assertSame($document->document_hash, $signature->document_hash);
            Storage::disk('contract_signatures')->assertExists($signature->signature_file_path);
            $this->assertSame(ContractSigningService::CONSENT_TEXT, $signature->consent_text);
            $this->getJson("/api/contracts/{$contract->id}/signatures/{$signature->id}/image")->assertOk()->assertHeader('Content-Type', 'image/png');
        }
        $this->assertSame('ACTIVE', $contract->fresh()->status);
        $this->assertDatabaseHas('contract_signature_events', ['event' => 'SIGNED', 'actor_type' => 'TENANT', 'actor_user_id' => null, 'actor_tenant_id' => $contract->tenant_id]);
        $this->getJson("/api/contracts/{$contract->id}/document/signed")->assertOk()->assertJsonPath('data.status', 'COMPLETED');
    }

    public function test_signature_requires_verified_otp_consent_image_and_matching_document(): void
    {
        [$contract, , $document] = $this->fixture();
        $prefix = '/api/sign/'.$this->start($contract);
        $this->post($prefix, $this->payload($document->document_hash), ['Accept' => 'application/json'])->assertUnprocessable();
        $this->verify($prefix);
        $this->post($prefix, [...$this->payload($document->document_hash), 'consent' => '0'], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->postJson($prefix, ['consent' => true, 'consent_version' => ContractSigningService::CONSENT_VERSION, 'content_hash' => $document->document_hash])->assertUnprocessable();
        $this->post($prefix, $this->payload(str_repeat('a', 64)), ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseCount('contract_signatures', 0);
        $this->assertSame([], Storage::disk('contract_signatures')->allFiles());
    }

    public function test_hash_tampering_rejects_even_with_verified_otp(): void
    {
        [$contract, , $document] = $this->fixture();
        $prefix = '/api/sign/'.$this->start($contract);
        $this->verify($prefix);
        DB::table('contract_documents')->where('id', $document->id)->update(['rendered_html' => '<p>tampered</p>']);
        $this->post($prefix, $this->payload($document->document_hash), ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseCount('contract_signatures', 0);
    }

    public function test_resend_revokes_old_token_and_keeps_single_active_request(): void
    {
        [$contract] = $this->fixture();
        $old = $this->start($contract);
        $this->travel(61)->seconds();
        $this->postJson("/api/contracts/{$contract->id}/signing-requests/resend")->assertOk();
        $this->getJson('/api/sign/'.$old)->assertUnprocessable();
        $this->assertSame(1, ContractSigningRequest::where('signer_role', 'TENANT')->whereNotNull('active_key')->count());
        $this->assertDatabaseHas('contract_signature_events', ['event' => 'REQUEST_REVOKED']);
    }

    public function test_void_preserves_evidence_and_rejects_old_link(): void
    {
        [$contract, , $document] = $this->fixture();
        $prefix = '/api/sign/'.$this->start($contract);
        $this->verify($prefix);
        $this->post($prefix, $this->payload($document->document_hash), ['Accept' => 'application/json'])->assertOk();
        $signature = ContractSignature::firstOrFail();

        $this->postJson("/api/contracts/{$contract->id}/signing/void", ['reason' => 'Sai nội dung'])->assertOk();
        $this->getJson($prefix)->assertUnprocessable();
        $this->post($prefix, $this->payload($document->document_hash), ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseCount('contract_signatures', 1);
        Storage::disk('contract_signatures')->assertExists($signature->signature_file_path);
        $this->assertSame('ACTIVE', $contract->fresh()->status);
    }

    public function test_assigned_manager_can_manage_but_only_frozen_representative_can_sign_and_owner_can_void(): void
    {
        $manager = User::factory()->create();
        [$contract, $owner] = $this->fixture($manager);
        $this->start($contract);
        $this->getJson("/api/contracts/{$contract->id}/sign/lessor")->assertForbidden();
        $this->actingAs($manager, 'sanctum');
        $this->getJson("/api/contracts/{$contract->id}/sign/lessor")->assertOk();
        $this->postJson("/api/contracts/{$contract->id}/signing/void", ['reason' => 'Không được'])->assertForbidden();
        $other = Contract::factory()->create();
        $this->postJson("/api/contracts/{$other->id}/signing-requests")->assertNotFound();
        $contract->room->boardingHouse->managers()->detach($manager);
        $this->getJson("/api/contracts/{$contract->id}/sign/lessor")->assertNotFound();
        $this->actingAs($owner, 'sanctum');
        $this->postJson("/api/contracts/{$contract->id}/sign/lessor/request-otp")->assertForbidden();
    }

    public function test_draft_legacy_missing_email_and_log_mailer_cannot_start_signing(): void
    {
        [$contract] = $this->fixture();
        config(['signing.mailer' => 'log']);
        $this->postJson("/api/contracts/{$contract->id}/signing-requests")->assertUnprocessable();
        $this->assertDatabaseCount('contract_signing_requests', 0);
        Notification::assertNothingSent();
        $draft = Contract::factory()->create(['room_id' => $contract->room_id]);
        $this->postJson("/api/contracts/{$draft->id}/signing-requests")->assertNotFound();
    }

    public function test_signature_insert_failure_cleans_up_file_and_rolls_back(): void
    {
        [$contract, , $document] = $this->fixture();
        $prefix = '/api/sign/'.$this->start($contract);
        $this->verify($prefix);
        Event::listen('eloquent.creating: '.ContractSignature::class, fn () => throw new \RuntimeException('simulated persistence failure'));
        try {
            $this->post($prefix, $this->payload($document->document_hash), ['Accept' => 'application/json'])->assertServerError();
        } finally {
            Event::forget('eloquent.creating: '.ContractSignature::class);
        }
        $this->assertSame([], Storage::disk('contract_signatures')->allFiles());
        $this->assertDatabaseCount('contract_signatures', 0);
        $this->assertDatabaseMissing('contract_signature_events', ['event' => 'SIGNED']);
    }

    public function test_authenticated_signing_management_requires_login_and_foreign_owner_is_hidden(): void
    {
        $this->getJson('/api/contracts/1/signing')->assertUnauthorized();
        $this->postJson('/api/contracts/1/signing-requests')->assertUnauthorized();
        [$contract, , $document] = $this->fixture();
        $this->start($contract);
        $foreign = User::factory()->create();
        $this->actingAs($foreign, 'sanctum');
        $this->getJson("/api/contracts/{$contract->id}/signing")->assertNotFound();
        $this->postJson("/api/contracts/{$contract->id}/signing-requests/resend")->assertNotFound();
        $this->postJson("/api/contracts/{$contract->id}/signing/void", ['reason' => 'Sai quyền'])->assertNotFound();
        $this->assertSame('PENDING', $document->signing()->firstOrFail()->status);
    }

    public function test_decline_requires_otp_and_keeps_existing_signature_history(): void
    {
        [$contract, , $document] = $this->fixture();
        $prefix = '/api/sign/'.$this->start($contract);
        $this->postJson($prefix.'/decline', ['reason' => 'Cần xem lại'])->assertUnprocessable();
        $lessor = "/api/contracts/{$contract->id}/sign/lessor";
        $this->verify($lessor);
        $this->post($lessor, $this->payload($document->document_hash), ['Accept' => 'application/json'])->assertOk();
        $this->verify($prefix);
        $this->withHeaders(['X-Signing-Verification' => $this->proof])->postJson($prefix.'/decline', ['reason' => 'Cần xem lại'])->assertOk();

        $this->assertDatabaseHas('contract_signature_events', ['event' => 'DECLINED']);
        $this->assertDatabaseCount('contract_signatures', 1);
        $this->getJson("/api/contracts/{$contract->id}/signing")->assertOk()->assertJsonPath('data.status', 'DECLINED');
        $this->getJson($prefix)->assertUnprocessable();
    }

    public function test_expired_verified_otp_cannot_sign_and_resend_invalidates_verification(): void
    {
        [$contract, , $document] = $this->fixture();
        $prefix = '/api/sign/'.$this->start($contract);
        $this->verify($prefix);
        $this->travel(6)->minutes();
        $this->post($prefix, $this->payload($document->document_hash), ['Accept' => 'application/json'])->assertUnprocessable();
        $this->postJson($prefix.'/request-otp')->assertOk();
        $this->post($prefix, $this->payload($document->document_hash), ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseCount('contract_signatures', 0);
    }

    public function test_blank_or_disguised_signature_is_rejected_without_file(): void
    {
        [$contract, , $document] = $this->fixture();
        $prefix = '/api/sign/'.$this->start($contract);
        $this->verify($prefix);
        $this->post($prefix, [...$this->payload($document->document_hash), 'signature' => $this->signature(true)], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post($prefix, [...$this->payload($document->document_hash), 'signature' => UploadedFile::fake()->createWithContent('signature.png', '<svg onload="alert(1)"></svg>')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseCount('contract_signatures', 0);
        $this->assertSame([], Storage::disk('contract_signatures')->allFiles());
    }

    public function test_delivery_uses_current_email_without_rewriting_the_frozen_document(): void
    {
        [$contract, , $document] = $this->fixture();
        $snapshot = $document->source_snapshot;
        $snapshot['tenant']['email'] = null;
        DB::table('contract_documents')->where('id', $document->id)->update(['source_snapshot' => json_encode($snapshot)]);
        $this->putJson("/api/tenants/{$contract->tenant_id}", ['full_name' => 'Nguyễn Văn A', 'email' => 'new@example.test'])->assertOk();
        $this->start($contract);
        $this->assertDatabaseHas('contract_signing_requests', ['signer_role' => 'TENANT', 'delivery_destination_snapshot' => 'new@example.test']);
        Notification::assertSentOnDemand(ContractSigningNotification::class, fn ($mail, $channels, $recipient) => $mail->kind === 'REQUEST' && $recipient->routes['mail'] === 'new@example.test');
        $this->assertSame($snapshot, $document->fresh()->source_snapshot);
        $this->assertSame($document->rendered_html, $document->fresh()->rendered_html);
        $this->assertSame($document->document_hash, $document->fresh()->document_hash);
    }

    public function test_partial_signature_keeps_exact_content_and_append_only_evidence(): void
    {
        [$contract, , $document] = $this->fixture();
        $prefix = '/api/sign/'.$this->start($contract);
        $this->verify($prefix);
        $this->post($prefix, $this->payload($document->document_hash), ['Accept' => 'application/json'])->assertOk();
        $contract->tenant->update(['phone' => '0999999999']);
        $this->assertSame($document->rendered_html, $document->fresh()->rendered_html);
        foreach ([$document, ContractSignature::firstOrFail(), ContractSignatureEvent::firstOrFail()] as $evidence) {
            try {
                $evidence->update($evidence instanceof ContractDocument ? ['rendered_html' => 'changed'] : ($evidence instanceof ContractSignature ? ['signer_name_snapshot' => 'changed'] : ['event' => 'changed']));
                $this->fail('Evidence must reject mutation.');
            } catch (\LogicException) {
                $this->assertModelExists($evidence);
            }
        }
    }

    public function test_lessor_request_can_be_renewed_after_tenant_signed_without_replacing_their_evidence(): void
    {
        [$contract, , $document] = $this->fixture();
        $prefix = '/api/sign/'.$this->start($contract);
        $this->verify($prefix);
        $this->post($prefix, $this->payload($document->document_hash), ['Accept' => 'application/json'])->assertOk();
        $tenant = ContractSignature::firstOrFail();
        $this->travel(8)->days();
        $this->postJson("/api/contracts/{$contract->id}/signing-requests/resend")->assertOk();
        $this->assertSame(1, ContractSigningRequest::where('signer_role', 'LESSOR')->whereNotNull('active_key')->count());
        $this->assertModelExists($tenant);
        $this->assertDatabaseCount('contract_signatures', 1);
        $this->getJson("/api/contracts/{$contract->id}/sign/lessor")->assertOk();
    }

    public function test_private_signature_cannot_be_read_through_another_document(): void
    {
        [$contract, , $document] = $this->fixture();
        $prefix = '/api/sign/'.$this->start($contract);
        $this->verify($prefix);
        $this->post($prefix, $this->payload($document->document_hash), ['Accept' => 'application/json'])->assertOk();
        $foreign = ContractSignature::factory()->create();
        $this->getJson($prefix.'/signatures/'.$foreign->id)->assertNotFound();
        $this->getJson("/api/contracts/{$contract->id}/signatures/{$foreign->id}/image")->assertNotFound();
        $this->assertDatabaseCount('contract_signatures', 2);
    }

    public function test_untrusted_forwarded_headers_cannot_spoof_signing_audit_ip(): void
    {
        [$contract, , $document] = $this->fixture();
        $prefix = '/api/sign/'.$this->start($contract);
        $this->withHeaders(['X-Forwarded-For' => '203.0.113.42', 'User-Agent' => 'BeeHouse QA'])->getJson($prefix)->assertOk();
        $event = ContractSignatureEvent::where('event', 'DOCUMENT_VIEWED')->latest('id')->firstOrFail();
        $this->assertSame('127.0.0.1', $event->ip_address);
        $this->assertSame('BeeHouse QA', $event->user_agent);
    }

    public function test_verification_receipt_is_bound_to_the_browser_flow_and_only_hashed_in_database(): void
    {
        [$contract, , $document] = $this->fixture();
        $prefix = '/api/sign/'.$this->start($contract);
        $this->verify($prefix);
        $request = ContractSigningRequest::where('signer_role', 'TENANT')->firstOrFail();
        $this->assertSame(hash('sha256', $this->proof), $request->verification_hash);
        $this->assertStringNotContainsString($this->proof, json_encode($request->getAttributes()));
        $this->getJson($prefix)->assertOk()->assertJsonPath('data.otp_verified', false);
        $this->getJson($prefix, ['X-Signing-Verification' => $this->proof])->assertOk()->assertJsonPath('data.otp_verified', true);
        $this->post($prefix, [...$this->payload($document->document_hash), 'verification_token' => str_repeat('a', 64)], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseCount('contract_signatures', 0);
    }

    public function test_otp_send_is_rate_limited_even_before_cooldown_expires(): void
    {
        [$contract] = $this->fixture();
        $prefix = '/api/sign/'.$this->start($contract);
        $this->postJson($prefix.'/request-otp')->assertOk();
        for ($i = 0; $i < 3; $i++) {
            $this->postJson($prefix.'/request-otp')->assertUnprocessable();
        }
        $this->postJson($prefix.'/request-otp')->assertTooManyRequests()->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_unverified_representative_is_not_silently_substituted(): void
    {
        $manager = User::factory()->create();
        [$contract, $owner] = $this->fixture($manager);
        $this->start($contract);
        $manager->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($manager, 'sanctum');
        $this->postJson("/api/contracts/{$contract->id}/sign/lessor/request-otp")->assertUnprocessable();
        $this->actingAs($owner, 'sanctum');
        $this->postJson("/api/contracts/{$contract->id}/sign/lessor/request-otp")->assertForbidden();
        $this->assertDatabaseCount('contract_signatures', 0);
    }

    public function test_mail_failure_rolls_back_requests_without_exposing_transport_exception(): void
    {
        [$contract] = $this->fixture();
        Notification::shouldReceive('route')->andThrow(new \RuntimeException('private transport diagnostic'));
        $this->postJson("/api/contracts/{$contract->id}/signing-requests")->assertUnprocessable()
            ->assertJsonMissing(['message' => 'private transport diagnostic']);
        $this->assertDatabaseCount('contract_signing_requests', 0);
        $this->assertDatabaseMissing('contract_signature_events', ['event' => 'SIGN_REQUEST_SENT']);
    }

    public function test_email_change_revokes_old_link_and_receipt_but_preserves_lessor_signature(): void
    {
        [$contract, , $document] = $this->fixture();
        $oldToken = $this->start($contract);
        $lessor = "/api/contracts/{$contract->id}/sign/lessor";
        $this->verify($lessor);
        $this->post($lessor, $this->payload($document->document_hash), ['Accept' => 'application/json'])->assertOk();
        $signature = ContractSignature::firstOrFail();
        $oldPrefix = '/api/sign/'.$oldToken;
        $oldOtp = $this->verify($oldPrefix);
        $oldRequest = ContractSigningRequest::where('signer_role', 'TENANT')->firstOrFail();

        $this->putJson("/api/tenants/{$contract->tenant_id}", ['full_name' => 'Nguyễn Văn A', 'email' => 'new@example.test'])->assertOk();

        $oldRequest->refresh();
        $this->assertSame('REVOKED', $oldRequest->status);
        $this->assertNull($oldRequest->active_key);
        $this->assertNull($oldRequest->otp_verified_at);
        $this->assertNull($oldRequest->verification_hash);
        $this->getJson($oldPrefix)->assertUnprocessable()->assertJsonPath('message', ContractSigningService::INVALID_LINK);
        $this->postJson($oldPrefix.'/request-otp')->assertUnprocessable();
        $this->postJson($oldPrefix.'/verify-otp', ['otp' => $oldOtp])->assertUnprocessable();
        $this->post($oldPrefix, $this->payload($document->document_hash), ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertModelExists($signature);
        $this->assertDatabaseCount('contract_signatures', 1);
        $this->assertSame('PARTIALLY_SIGNED', $document->signing()->firstOrFail()->status);
        $this->assertSame($document->source_snapshot, $document->fresh()->source_snapshot);
        Storage::disk('contract_signatures')->assertExists($signature->signature_file_path);

        Notification::fake();
        $this->postJson("/api/contracts/{$contract->id}/signing-requests/resend")->assertOk();
        $newToken = '';
        Notification::assertSentOnDemand(ContractSigningNotification::class, function ($mail, $channels, $recipient) use (&$newToken): bool {
            if ($mail->kind === 'REQUEST' && $recipient->routes['mail'] === 'new@example.test') {
                $newToken = basename($mail->details['url']);

                return true;
            }

            return false;
        });
        $this->assertNotSame($oldToken, $newToken);
        $newPrefix = '/api/sign/'.$newToken;
        $this->getJson($newPrefix)->assertOk()->assertJsonPath('data.masked_email', 'n***@example.test')->assertJsonPath('data.otp_verified', false);
        $this->travel(61)->seconds();
        $this->verify($newPrefix);
        Notification::assertSentOnDemand(ContractSigningNotification::class, fn ($mail, $channels, $recipient) => $mail->kind === 'OTP' && $recipient->routes['mail'] === 'new@example.test');
        $this->post($newPrefix, $this->payload($document->document_hash), ['Accept' => 'application/json'])->assertOk();
        $this->assertSame('COMPLETED', $document->signing()->firstOrFail()->status);
        $this->assertDatabaseCount('contract_signatures', 2);
    }

    public function test_public_actions_recheck_current_email_even_if_contact_was_updated_outside_tenant_api(): void
    {
        [$contract, , $document] = $this->fixture();
        $prefix = '/api/sign/'.$this->start($contract);
        $this->verify($prefix);
        DB::table('tenants')->where('id', $contract->tenant_id)->update(['email' => 'new@example.test']);

        $this->getJson($prefix)->assertUnprocessable()->assertJsonPath('message', ContractSigningService::INVALID_LINK);
        $this->postJson($prefix.'/request-otp')->assertUnprocessable();
        $this->post($prefix, $this->payload($document->document_hash), ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseCount('contract_signatures', 0);
    }

    public function test_removing_email_revokes_pending_request_and_never_falls_back_to_old_snapshot(): void
    {
        [$contract] = $this->fixture();
        $prefix = '/api/sign/'.$this->start($contract);
        Notification::fake();
        $this->putJson("/api/tenants/{$contract->tenant_id}", ['full_name' => 'Nguyễn Văn A', 'email' => null])->assertOk();
        $this->getJson($prefix)->assertUnprocessable();
        $this->postJson("/api/contracts/{$contract->id}/signing-requests/resend")->assertUnprocessable()->assertJsonValidationErrors('email');
        Notification::assertNothingSent();
    }

    public function test_non_email_profile_edit_does_not_revoke_or_reactivate_an_old_link(): void
    {
        [$contract] = $this->fixture();
        $prefix = '/api/sign/'.$this->start($contract);
        $this->putJson("/api/tenants/{$contract->tenant_id}", ['full_name' => 'Tên cập nhật', 'phone' => '0909999999', 'email' => 'tenant@example.test'])->assertOk();
        $this->getJson($prefix)->assertOk();
        $this->putJson("/api/tenants/{$contract->tenant_id}", ['full_name' => 'Tên cập nhật', 'email' => 'new@example.test'])->assertOk();
        $this->putJson("/api/tenants/{$contract->tenant_id}", ['full_name' => 'Tên cập nhật', 'email' => 'tenant@example.test'])->assertOk();
        $this->getJson($prefix)->assertUnprocessable();
        $this->assertSame(1, ContractSignatureEvent::where('event', 'REQUEST_REVOKED')->count());
    }

    public function test_changing_email_after_completion_preserves_all_signed_evidence(): void
    {
        [$contract, , $document] = $this->fixture();
        $prefix = '/api/sign/'.$this->start($contract);
        $this->verify($prefix);
        $this->post($prefix, $this->payload($document->document_hash), ['Accept' => 'application/json'])->assertOk();
        $lessor = "/api/contracts/{$contract->id}/sign/lessor";
        $this->verify($lessor);
        $this->post($lessor, $this->payload($document->document_hash), ['Accept' => 'application/json'])->assertOk();
        $workflow = $document->signing()->firstOrFail();
        $signatures = ContractSignature::orderBy('id')->get()->toArray();

        $this->putJson("/api/tenants/{$contract->tenant_id}", ['full_name' => 'Nguyễn Văn A', 'email' => 'new@example.test'])->assertOk();

        $this->assertSame($workflow->signed_document_hash, $workflow->fresh()->signed_document_hash);
        $this->assertSame($workflow->signed_rendered_html, $workflow->fresh()->signed_rendered_html);
        $this->assertSame($signatures, ContractSignature::orderBy('id')->get()->toArray());
        $this->assertDatabaseMissing('contract_signature_events', ['event' => 'REQUEST_REVOKED']);
        $this->assertSame($document->source_snapshot, $document->fresh()->source_snapshot);
    }

    public function test_email_change_and_revocation_roll_back_together_if_audit_cannot_be_saved(): void
    {
        [$contract] = $this->fixture();
        $prefix = '/api/sign/'.$this->start($contract);
        Event::listen('eloquent.creating: '.ContractSignatureEvent::class, fn () => throw new \RuntimeException('simulated audit failure'));
        try {
            $this->putJson("/api/tenants/{$contract->tenant_id}", ['full_name' => 'Nguyễn Văn A', 'email' => 'new@example.test'])->assertServerError();
        } finally {
            Event::forget('eloquent.creating: '.ContractSignatureEvent::class);
        }
        $this->assertDatabaseHas('tenants', ['id' => $contract->tenant_id, 'email' => 'tenant@example.test']);
        $this->assertDatabaseHas('contract_signing_requests', ['signer_role' => 'TENANT', 'status' => 'PENDING']);
        $this->getJson($prefix)->assertOk();
    }
}
