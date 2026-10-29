<?php

namespace App\Services;

use App\Models\ContractDocument;
use App\Models\ContractDocumentSigning;
use App\Models\ContractSignatureEvent;
use App\Models\ContractSigningRequest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ContractSigningService
{
    public const CONSENT_VERSION = 'BEEHOUSE_SIGN_V1';

    public const CONSENT_TEXT = 'Tôi xác nhận đã đọc và đồng ý với nội dung hợp đồng này.';

    public const INVALID_LINK = 'Liên kết ký không hợp lệ hoặc đã hết hạn.';

    public function __construct(
        private ContractService $contracts,
        private BoardingHouseAccessService $access,
        private SigningOtpService $otp,
        private SigningMailService $mail,
        private SignatureFileService $files,
        private ContractSigningAudit $audit,
        private ContractTemplateRenderer $templateRenderer,
    ) {}

    private function fail(string $message, string $field = 'signing'): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function integrity(ContractDocument $document): void
    {
        if (! $document->finalized_at || ! hash_equals($document->document_hash, hash('sha256', $document->rendered_html))) {
            $this->fail('Nội dung tài liệu không khớp bản đã chốt. Không thể tiếp tục ký.');
        }
    }

    private function owned(Request $http, int $id, bool $lock = false): ContractDocument
    {
        $contract = $this->contracts->find($http->user(), $id);
        if ($lock) {
            $this->access->house($http->user(), $contract->room->boarding_house_id, true);
        }
        $document = $contract->document()->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
        $this->integrity($document);

        return $document;
    }

    private function workflow(ContractDocument $document): ContractDocumentSigning
    {
        return $document->signing()->lockForUpdate()->first()
            ?? $document->signing()->create(['status' => 'NOT_STARTED']);
    }

    private function writable(ContractDocumentSigning $workflow): void
    {
        if (in_array($workflow->status, ['VOID', 'DECLINED', 'COMPLETED'], true)) {
            $this->fail('Tài liệu đã hoàn tất, bị từ chối hoặc đã hủy. Không thể thêm yêu cầu ký.');
        }
    }

    private function representative(ContractDocument $document, ?User $actor = null): User
    {
        $id = data_get($document->source_snapshot, 'lessor.user_id');
        $person = User::query()->find($id);
        if (! $person || ($actor && (int) $actor->id !== (int) $id)) {
            abort(403);
        }
        $house = $document->contract->room->boardingHouse;
        if (! $this->access->houses($person)->where('boarding_houses.id', $house->id)->exists()
            || ! $person->hasVerifiedEmail()) {
            $this->fail('Người đại diện đã chốt không còn đủ điều kiện ký. Vui lòng hủy tài liệu và lập tài liệu mới, không ký thay.');
        }

        return $person;
    }

    /** Delivery contact may change; legal identity and rendered document remain frozen. */
    private function tenantEmail(ContractDocument $document): string
    {
        $contract = $document->contract;
        $tenant = Tenant::query()->where('landlord_id', $contract->room->boardingHouse->landlord_id)
            ->lockForUpdate()->find($contract->tenant_id);

        return trim((string) $tenant?->email);
    }

    public function start(Request $http, int $id, bool $resend = false, ?string $expectedEmail = null): array
    {
        return DB::transaction(function () use ($http, $id, $resend, $expectedEmail): array {
            $document = $this->owned($http, $id, true);
            $workflow = $this->workflow($document);
            $this->writable($workflow);
            $representative = $this->representative($document);
            $email = $this->tenantEmail($document);
            $tenant = $document->signingRequests()->where('signer_role', 'TENANT')->latest('id')->lockForUpdate()->first();
            $destinationChanged = $tenant && trim($tenant->delivery_destination_snapshot) !== $email;
            if ($expectedEmail !== null && trim($expectedEmail) !== $email) {
                $this->fail('Email người thuê đã thay đổi so với lúc xác nhận. Vui lòng tải lại tình trạng ký và kiểm tra email trước khi gửi.', 'email');
            }
            if ($tenant?->status !== 'SIGNED' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->fail('Người thuê chưa có email hợp lệ. Vui lòng cập nhật email người thuê trước khi gửi yêu cầu ký.', 'email');
            }
            if ($tenant && ! $resend && ! $destinationChanged && $tenant->active_key && $tenant->token_expires_at > now()) {
                return $this->deliveryReceipt($tenant, 'reused', false);
            }
            if ($tenant && ! $destinationChanged && $tenant->status !== 'SIGNED' && $tenant->created_at->copy()->addSeconds(max(1, config('signing.request_resend_seconds'))) > now()) {
                $this->fail('Bạn đã gửi yêu cầu quá nhiều lần. Vui lòng thử lại sau.');
            }
            if ($tenant?->active_key && $tenant->status !== 'SIGNED') {
                $this->revoke($document, $tenant, $http);
            }
            $lessor = $document->signingRequests()->where('signer_role', 'LESSOR')->latest('id')->lockForUpdate()->first();
            if (! $lessor || ($lessor->status !== 'SIGNED' && ($lessor->token_expires_at <= now()
                || $lessor->delivery_destination_snapshot !== $representative->email
                || $lessor->otp_attempts >= max(1, config('signing.otp_max_attempts'))))) {
                if ($lessor && $lessor->created_at->copy()->addSeconds(max(1, config('signing.request_resend_seconds'))) > now()) {
                    $this->fail('Bạn đã gửi yêu cầu quá nhiều lần. Vui lòng thử lại sau.');
                }
                if ($lessor?->active_key) {
                    $this->revoke($document, $lessor, $http);
                }
                $lessor = $this->newRequest($document, 'LESSOR', (string) data_get($document->source_snapshot, 'lessor.full_name'), $representative->email, $http, $representative->id);
            }
            if ($tenant?->status === 'SIGNED') {
                return $this->deliveryReceipt($lessor, 'lessor_ready', false);
            }
            $token = bin2hex(random_bytes(32));
            $request = $this->newRequest($document, 'TENANT', (string) data_get($document->source_snapshot, 'tenant.full_name'), $email, $http, null, $token);
            $url = rtrim((string) config('app.frontend_url'), '/').'/sign/'.$token;
            if (app()->isProduction() && parse_url($url, PHP_URL_SCHEME) !== 'https') {
                $this->fail('Liên kết ký trên production phải sử dụng HTTPS.');
            }
            $this->mail->send($email, 'REQUEST', [
                'name' => $request->signer_name_snapshot, 'url' => $url,
                'code' => data_get($document->source_snapshot, 'contract.contract_code'),
                'room' => data_get($document->source_snapshot, 'room.room_code'),
                'house' => data_get($document->source_snapshot, 'room.boarding_house_name'),
                'expires' => $request->token_expires_at->copy()->timezone(config('signing.display_timezone'))->format('d/m/Y H:i P'),
            ]);
            $request->update(['sent_at' => now()]);
            $this->audit->record($document, 'SIGN_REQUEST_SENT', $http, $request);
            $workflow->update(['status' => $document->signatures()->lockForUpdate()->get(['id'])->isNotEmpty() ? 'PARTIALLY_SIGNED' : 'PENDING']);

            return $this->deliveryReceipt($request, 'sent', true);
        });
    }

    /** Authenticated management response only: identifies this exact transport submission, never returns the secret link. */
    private function deliveryReceipt(ContractSigningRequest $request, string $action, bool $submitted): array
    {
        return ['request_id' => $request->id, 'tenant_id' => $request->signer_tenant_id,
            'signer_role' => $request->signer_role, 'recipient_email' => $request->delivery_destination_snapshot,
            'sent_at' => $request->sent_at, 'action' => $action, 'mail_submitted' => $submitted];
    }

    private function newRequest(ContractDocument $document, string $role, string $name, string $email, Request $http, ?int $userId = null, ?string $token = null): ContractSigningRequest
    {
        $request = $document->signingRequests()->create([
            'signer_role' => $role, 'active_key' => $document->id.':'.$role,
            'signer_user_id' => $userId, 'signer_tenant_id' => $role === 'TENANT' ? $document->contract->tenant_id : null,
            'signer_name_snapshot' => $name, 'delivery_destination_snapshot' => $email,
            'token_hash' => $token ? hash('sha256', $token) : null,
            'token_expires_at' => now()->addHours(max(1, config('signing.link_expiry_hours'))),
            'created_by_user_id' => $http->user()->id, 'status' => 'PENDING',
        ]);
        $this->audit->record($document, 'SIGN_REQUEST_CREATED', $http, $request);

        return $request;
    }

    private function revoke(ContractDocument $document, ContractSigningRequest $request, Request $http): void
    {
        $request->update(['status' => 'REVOKED', 'active_key' => null, 'revoked_at' => now(), 'otp_hash' => null, 'otp_verified_at' => null, 'verification_hash' => null]);
        $this->audit->record($document, 'REQUEST_REVOKED', $http, $request);
    }

    /** Lock order is always document -> request; both parties serialize completion on the same document. */
    private function resolve(Request $http, ?int $id, ?string $token): array
    {
        if ($id !== null) {
            $document = $this->owned($http, $id, true);
            $representative = $this->representative($document, $http->user());
            $signing = $document->signingRequests()->where('signer_role', 'LESSOR')->latest('id')->lockForUpdate()->first();
            if ($signing && $signing->delivery_destination_snapshot !== $representative->email) {
                $this->fail('Email người đại diện đã thay đổi. Vui lòng gửi lại yêu cầu ký trước khi xác thực.');
            }
        } else {
            if (! $token || ! preg_match('/\A[a-f0-9]{64}\z/', $token)) {
                $this->fail(self::INVALID_LINK);
            }
            $candidate = ContractSigningRequest::query()->where('token_hash', hash('sha256', $token))->first();
            if (! $candidate) {
                $this->fail(self::INVALID_LINK);
            }
            $document = ContractDocument::query()->lockForUpdate()->findOrFail($candidate->contract_document_id);
            $signing = ContractSigningRequest::query()->lockForUpdate()->findOrFail($candidate->id);
            $this->integrity($document);
        }
        // Current locking reads avoid stale MySQL REPEATABLE READ snapshots after waiting for the other signer.
        $workflow = $document->signing()->lockForUpdate()->first();
        if (! $signing || $signing->token_expires_at <= now() || in_array($signing->status, ['REVOKED', 'DECLINED', 'EXPIRED'], true)
            || ! $workflow || in_array($workflow->status, ['VOID', 'DECLINED'], true)) {
            $this->fail($id === null ? self::INVALID_LINK : 'Yêu cầu ký không còn hiệu lực. Vui lòng gửi lại yêu cầu ký.');
        }
        // Defense in depth for contacts changed outside TenantController: never send
        // another OTP, disclose the document, or accept an old receipt at the old address.
        if ($signing->signer_role === 'TENANT' && $signing->status !== 'SIGNED'
            && trim($signing->delivery_destination_snapshot) !== $this->tenantEmail($document)) {
            $this->fail(self::INVALID_LINK);
        }

        return [$document, $signing, $workflow];
    }

    public function context(Request $http, ?int $id = null, ?string $token = null): array
    {
        return DB::transaction(function () use ($http, $id, $token): array {
            [$document, $signing, $workflow] = $this->resolve($http, $id, $token);
            $this->audit->record($document, 'DOCUMENT_VIEWED', $http, $signing);
            $signed = $workflow->signed_rendered_html;
            if ($signed !== null && ! hash_equals($workflow->signed_document_hash, hash('sha256', $signed))) {
                $this->fail('Bản đã ký không khớp dữ liệu đã lưu.');
            }

            return [
                'rendered_html' => $this->withCurrentSignatures($document), 'content_hash' => $document->document_hash,
                'signed_rendered_html' => $signed, 'signing_status' => $workflow->status,
                'request_status' => $signing->status, 'signer_role' => $signing->signer_role,
                'signer_name' => $signing->signer_name_snapshot, 'masked_email' => $this->mask($signing->delivery_destination_snapshot),
                'expires_at' => $signing->token_expires_at, 'otp_verified' => $signing->otp_verified_at !== null && $signing->otp_expires_at > now()
                    && $signing->verification_hash && hash_equals($signing->verification_hash, hash('sha256', (string) $http->header('X-Signing-Verification'))),
                'contract_code' => data_get($document->source_snapshot, 'contract.contract_code'),
                'room_code' => data_get($document->source_snapshot, 'room.room_code'),
                'boarding_house_name' => data_get($document->source_snapshot, 'room.boarding_house_name'),
                'consent_version' => self::CONSENT_VERSION, 'consent_text' => self::CONSENT_TEXT,
                'signature_ids' => $document->signatures()->pluck('id'),
            ];
        });
    }

    private function mask(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1).'***@'.$domain;
    }

    public function requestOtp(Request $http, ?int $id = null, ?string $token = null): void
    {
        DB::transaction(function () use ($http, $id, $token): void {
            [$document, $signing, $workflow] = $this->resolve($http, $id, $token);
            $this->writable($workflow);
            if ($signing->status === 'SIGNED') {
                $this->fail('Bạn đã ký tài liệu này.');
            }
            $this->otp->send($signing);
            $this->audit->record($document, 'OTP_SENT', $http, $signing);
        });
    }

    public function verifyOtp(Request $http, string $code, ?int $id = null, ?string $token = null): string
    {
        $proof = bin2hex(random_bytes(32));
        $error = DB::transaction(function () use ($http, $code, $id, $token, $proof): ?string {
            [$document, $signing, $workflow] = $this->resolve($http, $id, $token);
            $this->writable($workflow);
            if ($signing->status === 'SIGNED') {
                return 'Bạn đã ký tài liệu này.';
            }
            $error = $this->otp->verify($signing, $code);
            if ($error === null) {
                $signing->verification_hash = hash('sha256', $proof);
                $signing->save();
                $this->audit->record($document, 'OTP_VERIFIED', $http, $signing);
            }

            return $error;
        });
        if ($error) {
            $this->fail($error, 'otp');
        }

        return $proof;
    }

    public function sign(Request $http, array $data, ?int $id = null, ?string $token = null): void
    {
        $file = null;
        $notifyDocument = null;
        try {
            DB::transaction(function () use ($http, $data, $id, $token, &$file, &$notifyDocument): void {
                [$document, $signing, $workflow] = $this->resolve($http, $id, $token);
                // Retry after a lost response returns success without another file, signature or completion event.
                if ($signing->status === 'SIGNED') {
                    return;
                }
                $this->writable($workflow);
                if ($signing->status !== 'OTP_VERIFIED' || ! $signing->otp_verified_at || $signing->otp_expires_at <= now()) {
                    $this->fail('Vui lòng xác thực mã OTP còn hiệu lực trước khi ký.', 'otp');
                }
                if (! $signing->verification_hash || ! hash_equals($signing->verification_hash, hash('sha256', $data['verification_token']))) {
                    $this->fail('Vui lòng xác thực OTP trên trình duyệt này trước khi ký.', 'otp');
                }
                if (! hash_equals($document->document_hash, $data['content_hash']) || $data['consent_version'] !== self::CONSENT_VERSION) {
                    $this->fail('Nội dung xác nhận không khớp tài liệu. Vui lòng tải lại trang.');
                }
                $file = $this->files->store($http->file('signature'));
                $this->audit->record($document, 'SIGNATURE_SUBMITTED', $http, $signing);
                $document->signatures()->create([
                    'signing_request_id' => $signing->id, 'signer_role' => $signing->signer_role,
                    'signer_user_id' => $signing->signer_user_id, 'signer_tenant_id' => $signing->signer_tenant_id,
                    'signer_name_snapshot' => $signing->signer_name_snapshot,
                    'signature_method' => 'DRAWN', 'verification_method' => $id !== null ? 'AUTHENTICATED_SESSION_OTP' : 'EMAIL_OTP',
                    'signature_file_path' => $file['path'], 'signature_file_hash' => $file['hash'],
                    'document_hash' => $document->document_hash, 'consent_version' => self::CONSENT_VERSION, 'consent_text' => self::CONSENT_TEXT,
                    'signed_at' => now(), 'ip_address' => $http->ip(), 'user_agent' => mb_substr((string) $http->userAgent(), 0, 500),
                ]);
                $signing->update(['status' => 'SIGNED', 'completed_at' => now(), 'otp_hash' => null, 'otp_verified_at' => null, 'verification_hash' => null]);
                $this->audit->record($document, 'SIGNED', $http, $signing);
                $signatures = $document->signatures()->orderBy('signer_role')->lockForUpdate()->get();
                if ($signatures->pluck('signer_role')->sort()->values()->all() === ['LESSOR', 'TENANT']) {
                    $unsigned = $this->unsignedBlockInDocument($document);
                    $signatureBlock = $this->signatureBlock($signatures->all(), $this->signatureTypeForBlock($unsigned));
                    $html = str_contains($document->rendered_html, $unsigned)
                        ? str_replace($unsigned, $signatureBlock, $document->rendered_html)
                        : $document->rendered_html.$signatureBlock;
                    $html .= '<p>Xác nhận ký điện tử bằng OTP và chữ ký vẽ tay.</p>';
                    $workflow->update(['status' => 'COMPLETED', 'signed_rendered_html' => $html,
                        'signed_document_hash' => hash('sha256', $html), 'signatures_completed_at' => now()]);
                    $this->audit->record($document, 'SIGNING_COMPLETED', $http, $signing);
                } else {
                    $workflow->update(['status' => 'PARTIALLY_SIGNED']);
                }
                $notifyDocument = $signing->signer_role === 'TENANT' || $workflow->status === 'COMPLETED' ? $document : null;
            });
        } catch (\Throwable $exception) {
            if ($file) {
                Storage::disk('contract_signatures')->delete($file['path']);
            }
            throw $exception;
        }
        if ($notifyDocument) {
            $this->notifyProgress($notifyDocument, $http);
        }
    }

    /** Display current evidence without changing the frozen document or its consent hash. */
    private function withCurrentSignatures(ContractDocument $document): string
    {
        $unsigned = $this->unsignedBlockInDocument($document);
        if (! str_contains($document->rendered_html, $unsigned)) {
            return $document->rendered_html;
        }

        return str_replace($unsigned, $this->signatureBlock($document->signatures()->get()->all(), $this->signatureTypeForBlock($unsigned)), $document->rendered_html);
    }

    private function unsignedBlockInDocument(ContractDocument $document): string
    {
        foreach ([data_get($document->source_snapshot, 'contract.contract_type'), null] as $type) {
            $block = $this->templateRenderer->unsignedSignatureBlock($type);
            if (str_contains($document->rendered_html, $block)) {
                return $block;
            }
        }

        return $this->templateRenderer->unsignedSignatureBlock();
    }

    private function signatureTypeForBlock(string $block): ?string
    {
        if (str_contains($block, 'ĐẠI DIỆN BÊN CHO THUÊ')) {
            return 'DORM_BED';
        }

        return str_contains($block, 'Người đại diện (Họ tên, chữ ký)') ? 'ROOM_RENTAL' : null;
    }

    private function signatureBlock(array $signatures, ?string $type = null): string
    {
        $byRole = collect($signatures)->keyBy('signer_role');
        $html = '<div class="document-signatures">';
        $titles = $type === 'DORM_BED'
            ? ['LESSOR' => 'ĐẠI DIỆN BÊN CHO THUÊ (BÊN A)', 'TENANT' => 'ĐẠI DIỆN BÊN THUÊ (BÊN B)']
            : ['LESSOR' => 'BÊN CHO THUÊ (BÊN A)', 'TENANT' => 'BÊN THUÊ (BÊN B)'];
        foreach ($titles as $role => $title) {
            $caption = match ($type) {
                'DORM_BED' => '(Ký và ghi rõ họ tên)',
                'ROOM_RENTAL' => $role === 'LESSOR' ? 'Người đại diện (Họ tên, chữ ký)' : '(Họ tên, chữ ký)',
                default => null,
            };
            $html .= '<div><h3>'.$title.'</h3>'.($caption ? '<p>'.$caption.'</p>' : '');
            $signature = $byRole->get($role);
            if ($signature) {
                $html .= '<img data-signature-id="'.$signature->id.'" data-file-hash="'.$signature->signature_file_hash.'" alt="Chữ ký vẽ tay">'
                    .'<p>'.e($signature->signer_name_snapshot).'</p><p>Ký lúc: '.$signature->signed_at->copy()->timezone(config('signing.display_timezone'))->format('d/m/Y H:i P').'</p>';
            } else {
                $html .= '<div class="signature-handwritten-space"></div>';
            }
            $html .= '</div>';
        }

        return $html.'</div>';
    }

    private function notifyProgress(ContractDocument $document, Request $http): void
    {
        $complete = $document->signing->status === 'COMPLETED';
        $house = $document->contract->room->boardingHouse;
        $recipients = User::query()->whereIn('id', array_filter([data_get($document->source_snapshot, 'lessor.user_id'), $house->landlord->user_id]))->get();
        foreach ($recipients as $recipient) {
            try {
                $this->mail->send($recipient->email, 'PROGRESS', ['name' => $recipient->name,
                    'message' => $complete ? 'Hợp đồng '.data_get($document->source_snapshot, 'contract.contract_code').' đã hoàn tất ký.'
                        : data_get($document->source_snapshot, 'tenant.full_name').' đã ký hợp đồng '.data_get($document->source_snapshot, 'contract.contract_code').'.']);
            } catch (ValidationException) {
                $this->audit->record($document, 'NOTIFICATION_FAILED', $http);
            }
        }
    }

    public function decline(Request $http, string $reason, string $token): void
    {
        DB::transaction(function () use ($http, $reason, $token): void {
            [$document, $signing, $workflow] = $this->resolve($http, null, $token);
            $this->writable($workflow);
            if ($signing->status !== 'OTP_VERIFIED' || ! $signing->otp_verified_at || $signing->otp_expires_at <= now()) {
                $this->fail('Vui lòng xác thực OTP trước khi từ chối ký.');
            }
            if (! $signing->verification_hash || ! hash_equals($signing->verification_hash, hash('sha256', (string) $http->header('X-Signing-Verification')))) {
                $this->fail('Vui lòng xác thực OTP trên trình duyệt này trước khi từ chối ký.');
            }
            $signing->update(['status' => 'DECLINED', 'active_key' => null, 'otp_hash' => null, 'otp_verified_at' => null, 'verification_hash' => null]);
            $workflow->update(['status' => 'DECLINED']);
            $this->audit->record($document, 'DECLINED', $http, $signing, ['reason' => $reason]);
        });
    }

    public function void(Request $http, int $id, string $reason): void
    {
        DB::transaction(function () use ($http, $id, $reason): void {
            $document = $this->owned($http, $id, true);
            abort_unless($this->access->isOwner($http->user(), $document->contract->room->boardingHouse), 403);
            $workflow = $this->workflow($document);
            if ($workflow->status === 'VOID') {
                return;
            }
            $workflow->update(['status' => 'VOID', 'voided_at' => now(), 'void_reason' => $reason]);
            foreach ($document->signingRequests()->whereIn('status', ['PENDING', 'OTP_VERIFIED'])->lockForUpdate()->get() as $signing) {
                $this->revoke($document, $signing, $http);
            }
            $this->audit->record($document, 'DOCUMENT_VOIDED', $http, null, ['reason' => $reason]);
        });
    }

    public function status(Request $http, int $id): array
    {
        $document = $this->owned($http, $id);
        $contract = $document->contract;
        $contact = Tenant::query()->where('landlord_id', $contract->room->boardingHouse->landlord_id)->find($contract->tenant_id);

        return [
            'tenant_contact' => ['tenant_id' => $contract->tenant_id, 'name' => $contact?->full_name, 'email' => $contact?->email],
            'status' => $document->signing?->status ?? 'NOT_STARTED',
            'completed_at' => $document->signing?->signatures_completed_at,
            'void_reason' => $document->signing?->void_reason,
            'is_owner' => $this->access->isOwner($http->user(), $document->contract->room->boardingHouse),
            'is_representative' => (int) data_get($document->source_snapshot, 'lessor.user_id') === (int) $http->user()->id,
            'signers' => collect(['LESSOR', 'TENANT'])->map(function (string $role) use ($document): array {
                $request = $document->signingRequests()->where('signer_role', $role)->latest('id')->first();

                return ['role' => $role, 'name' => data_get($document->source_snapshot, $role === 'LESSOR' ? 'lessor.full_name' : 'tenant.full_name'),
                    'delivery_email' => $request?->delivery_destination_snapshot, 'last_sent_at' => $request?->sent_at,
                    'status' => $request && $request->status !== 'SIGNED' && $request->token_expires_at <= now() ? 'EXPIRED' : ($request?->status ?? 'NOT_STARTED'),
                    'signed_at' => $request?->completed_at];
            }),
            'events' => ContractSignatureEvent::query()->where('contract_document_id', $document->id)->orderBy('id')->get(['id', 'event', 'created_at', 'metadata']),
        ];
    }

    public function image(Request $http, int $signatureId, ?int $id = null, ?string $token = null): string
    {
        return DB::transaction(function () use ($http, $signatureId, $id, $token): string {
            $document = $id !== null ? $this->owned($http, $id) : $this->resolve($http, null, $token)[0];
            $signature = $document->signatures()->findOrFail($signatureId);

            return $this->files->read($signature);
        });
    }

    public function signedDocument(Request $http, int $id): array
    {
        $document = $this->owned($http, $id);
        $workflow = $document->signing;
        if (! $workflow?->signed_rendered_html || ! hash_equals($workflow->signed_document_hash, hash('sha256', $workflow->signed_rendered_html))) {
            $this->fail('Chưa có bản hợp đồng hoàn tất ký hoặc dữ liệu không khớp.');
        }
        $this->audit->record($document, 'DOCUMENT_VIEWED', $http);

        return ['rendered_html' => $workflow->signed_rendered_html, 'source_snapshot' => $document->source_snapshot, 'signed_document_hash' => $workflow->signed_document_hash,
            'status' => $workflow->status, 'signature_ids' => $document->signatures()->pluck('id'), 'completed_at' => $workflow->signatures_completed_at];
    }
}
