<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\UtilityReading\StoreUtilityReadingRequest;
use App\Http\Requests\UtilityReading\UpdateUtilityReadingRequest;
use App\Models\UtilityMeter;
use App\Models\UtilityReading;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UtilityReadingController extends Controller
{
    public function index(Request $request, int $meterId): JsonResponse
    {
        return ApiResponse::success($this->meter($request, $meterId)->readings()->get(), 'Utility readings retrieved successfully.');
    }

    public function store(StoreUtilityReadingRequest $request, int $meterId): JsonResponse
    {
        $meter = $this->meter($request, $meterId);
        $data = $request->validated();
        $this->validateSequence($meter, $data);
        $meter->readings()->create($data);

        return ApiResponse::success(null, 'Utility reading created successfully.', 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->reading($request, $id)->load('meter'), 'Utility reading retrieved successfully.');
    }

    public function update(UpdateUtilityReadingRequest $request, int $id): JsonResponse
    {
        $reading = $this->reading($request, $id);
        $data = $request->validated();
        $this->validateSequence($reading->meter, $data, $reading->id);
        $reading->update($data);

        return ApiResponse::success(null, 'Utility reading updated successfully.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->reading($request, $id)->delete();

        return ApiResponse::success(null, 'Utility reading deleted successfully.');
    }

    private function meter(Request $request, int $id): UtilityMeter
    {
        return UtilityMeter::whereHas('room.boardingHouse', fn ($query) => $query->where('landlord_id', $request->user()->landlord->id))->findOrFail($id);
    }

    private function reading(Request $request, int $id): UtilityReading
    {
        return UtilityReading::whereHas('meter.room.boardingHouse', fn ($query) => $query->where('landlord_id', $request->user()->landlord->id))->findOrFail($id);
    }

    private function validateSequence(UtilityMeter $meter, array $data, ?int $ignoreId = null): void
    {
        $readings = $meter->readings()->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId));
        if ($readings->clone()->whereDate('reading_date', $data['reading_date'])->exists()) {
            throw ValidationException::withMessages(['reading_date' => 'A reading already exists for this date.']);
        }
        $previous = $readings->clone()->where('reading_date', '<', $data['reading_date'])->orderByDesc('reading_date')->first();
        $next = $readings->clone()->where('reading_date', '>', $data['reading_date'])->orderBy('reading_date')->first();
        if ($previous && $data['reading_value'] < $previous->reading_value) {
            throw ValidationException::withMessages(['reading_value' => 'The reading value cannot be lower than the previous reading.']);
        }
        if (! $previous && $data['reading_value'] < $meter->initial_reading) {
            throw ValidationException::withMessages(['reading_value' => 'The first reading cannot be lower than the initial reading.']);
        }
        if ($next && $data['reading_value'] > $next->reading_value) {
            throw ValidationException::withMessages(['reading_value' => 'The reading value cannot exceed the next reading.']);
        }
    }
}
