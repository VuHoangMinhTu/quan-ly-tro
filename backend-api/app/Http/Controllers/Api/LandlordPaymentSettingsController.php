<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\PayOSPaymentRequest;
use App\Services\PayOSService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LandlordPaymentSettingsController extends Controller
{
    private function landlord(Request $request)
    {
        // Only the workspace OWNER has a Landlord record. Assigned MANAGERs do not.
        abort_unless($request->user()->landlord, 403);

        return $request->user()->landlord;
    }

    public function profile(Request $request): JsonResponse
    {
        return ApiResponse::success($this->landlord($request)->paymentProfile?->only(['bank_name', 'bank_account_name', 'bank_account_number']) ?? [
            'bank_name' => null, 'bank_account_name' => null, 'bank_account_number' => null,
        ]);
    }

    public function saveProfile(Request $request): JsonResponse
    {
        $landlord = $this->landlord($request);
        $data = $request->validate([
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account_name' => ['nullable', 'string', 'max:255'],
            'bank_account_number' => ['nullable', 'string', 'max:255'],
        ]);
        foreach (['bank_name', 'bank_account_name', 'bank_account_number'] as $field) {
            $data[$field] = trim((string) ($data[$field] ?? '')) ?: null;
        }
        $landlord->paymentProfile()->updateOrCreate(['landlord_id' => $landlord->id], $data);

        return ApiResponse::success($data, 'Đã lưu tài khoản nhận tiền.');
    }

    public function payos(Request $request, PayOSService $payos): JsonResponse
    {
        $landlord = $this->landlord($request);
        $config = $landlord->payosConfig()->first();

        return ApiResponse::success([
            'configured' => $config !== null,
            'is_active' => (bool) $config?->is_active,
            'client_id_masked' => $config ? str_repeat('•', 8).(strlen($config->client_id) > 4 ? substr($config->client_id, -4) : '') : null,
            'api_key_configured' => $config !== null,
            'checksum_key_configured' => $config !== null,
            'webhook_url' => $config ? $payos->webhookUrl($config) : null,
            'legacy_fallback' => $payos->usesGlobalFallback($landlord),
        ]);
    }

    public function savePayos(Request $request): JsonResponse
    {
        $landlord = $this->landlord($request);
        $config = $landlord->payosConfig()->first();
        $data = $request->validate([
            'client_id' => [$config ? 'sometimes' : 'required', 'string', 'max:255'],
            'api_key' => [$config ? 'sometimes' : 'required', 'string'],
            'checksum_key' => [$config ? 'sometimes' : 'required', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        foreach (['client_id', 'api_key', 'checksum_key'] as $key) {
            if (array_key_exists($key, $data) && trim($data[$key]) === '') {
                throw ValidationException::withMessages([$key => 'Trường này không được để trống.']);
            }
        }
        if ($config && array_intersect(array_keys($data), ['client_id', 'api_key', 'checksum_key'])
            && PayOSPaymentRequest::query()->where('payos_config_id', $config->id)
                ->whereIn('status', [...PayOSPaymentRequest::REUSABLE_STATUSES, 'CREATING', 'CREATION_FAILED', 'RECONCILIATION_REQUIRED', 'STALE'])
                ->exists()) {
            throw ValidationException::withMessages(['payos' => 'Còn yêu cầu thanh toán PayOS chưa hoàn tất. Hãy đối soát trước khi đổi credentials.']);
        }
        $landlord->payosConfig()->updateOrCreate(['landlord_id' => $landlord->id], [
            ...$data,
            'webhook_uuid' => $config?->webhook_uuid ?? (string) Str::uuid(),
            'is_active' => $data['is_active'] ?? $config?->is_active ?? true,
        ]);

        return ApiResponse::success(null, 'Đã lưu cấu hình PayOS. Hãy xác nhận webhook bằng Artisan command.');
    }

    public function deletePayos(Request $request): JsonResponse
    {
        $this->landlord($request)->payosConfig()->update(['is_active' => false]);

        return ApiResponse::success(null, 'Đã ngắt kết nối PayOS.');
    }
}
