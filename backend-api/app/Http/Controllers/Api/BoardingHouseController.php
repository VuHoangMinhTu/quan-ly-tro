<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\BoardingHouse\StoreBoardingHouseRequest;
use App\Http\Requests\BoardingHouse\UpdateBoardingHouseRequest;
use App\Models\BoardingHouse;
use App\Models\Landlord;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        $boardingHouse = $this->landlordBoardingHouses($request)
            ->create($request->validated());

        return ApiResponse::success(null, 'Tạo nhà trọ thành công.', 201);
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
}
