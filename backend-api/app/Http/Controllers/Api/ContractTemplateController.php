<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contract\ContractTemplateStatusRequest;
use App\Http\Requests\Contract\PreviewContractTemplateRequest;
use App\Http\Requests\Contract\SaveContractTemplateRequest;
use App\Services\BoardingHouseAccessService;
use App\Services\ContractTemplateRenderer;
use App\Services\ContractTemplateService;
use App\Services\ContractTemplateVariableRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContractTemplateController extends Controller
{
    public function __construct(private ContractTemplateService $templates, private ContractTemplateVariableRegistry $variables) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success([
            'templates' => $this->templates->visible($request->user())->select(['id', 'landlord_id', 'name', 'template_key', 'version', 'is_active', 'created_at'])->withCount(['documents' => fn ($query) => $this->scopedDocuments($query, $request)])->orderBy('template_key')->orderByDesc('version')->get(),
            'variables' => $this->variables->definitions(), 'can_manage' => $request->user()->landlord()->exists(),
        ], 'Lấy danh sách mẫu hợp đồng thành công.');
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->templates->visible($request->user())->withCount(['documents' => fn ($query) => $this->scopedDocuments($query, $request)])->findOrFail($id), 'Lấy nội dung mẫu hợp đồng thành công.');
    }

    public function store(SaveContractTemplateRequest $request): JsonResponse
    {
        $template = $this->templates->create($request->user(), $request->validated());

        return ApiResponse::success(['id' => $template->id], 'Tạo mẫu hợp đồng thành công.', 201);
    }

    public function update(SaveContractTemplateRequest $request, int $id): JsonResponse
    {
        $this->templates->update($request->user(), $id, $request->validated());

        return ApiResponse::success(null, 'Cập nhật mẫu hợp đồng thành công.');
    }

    public function version(SaveContractTemplateRequest $request, int $id): JsonResponse
    {
        $template = $this->templates->create($request->user(), $request->validated(), $id);

        return ApiResponse::success(['id' => $template->id], 'Tạo phiên bản mẫu mới thành công.', 201);
    }

    public function status(ContractTemplateStatusRequest $request, int $id): JsonResponse
    {
        $this->templates->status($request->user(), $id, $request->boolean('is_active'));

        return ApiResponse::success(null, 'Cập nhật trạng thái mẫu hợp đồng thành công.');
    }

    public function preview(PreviewContractTemplateRequest $request, ContractTemplateRenderer $renderer): JsonResponse
    {
        return ApiResponse::success(['rendered_html' => $renderer->render($request->validated('content_html'), $this->variables->sample())], 'Xem trước mẫu hợp đồng thành công.');
    }

    private function scopedDocuments($query, Request $request): void
    {
        $houses = app(BoardingHouseAccessService::class)->houses($request->user())->select('boarding_houses.id');
        $query->whereHas('contract.room', fn ($room) => $room->whereIn('boarding_house_id', $houses));
    }
}
