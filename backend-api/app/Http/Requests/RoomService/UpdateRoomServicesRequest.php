<?php

namespace App\Http\Requests\RoomService;

use App\Models\Room;
use App\Models\Service;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class UpdateRoomServicesRequest extends FormRequest
{
    private Room $ownedRoom;

    public function authorize(): bool
    {
        $this->ownedRoom = Room::query()
            ->whereHas('boardingHouse', fn ($query) => $query->where('landlord_id', $this->user()->landlord->id))
            ->findOrFail($this->route('roomId'));

        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'service_ids' => ['present', 'array', 'list'],
            'service_ids.*' => ['integer', 'min:1'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->validateServices($this->ownedRoom, $this->input('service_ids'));
        }];
    }

    /** @param array<int, int|string> $serviceIds */
    public function validateServices(Room $room, array $serviceIds, bool $lockForUpdate = false): void
    {
        $services = Service::query()
            ->where('boarding_house_id', $room->boarding_house_id)
            ->where('is_active', true)
            ->whereIn('id', $serviceIds)
            ->orderBy('id')
            ->when($lockForUpdate, fn ($query) => $query->lockForUpdate())
            ->get();

        $availableIds = $services->modelKeys();
        $errors = [];

        foreach ($serviceIds as $index => $serviceId) {
            if (! in_array((int) $serviceId, $availableIds, true)) {
                $errors["service_ids.{$index}"] = 'Dịch vụ phải đang hoạt động và thuộc cùng nhà trọ với phòng.';
            }
        }

        foreach (['ELECTRICITY' => 'điện', 'WATER' => 'nước'] as $type => $label) {
            if ($services->where('type', $type)->count() > 1) {
                $errors['service_ids'][] = "Mỗi phòng chỉ được áp dụng một dịch vụ {$label} đang hoạt động.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
