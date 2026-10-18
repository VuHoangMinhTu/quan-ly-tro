<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class ContractTemplateVariableRegistry
{
    public function definitions(): array
    {
        $groups = [
            'Hợp đồng' => ['contract.code' => 'Mã hợp đồng', 'contract.contract_code' => 'Mã hợp đồng (tên field)', 'contract.start_date' => 'Ngày bắt đầu', 'contract.end_date' => 'Ngày kết thúc', 'contract.signed_date' => 'Ngày ký đã khai báo', 'contract.monthly_rent' => 'Giá thuê hàng tháng', 'contract.deposit' => 'Tiền cọc thỏa thuận', 'contract.deposit_amount' => 'Tiền cọc (tên field)', 'contract.payment_due_day' => 'Ngày thanh toán hàng tháng'],
            'Người thuê' => ['tenant.full_name' => 'Họ tên', 'tenant.date_of_birth' => 'Ngày sinh', 'tenant.gender' => 'Giới tính', 'tenant.identity_number' => 'Số định danh', 'tenant.identity_issue_date' => 'Ngày cấp', 'tenant.identity_issued_date' => 'Ngày cấp (bí danh)', 'tenant.identity_issue_place' => 'Nơi cấp', 'tenant.identity_issued_place' => 'Nơi cấp (bí danh)', 'tenant.phone' => 'Số điện thoại', 'tenant.email' => 'Email', 'tenant.permanent_address' => 'Địa chỉ thường trú'],
            'Phòng' => ['room.room_code' => 'Mã phòng', 'room.room_name' => 'Tên phòng', 'room.area' => 'Diện tích', 'room.max_tenants' => 'Số người tối đa'],
            'Nhà trọ' => ['boarding_house.name' => 'Tên nhà trọ', 'boarding_house.address' => 'Địa chỉ nhà trọ'],
            'Bên cho thuê' => ['lessor.full_name' => 'Người đại diện', 'lessor.title' => 'Chức vụ', 'lessor.phone' => 'Điện thoại bên A'],
            'Danh sách' => ['services_table' => 'Bảng dịch vụ thỏa thuận', 'occupants_table' => 'Danh sách người ở'],
        ];
        $result = [];
        foreach ($groups as $group => $items) {
            foreach ($items as $key => $label) {
                $result[] = ['key' => $key, 'placeholder' => '{{'.$key.'}}', 'label' => $label, 'group' => $group];
            }
        }

        return $result;
    }

    public function date(mixed $value): string
    {
        if (! $value) {
            return 'Chưa cập nhật';
        }
        $date = explode('T', (string) $value)[0];
        $parts = explode('-', $date);

        return count($parts) === 3 ? $parts[2].'/'.$parts[1].'/'.$parts[0] : 'Chưa cập nhật';
    }

    public function number(mixed $value, bool $money = false): string
    {
        if ($value === null || $value === '') {
            return 'Chưa cập nhật';
        }
        $decimal = BigDecimal::of((string) $value);
        $text = $money ? (string) $decimal->toScale(0, RoundingMode::HalfUp) : (string) $decimal->strippedOfTrailingZeros();
        [$whole, $fraction] = array_pad(explode('.', $text), 2, null);
        $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $whole);

        return $whole.($fraction === null ? '' : ','.$fraction).($money ? ' đồng' : '');
    }

    public function values(array $snapshot): array
    {
        $contract = $snapshot['contract'] ?? [];
        $tenant = $snapshot['tenant'] ?? [];
        $room = $snapshot['room'] ?? [];
        $values = [
            'contract.code' => $contract['contract_code'] ?? null,
            'contract.monthly_rent' => $this->number($contract['monthly_rent'] ?? null, true),
            'contract.deposit' => $this->number($contract['deposit_amount'] ?? null, true),
            'contract.payment_due_day' => isset($contract['payment_due_day']) ? str_pad((string) $contract['payment_due_day'], 2, '0', STR_PAD_LEFT) : null,
            'room.room_code' => $room['room_code'] ?? null, 'room.room_name' => $room['room_name'] ?? null,
            'room.area' => isset($room['area']) ? $this->number($room['area']).' m²' : null,
            'room.max_tenants' => $room['max_tenants'] ?? null,
            'boarding_house.name' => $room['boarding_house_name'] ?? null, 'boarding_house.address' => $room['address'] ?? null,
        ];
        foreach (['start_date', 'end_date', 'signed_date'] as $key) {
            $values['contract.'.$key] = $this->date($contract[$key] ?? null);
        }
        foreach (['full_name', 'identity_number', 'identity_issue_place', 'phone', 'email', 'permanent_address'] as $key) {
            $values['tenant.'.$key] = $tenant[$key] ?? null;
        }
        $values['tenant.gender'] = ['MALE' => 'Nam', 'FEMALE' => 'Nữ', 'OTHER' => 'Khác'][$tenant['gender'] ?? ''] ?? null;
        $values['tenant.date_of_birth'] = $this->date($tenant['date_of_birth'] ?? null);
        $values['tenant.identity_issue_date'] = $this->date($tenant['identity_issue_date'] ?? null);
        foreach (['full_name', 'title', 'phone'] as $key) {
            $values['lessor.'.$key] = $snapshot['lessor'][$key] ?? null;
        }
        $values['contract.contract_code'] = $values['contract.code'];
        $values['contract.deposit_amount'] = $values['contract.deposit'];
        $values['tenant.identity_issued_date'] = $values['tenant.identity_issue_date'];
        $values['tenant.identity_issued_place'] = $values['tenant.identity_issue_place'];

        return array_map(fn ($value) => $value === null || $value === '' ? 'Chưa cập nhật' : (string) $value, $values);
    }

    public function sample(): array
    {
        return [
            'contract' => ['contract_code' => 'MINH-HOA-001', 'start_date' => '2026-11-01', 'end_date' => '2027-10-31', 'monthly_rent' => '3800000.00', 'deposit_amount' => '7600000.00', 'payment_due_day' => 5],
            'tenant' => ['full_name' => 'Người thuê minh họa', 'phone' => 'Số điện thoại minh họa'],
            'lessor' => ['full_name' => 'Đại diện minh họa', 'title' => 'Chủ trọ'],
            'room' => ['room_code' => '101', 'area' => '20.50', 'boarding_house_name' => 'Nhà trọ minh họa', 'address' => 'Địa chỉ minh họa'],
            'services' => [], 'occupants' => [],
        ];
    }
}
