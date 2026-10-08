<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contract\StoreContractRequest;
use App\Http\Requests\Contract\UpdateContractRequest;
use App\Models\Contract;
use App\Models\Room;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContractController extends Controller
{
    public function index(Request $request, int $roomId): JsonResponse
    {
        $contracts = $this->findOwnedRoom($request, $roomId)
            ->contracts()
            ->with('tenant')
            ->latest()
            ->get();

        return ApiResponse::success($contracts, 'Contracts retrieved successfully.');
    }

    public function store(StoreContractRequest $request, int $roomId): JsonResponse
    {
        $room = $this->findOwnedRoom($request, $roomId);
        $tenant = $this->findOwnedTenant($request, $request->integer('tenant_id'));
        $data = $request->validated();
        $data['tenant_id'] = $tenant->id;

        $room->contracts()->create($data);

        return ApiResponse::success(null, 'Contract created successfully.', 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success(
            $this->findOwnedContract($request, $id)->load(['room', 'tenant']),
            'Contract retrieved successfully.'
        );
    }

    public function update(UpdateContractRequest $request, int $id): JsonResponse
    {
        $contract = $this->findOwnedContract($request, $id);
        $tenant = $this->findOwnedTenant($request, $request->integer('tenant_id'));
        $data = $request->validated();
        $data['tenant_id'] = $tenant->id;

        $contract->update($data);

        return ApiResponse::success(null, 'Contract updated successfully.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->findOwnedContract($request, $id)->delete();

        return ApiResponse::success(null, 'Contract deleted successfully.');
    }

    private function findOwnedRoom(Request $request, int $id): Room
    {
        return Room::query()
            ->whereHas('boardingHouse', fn ($query) => $query->where('landlord_id', $request->user()->landlord->id))
            ->findOrFail($id);
    }

    private function findOwnedTenant(Request $request, int $id): Tenant
    {
        return Tenant::query()
            ->where('landlord_id', $request->user()->landlord->id)
            ->findOrFail($id);
    }

    private function findOwnedContract(Request $request, int $id): Contract
    {
        return Contract::query()
            ->whereHas('room.boardingHouse', fn ($query) => $query->where('landlord_id', $request->user()->landlord->id))
            ->findOrFail($id);
    }
}
