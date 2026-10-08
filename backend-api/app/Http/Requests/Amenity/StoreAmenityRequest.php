<?php

namespace App\Http\Requests\Amenity;

use App\Models\Amenity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAmenityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $normalizedName = mb_strtolower(trim((string) $value));
                    $exists = Amenity::query()->pluck('name')->contains(
                        fn (string $name) => mb_strtolower(trim($name)) === $normalizedName,
                    );

                    if ($exists) {
                        $fail('Tiện nghi này đã tồn tại trong danh mục.');
                    }
                },
                Rule::unique('amenities', 'name'),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
        ]);
    }
}
