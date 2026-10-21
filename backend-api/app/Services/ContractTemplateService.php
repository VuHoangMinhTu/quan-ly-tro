<?php

namespace App\Services;

use App\Models\ContractTemplate;
use App\Models\Landlord;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ContractTemplateService
{
    public function __construct(private ContractTemplateRenderer $renderer) {}

    public function visible(User $user): Builder
    {
        return ContractTemplate::query()->where(fn ($query) => $query->whereNull('landlord_id')
            ->orWhereIn('landlord_id', Landlord::query()->where('user_id', $user->id)->select('id')));
    }

    public function available(int $landlordId): Builder
    {
        return ContractTemplate::query()->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('landlord_id')->orWhere('landlord_id', $landlordId))
            ->orderByRaw('CASE WHEN landlord_id IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw("CASE WHEN template_key = 'ROOM_RENTAL_STANDARD' THEN 0 ELSE 1 END")
            ->orderByDesc('version')->orderBy('id');
    }

    public function select(int $landlordId, ?int $id, bool $lock = false): ContractTemplate
    {
        $query = ContractTemplate::query()->where(fn ($query) => $query->whereNull('landlord_id')->orWhere('landlord_id', $landlordId));
        $template = $id === null
            ? $this->available($landlordId)->when($lock, fn ($query) => $query->lockForUpdate())->first()
            : $query->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($id);
        if ($template === null || ! $template->is_active) {
            throw ValidationException::withMessages(['contract_template_id' => 'Vui lòng chọn một mẫu hợp đồng đang được sử dụng. Mẫu đã chọn không được tự thay thế.']);
        }

        return $template;
    }

    private function owner(User $user): Landlord
    {
        $owner = Landlord::query()->where('user_id', $user->id)->lockForUpdate()->first();
        abort_unless($owner, 403, 'Chỉ chủ trọ được quản lý mẫu hợp đồng.');

        return $owner;
    }

    public function create(User $user, array $data, ?int $sourceId = null): ContractTemplate
    {
        return DB::transaction(function () use ($user, $data, $sourceId): ContractTemplate {
            $owner = $this->owner($user);
            $source = $sourceId === null ? null : $this->visible($user)->lockForUpdate()->findOrFail($sourceId);
            $key = $source?->template_key ?? $data['template_key'];
            $version = (int) ContractTemplate::query()->where('landlord_id', $owner->id)->where('template_key', $key)->max('version') + 1;
            $template = ContractTemplate::create([
                'landlord_id' => $owner->id, 'scope_key' => 'LANDLORD:'.$owner->id, 'template_key' => $key,
                'name' => $data['name'] ?? ($source?->landlord_id === null ? $source->name.' - Bản tùy chỉnh' : $source?->name), 'version' => $version,
                'content_html' => $this->renderer->prepare($data['content_html'] ?? $source->content_html),
                'is_active' => false, 'created_by_user_id' => $user->id,
            ]);
            if ($data['is_active'] ?? false) {
                $this->activateVersion($template, true);
            }

            return $template;
        }, 3);
    }

    public function update(User $user, int $id, array $data): void
    {
        DB::transaction(function () use ($user, $id, $data): void {
            $owner = $this->owner($user);
            $template = ContractTemplate::query()->where('landlord_id', $owner->id)->lockForUpdate()->findOrFail($id);
            if (array_key_exists('template_key', $data) && $data['template_key'] !== $template->template_key) {
                throw ValidationException::withMessages(['template_key' => 'Không thể đổi mã của phiên bản mẫu. Vui lòng tạo mẫu mới.']);
            }
            $changes = Arr::only($data, ['name', 'content_html']);
            if (isset($changes['content_html'])) {
                $changes['content_html'] = $this->renderer->prepare($changes['content_html']);
            }
            $template->update($changes);
            if (array_key_exists('is_active', $data)) {
                $this->activateVersion($template, $data['is_active']);
            }
        }, 3);
    }

    public function status(User $user, int $id, bool $active): void
    {
        DB::transaction(function () use ($user, $id, $active): void {
            $owner = $this->owner($user);
            $template = ContractTemplate::query()->where('landlord_id', $owner->id)->lockForUpdate()->findOrFail($id);
            $this->activateVersion($template, $active);
        }, 3);
    }

    public function delete(User $user, int $id): void
    {
        DB::transaction(function () use ($user, $id): void {
            $owner = $this->owner($user);
            $template = ContractTemplate::query()->where('landlord_id', $owner->id)->lockForUpdate()->findOrFail($id);
            if ($template->documents()->exists() || $template->contracts()->exists()) {
                throw ValidationException::withMessages(['template' => 'Mẫu đã được dùng trong hợp đồng hoặc tài liệu. Hãy ngừng sử dụng thay vì xóa.']);
            }
            $template->delete();
        }, 3);
    }

    private function activateVersion(ContractTemplate $template, bool $active): void
    {
        if ($active) {
            ContractTemplate::query()->where('landlord_id', $template->landlord_id)->where('template_key', $template->template_key)
                ->whereKeyNot($template->id)->update(['is_active' => false]);
        }
        $template->update(['is_active' => $active]);
    }
}
