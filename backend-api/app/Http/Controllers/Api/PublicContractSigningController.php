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

class PublicContractSigningController extends Controller
{
    public function __construct(private ContractSigningService $signing) {}

    public function show(Request $request, string $token): JsonResponse
    {
        return ApiResponse::success($this->signing->context($request, token: $token), 'Lấy tài liệu cần ký thành công.');
    }

    public function requestOtp(Request $request, string $token): JsonResponse
    {
        $this->signing->requestOtp($request, token: $token);

        return ApiResponse::success(null, 'Đã gửi mã xác thực tới email của bạn.');
    }

    public function verifyOtp(VerifySigningOtpRequest $request, string $token): JsonResponse
    {
        $proof = $this->signing->verifyOtp($request, $request->validated('otp'), token: $token);

        return ApiResponse::success(['verification_token' => $proof], 'Xác thực OTP thành công.');
    }

    public function sign(SubmitContractSignatureRequest $request, string $token): JsonResponse
    {
        $this->signing->sign($request, $request->validated(), token: $token);

        return ApiResponse::success(null, 'Xác nhận ký hợp đồng thành công.');
    }

    public function decline(SigningReasonRequest $request, string $token): JsonResponse
    {
        $this->signing->decline($request, $request->validated('reason'), $token);

        return ApiResponse::success(null, 'Đã ghi nhận từ chối ký hợp đồng.');
    }

    public function image(Request $request, string $token, int $signatureId): Response
    {
        return response($this->signing->image($request, $signatureId, token: $token), 200, ['Content-Type' => 'image/png']);
    }
}
