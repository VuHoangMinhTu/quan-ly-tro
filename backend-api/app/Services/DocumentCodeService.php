<?php

namespace App\Services;
use Illuminate\Support\Str;
use App\Models\Contract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DocumentCodeService
{
    public function prepare(Model $document): void
    {
        $contract = $document instanceof Contract;
        $field = $contract ? 'contract_code' : 'invoice_code';
        $label = $contract ? 'Mã hợp đồng' : 'Mã hóa đơn';
        $owner = DB::table('rooms')->join('boarding_houses', 'rooms.boarding_house_id', '=', 'boarding_houses.id')
            ->where('rooms.id', $document->room_id)->value('boarding_houses.landlord_id');
        if ($owner === null || ($document->exists && (int) $document->getOriginal('landlord_id') !== (int) $owner)) {
            throw ValidationException::withMessages(['room_id' => 'Không thể thay đổi chủ sở hữu chứng từ.']);
        }
        $document->landlord_id = $owner;
        if ($document->exists && ! $document->isDirty($field)) {
            return;
        }
        $code = trim((string) $document->{$field});
        if ($document->exists && $code === '') {
            $code = $document->getOriginal($field);
        }
        if ($document->exists && $code === $document->getOriginal($field)) {
            $document->{$field} = $code;

            return;
        }
        // The landlord lock serializes manual writers and sequence initialization.
        DB::table('landlords')->where('id', $owner)->lockForUpdate()->firstOrFail();
        if ($document->exists && $code !== $document->getOriginal($field)) {
            $persisted = DB::table($document->getTable())->where('id', $document->id)->lockForUpdate()->firstOrFail();
            $locked = $persisted->status !== 'DRAFT'
                || ($contract ? $persisted->finalized_at !== null
                    : $persisted->issued_at !== null || (float) $persisted->paid_amount > 0 || $document->payments()->exists());
            if ($locked) {
                throw ValidationException::withMessages([$field => "{$label} đã phát sinh không được thay đổi."]);
            }
            if ($persisted->{$field} !== $document->getOriginal($field)) {
                throw ValidationException::withMessages([$field => 'Mã đã được cập nhật. Vui lòng tải lại chứng từ.']);
            }
        }
        if ($code === '') {
            $code = $this->next(
                (int) $owner,
                $contract ? 'CONTRACT' : 'INVOICE',
                $document->getTable(),
                $field,
                $document
            );
        }
        if (DB::table($document->getTable())->where('landlord_id', $owner)->where($field, $code)
            ->when($document->exists, fn ($query) => $query->where('id', '!=', $document->id))->exists()) {
            throw ValidationException::withMessages([$field => "{$label} {$code} đã được sử dụng."]);
        }
        $document->{$field} = $code;
    }


    private function next(
        int $landlordId,
        string $type,
        string $table,
        string $field,
        Model $document
    ): string {
        // Lấy ngày ký nếu có; nếu không, dùng ngày tạo.
        $date = $document instanceof Contract
            ? ($document->signed_date ?? now())
            : now();

        $year = (int) $date->format('Y');

        $prefix = $type === 'CONTRACT'
            ? $this->contractPrefix(
                $document,
                $landlordId,
                $date->format('dmy')
            )
            : 'INV-' . $year;

        $key = [
            'landlord_id' => $landlordId,
            'document_type' => $type,
            'year' => $year,
        ];

        DB::table('document_sequences')->insertOrIgnore([
            ...$key,
            'last_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sequence = DB::table('document_sequences')
            ->where($key)
            ->lockForUpdate()
            ->first();

        $number = (int) $sequence->last_number;

        for ($attempt = 0; $attempt < 10000; $attempt++) {
            $number++;

            $code = $type === 'CONTRACT'
                ? $prefix . '-' . str_pad(
                    (string) $number,
                    3,
                    '0',
                    STR_PAD_LEFT
                )
                : 'INV-' . $year . '-' . str_pad(
                    (string) $number,
                    6,
                    '0',
                    STR_PAD_LEFT
                );

            if (!DB::table($table)
                ->where('landlord_id', $landlordId)
                ->where($field, $code)
                ->exists()) {

                DB::table('document_sequences')
                    ->where('id', $sequence->id)
                    ->update([
                        'last_number' => $number,
                        'updated_at' => now(),
                    ]);

                return $code;
            }
        }

        throw ValidationException::withMessages([
            $field => 'Chưa thể cấp mã tự động. Vui lòng thử lại.',
        ]);
    }

    private function contractPrefix(
        Model $document,
        int $landlordId,
        string $date
    ): string {
        $rawRoom = (string) DB::table('rooms')
            ->where('id', $document->room_id)
            ->value('room_code');

        $room = strtoupper((string) preg_replace(
            '/[^a-zA-Z0-9]+/',
            '',
            Str::ascii($rawRoom)
        ));

        $room = substr($room, 0, 30);

        if ($room === '') {
            $room = 'P' . $document->room_id;
        } elseif (!str_starts_with($room, 'P')) {
            $room = 'P' . $room;
        }

        $fullName = (string) DB::table('tenants')
            ->where('landlord_id', $landlordId)
            ->where('id', $document->tenant_id)
            ->value('full_name');

        $parts = preg_split(
            '/\s+/u',
            trim($fullName),
            -1,
            PREG_SPLIT_NO_EMPTY
        );

        $givenName = $parts ? (string) end($parts) : '';

        $shortName = strtolower((string) preg_replace(
            '/[^a-zA-Z0-9]+/',
            '',
            Str::ascii($givenName)
        ));

        $shortName = substr(
            $shortName !== '' ? $shortName : 'khach',
            0,
            24
        );

        return 'HD-' . $room . '-' . $shortName . '-' . $date;
    }
}
