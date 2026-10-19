<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contract\SigningReasonRequest;
use App\Http\Requests\Contract\SubmitContractSignatureRequest;
use App\Http\Requests\Contract\VerifySigningOtpRequest;
use App\Services\ContractSigningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ContractSigningController extends Controller
{
    public function __construct(private ContractSigningService $signing) {}

    public function status(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->signing->status($request, $id), 'Lấy tình trạng ký thành công.');
    }

    public function store(Request $request, int $id): JsonResponse
    {
        return $this->send($request, $id, false);
    }

    public function resend(Request $request, int $id): JsonResponse
    {
        return $this->send($request, $id, true);
    }

    private function send(Request $request, int $id, bool $resend): JsonResponse
    {
        $input = $request->validate(['expected_tenant_email' => ['sometimes', 'string', 'email', 'max:255']]);
        $receipt = $this->signing->start($request, $id, $resend, $input['expected_tenant_email'] ?? null);
        $message = match ($receipt['action']) {
            'sent' => 'Đã gửi yêu cầu ký tới '.$receipt['recipient_email'].($resend ? ' Liên kết cũ không còn hiệu lực.' : ''),
            'reused' => 'Yêu cầu ký tới '.$receipt['recipient_email'].' vẫn còn hiệu lực. Chưa gửi email mới; hãy chọn Gửi lại yêu cầu ký nếu cần.',
            default => 'Yêu cầu ký Bên A đã sẵn sàng. Không gửi email mới cho người thuê đã ký.',
        };

        return ApiResponse::success($receipt, $message);
    }

    public function context(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->signing->context($request, $id), 'Lấy tài liệu cần ký thành công.');
    }

    public function requestOtp(Request $request, int $id): JsonResponse
    {
        $this->signing->requestOtp($request, $id);

        return ApiResponse::success(null, 'Đã gửi mã xác thực tới email của bạn.');
    }

    public function verifyOtp(VerifySigningOtpRequest $request, int $id): JsonResponse
    {
        $proof = $this->signing->verifyOtp($request, $request->validated('otp'), $id);

        return ApiResponse::success(['verification_token' => $proof], 'Xác thực OTP thành công.');
    }

    public function sign(SubmitContractSignatureRequest $request, int $id): JsonResponse
    {
        $this->signing->sign($request, $request->validated(), $id);

        return ApiResponse::success(null, 'Xác nhận ký hợp đồng thành công.');
    }

    public function void(SigningReasonRequest $request, int $id): JsonResponse
    {
        $this->signing->void($request, $id, $request->validated('reason'));

        return ApiResponse::success(null, 'Đã hủy tài liệu ký. Lịch sử được giữ nguyên.');
    }

    public function signed(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->signing->signedDocument($request, $id), 'Lấy hợp đồng đã ký thành công.');
    }

    public function image(Request $request, int $id, int $signatureId): Response
    {
        return response($this->signing->image($request, $signatureId, $id), 200, ['Content-Type' => 'image/png']);
    }
}
