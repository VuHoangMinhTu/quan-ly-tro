<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\BoardingHouse\StoreBoardingHouseRequest;
use App\Http\Requests\BoardingHouse\StoreBoardingHouseWithRoomsRequest;
use App\Http\Requests\BoardingHouse\UpdateBoardingHouseRequest;
use App\Models\BoardingHouse;
use App\Models\Landlord;
use App\Models\User;
use App\Services\RoomOrderingService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BoardingHouseController extends Controller
{
    // Tương đương get bên rest api
    public function index(Request $request): JsonResponse
    {
        $boardingHouses = $this->landlordBoardingHouses($request)
            ->latest()
            ->get();

        return ApiResponse::success($boardingHouses, 'Lấy danh sách nhà trọ thành công.');
    }

    // Tương đương post bên rest api
    public function store(StoreBoardingHouseRequest $request): JsonResponse
    {
        DB::transaction(fn () => $this->createUncontaminatedHouse($request, $request->validated()));

        return ApiResponse::success(null, 'Tạo nhà trọ thành công.', 201);
    }

    public function storeWithRooms(StoreBoardingHouseWithRoomsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $key = $data['idempotency_key'];
        unset($data['idempotency_key']);
        $hash = hash('sha256', json_encode($this->canonicalPayload($data), JSON_THROW_ON_ERROR));

        try {
            $result = DB::transaction(function () use ($request, $data, $key, $hash): array {
                DB::table('house_creation_requests')->insertOrIgnore([
                    'user_id' => $request->user()->id, 'request_key' => $key,
                    'payload_hash' => $hash, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $receipt = DB::table('house_creation_requests')->where('user_id', $request->user()->id)
                    ->where('request_key', $key)->lockForUpdate()->first();
                if (! $receipt || ! hash_equals($receipt->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['idempotency_key' => 'Mã yêu cầu đã dùng cho dữ liệu khác. Vui lòng bắt đầu thao tác mới.']);
                }
                if ($receipt->house_id !== null) {
                    return ['id' => $receipt->house_id, 'rooms_count' => $receipt->rooms_count];
                }
                // Both ownership keys are assigned by relations, never by the client.
                $house = $this->createUncontaminatedHouse($request, $data['boarding_house']);
                $floorIds = [];
                foreach ($data['floors'] ?? [] as $floor) {
                    $floorIds[$floor['client_key']] = $house->floors()->create([
                        'name' => $floor['name'], 'sort_order' => $floor['sort_order'],
                    ])->id;
                }
                foreach ($data['rooms'] as $room) {
                    app(RoomOrderingService::class)->create($house, [
                        'floor_id' => isset($room['floor_client_key']) ? $floorIds[$room['floor_client_key']] : null,
                        'room_code' => $room['room_code'],
                        'room_type' => $room['room_type'] ?? 'PRIVATE_ROOM',
                        'private_room_type' => $room['private_room_type'] ?? null,
                        'area' => $room['area'] ?? null,
                        'monthly_rent' => $room['monthly_rent'] ?? null,
                        'max_tenants' => $room['max_tenants'] ?? null,
                        'status' => 'AVAILABLE',
                    ]);
                }

                $result = ['id' => $house->id, 'rooms_count' => count($data['rooms'])];
                DB::table('house_creation_requests')->where('id', $receipt->id)->update([
                    'house_id' => $house->id, 'rooms_count' => $result['rooms_count'], 'updated_at' => now(),
                ]);

                return $result;
            });
        } catch (QueryException $exception) {
            if ($this->isRoomCodeUniqueConstraint($exception)) {
                throw ValidationException::withMessages([
                    'rooms' => 'Có mã phòng bị trùng. Vui lòng kiểm tra lại danh sách phòng.',
                ]);
            }

            throw $exception;
        }

        $count = count($data['rooms']);

        // Only return the generated ID needed to open the new house detail.
        return ApiResponse::success(
            $result,
            "Đã tạo nhà trọ và {$count} phòng thành công.",
            201,
        );
    }

    // tương tự như get nhưng theo id, Lấy chi tiết một bản ghi
    public function show(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success(
            $this->findOwnedBoardingHouse($request, $id),
            'Lấy thông tin nhà trọ thành công.'
        );
    }

    public function update(UpdateBoardingHouseRequest $request, int $id): JsonResponse
    {
        $boardingHouse = $this->findOwnedBoardingHouse($request, $id);
        $boardingHouse->update($request->validated());

        return ApiResponse::success(null, 'Cập nhật nhà trọ thành công.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->findOwnedBoardingHouse($request, $id)->delete();

        return ApiResponse::success(null, 'Xóa nhà trọ thành công.');
    }

    private function landlordBoardingHouses(Request $request)
    {
        // Intelephense không nhận ra kiểu dữ liệu của $user và $landlord, nên cần khai báo kiểu dữ liệu để Intelephense nhận ra
        /** @var User $user */
        $user = $request->user();

        /** @var Landlord $landlord */
        $landlord = $user->landlord;

        return $landlord->boardingHouses();
    }

    private function findOwnedBoardingHouse(Request $request, int $id): BoardingHouse
    {
        return $this->landlordBoardingHouses($request)->findOrFail($id);
    }

    private function isRoomCodeUniqueConstraint(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'rooms_boarding_house_id_room_code_unique')
            || str_contains($message, 'rooms.boarding_house_id, rooms.room_code');
    }

    private function canonicalPayload(array $payload): array
    {
        if (! array_is_list($payload)) {
            ksort($payload);
        }

        return array_map(fn ($value) => is_array($value) ? $this->canonicalPayload($value) : $value, $payload);
    }

    /**
     * Call inside a transaction, before creating any child records.
     * Raw queries intentionally include soft-deleted legacy rows.
     */
    private function createUncontaminatedHouse(Request $request, array $attributes): BoardingHouse
    {
        $house = $this->landlordBoardingHouses($request)->create($attributes);

        foreach (['rooms', 'services', 'floors', 'boarding_house_managers', 'boarding_house_residence_profiles', 'residence_declarations'] as $table) {
            if (DB::table($table)->where('boarding_house_id', $house->id)->exists()) {
                throw ValidationException::withMessages([
                    'name' => 'Không thể tạo nhà trọ vì mã nội bộ đang được dữ liệu cũ tham chiếu. Vui lòng liên hệ quản trị viên kiểm tra tính toàn vẹn dữ liệu trước khi thử lại.',
                ]);
            }
        }

        return $house;
    }
}
