<?php

namespace App\Services;

use App\Models\ContractSigningRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class SigningOtpService
{
    public function __construct(private SigningMailService $mail) {}

    /** Caller holds the document and request locks. */
    public function send(ContractSigningRequest $request): void
    {
        if ($request->last_otp_sent_at && $request->last_otp_sent_at->copy()->addSeconds(max(1, config('signing.otp_resend_seconds'))) > now()) {
            throw ValidationException::withMessages(['otp' => 'Bạn đã yêu cầu mã quá nhiều lần. Vui lòng thử lại sau.']);
        }
        if ($request->otp_attempts >= max(1, config('signing.otp_max_attempts'))) {
            throw ValidationException::withMessages(['otp' => 'Đã vượt số lần xác thực. Vui lòng yêu cầu liên kết ký mới.']);
        }
        $otp = (string) random_int(100000, 999999);
        $minutes = max(1, config('signing.otp_expiry_minutes'));
        $request->update(['otp_hash' => Hash::make($otp), 'otp_expires_at' => now()->addMinutes($minutes),
            'otp_verified_at' => null, 'verification_hash' => null, 'last_otp_sent_at' => now(), 'status' => 'PENDING']);
        $this->mail->send($request->delivery_destination_snapshot, 'OTP', ['name' => $request->signer_name_snapshot, 'otp' => $otp, 'minutes' => $minutes]);
    }

    /** Return a failure instead of throwing in the transaction, preserving attempt counts. */
    public function verify(ContractSigningRequest $request, string $otp): ?string
    {
        if ($request->otp_attempts >= max(1, config('signing.otp_max_attempts'))) {
            return 'Đã vượt số lần xác thực. Vui lòng yêu cầu liên kết ký mới.';
        }
        if (! $request->otp_hash || ! $request->otp_expires_at || $request->otp_expires_at <= now()) {
            return 'Mã xác thực đã hết hạn hoặc chưa được gửi. Vui lòng yêu cầu mã mới.';
        }
        if (! Hash::check($otp, $request->otp_hash)) {
            $request->increment('otp_attempts');

            return 'Mã xác thực không đúng. Vui lòng kiểm tra lại.';
        }
        $request->update(['otp_verified_at' => now(), 'status' => 'OTP_VERIFIED', 'otp_hash' => null]);

        return null;
    }
}
