<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Residence\AssignBoardingHouseManagersRequest;
use App\Models\User;
use App\Services\BoardingHouseAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BoardingHouseManagerController extends Controller
{
    public function __construct(private BoardingHouseAccessService $access) {}

    public function index(Request $request, int $id): JsonResponse
    {
        $house = $this->access->house($request->user(), $id);
        abort_unless($this->access->isOwner($request->user(), $house), 403);

        return ApiResponse::success($house->managers()->get(['users.id', 'users.name', 'users.email']), 'Lấy danh sách quản lý thành công.');
    }

    public function update(AssignBoardingHouseManagersRequest $request, int $id): JsonResponse
    {
        DB::transaction(function () use ($request, $id): void {
            $house = $this->access->house($request->user(), $id, true);
            abort_unless($this->access->isOwner($request->user(), $house), 403);
            $users = User::query()->whereIn('email', $request->validated('manager_emails'))->get();
            if ($users->contains('id', $request->user()->id)) {
                throw ValidationException::withMessages(['manager_emails' => 'Chủ workspace đã có quyền OWNER, không cần phân công làm MANAGER.']);
            }
            $house->managers()->sync($users->modelKeys());
        }, 3);

        return ApiResponse::success(null, 'Cập nhật phân công quản lý thành công.');
    }
}
