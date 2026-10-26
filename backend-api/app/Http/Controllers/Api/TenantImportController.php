<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\TenantImport;
use App\Services\TenantImportService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TenantImportController extends Controller
{
    public function template(Request $request, TenantImportService $imports): BinaryFileResponse
    {
        $imports->owner($request->user());

        return response()->download(resource_path('templates/tenant-import.xlsx'), 'BeeHouse-mau-nguoi-thue.xlsx', ['Cache-Control' => 'private, no-store']);
    }

    public function preview(Request $request, TenantImportService $imports): JsonResponse
    {
        $imports->owner($request->user());
        $request->validate(['file' => ['required', 'file', 'max:5120', 'extensions:xlsx', 'mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip,application/octet-stream']], [
            'file.required' => 'Vui lòng chọn file Excel.', 'file.uploaded' => 'Upload file thất bại. Vui lòng kiểm tra giới hạn upload của máy chủ.',
            'file.max' => 'File Excel vượt quá dung lượng cho phép (5 MB).',
            'file.extensions' => 'Chỉ chấp nhận file Excel .xlsx.', 'file.mimetypes' => 'File không phải định dạng Excel .xlsx hợp lệ.',
        ]);
        if (! class_exists(\ZipArchive::class)) {
            return ApiResponse::error('Máy chủ cần bật PHP ZIP để đọc file Excel. Vui lòng liên hệ quản trị.', null, 503);
        }

        try {
            $data = $imports->preview($request->user(), $request->file('file'));
        } catch (QueryException) {
            throw ValidationException::withMessages(['import' => 'Chưa thể kiểm tra dữ liệu người thuê. Vui lòng thử lại.']);
        }

        return $this->privateResponse($data, 'Đã kiểm tra file. Vui lòng xem trước và chọn các dòng cần nhập.');
    }

    public function confirm(Request $request, TenantImportService $imports): JsonResponse
    {
        $imports->owner($request->user());
        $data = $request->validate([
            'token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'selected_rows' => ['required', 'array', 'min:1', 'max:1000'],
            'selected_rows.*' => ['required', 'integer', 'distinct', 'between:2,1001'],
            'accept_phone_warnings' => ['sometimes', 'boolean'],
        ]);
        try {
            $result = $imports->confirm($request->user(), $data['token'], array_map('intval', $data['selected_rows']), $data['accept_phone_warnings'] ?? false);
        } catch (QueryException) {
            throw ValidationException::withMessages(['import' => 'Chưa thể nhập dữ liệu. Toàn bộ lượt nhập đã được hoàn tác; vui lòng thử lại.']);
        }

        return $this->privateResponse($result, "Đã thêm thành công {$result['created']} người thuê. Bỏ qua {$result['duplicates']} người trùng và {$result['invalid']} dòng lỗi. {$result['unselected']} dòng chưa được chọn.");
    }

    public function cancel(Request $request, TenantImportService $imports): JsonResponse
    {
        $owner = $imports->owner($request->user());
        $data = $request->validate(['token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D']]);
        TenantImport::query()->whereKey(hash('sha256', $data['token']))->where('user_id', $request->user()->id)
            ->where('landlord_id', $owner->id)->whereNull('result')->delete();

        return $this->privateResponse(null, 'Đã xóa phiên xem trước.');
    }

    private function privateResponse(?array $data, string $message): JsonResponse
    {
        return ApiResponse::success($data, $message)->header('Cache-Control', 'private, no-store');
    }
}
