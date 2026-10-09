<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreTenantRequest;
use App\Http\Requests\Tenant\UpdateTenantRequest;
use App\Models\Landlord;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->landlordTenants($request)->latest()->get(),
            'Lấy danh sách người thuê thành công.'
        );
    }

    // tương đương method POST bên rest api
    public function store(StoreTenantRequest $request): JsonResponse
    {
        $tenant = $this->landlordTenants($request)->create($request->validated());

        return ApiResponse::success(null, 'Tạo người thuê thành công.', 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success(
            $this->findOwnedTenant($request, $id),
            'Lấy thông tin người thuê thành công.'
        );
    }

    public function update(UpdateTenantRequest $request, int $id): JsonResponse
    {
        $tenant = $this->findOwnedTenant($request, $id);
        $tenant->update($request->validated());

        return ApiResponse::success(null, 'Cập nhật người thuê thành công.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->findOwnedTenant($request, $id)->delete();

        return ApiResponse::success(null, 'Xóa người thuê thành công.');
    }

    private function landlordTenants(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Landlord $landlord */
        $landlord = $user->landlord;

        return $landlord->tenants();
    }

    private function findOwnedTenant(Request $request, int $id): Tenant
    {
        return $this->landlordTenants($request)->findOrFail($id);
    }
}
