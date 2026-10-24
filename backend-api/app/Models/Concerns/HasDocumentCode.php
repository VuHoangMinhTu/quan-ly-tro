<?php

namespace App\Models\Concerns;

use App\Models\Contract;
use App\Services\DocumentCodeService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

trait HasDocumentCode
{
    public function save(array $options = []): bool
    {
        try {
            return DB::transaction(function () use ($options) {
                app(DocumentCodeService::class)->prepare($this);

                return parent::save($options);
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $field = $this instanceof Contract ? 'contract_code' : 'invoice_code';
            if (! str_contains($exception->getMessage(), $field)) {
                throw $exception;
            }
            throw ValidationException::withMessages([$field => $field === 'contract_code' ? 'Mã hợp đồng này đã được sử dụng.' : 'Mã hóa đơn này đã được sử dụng.']);
        }
    }
}
