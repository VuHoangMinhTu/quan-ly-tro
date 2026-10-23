<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class ContractTemplateVariableRegistry
{
    public function definitions(): array
    {
        $groups = [
            'Hợp đồng' => ['contract.code' => 'Mã hợp đồng', 'contract.contract_code' => 'Mã hợp đồng (tên field)', 'contract.start_date' => 'Ngày bắt đầu', 'contract.end_date' => 'Ngày kết thúc', 'contract.signed_date' => 'Ngày ký đã khai báo', 'contract.document_day' => 'Ngày lập hợp đồng', 'contract.document_month' => 'Tháng lập hợp đồng', 'contract.document_year' => 'Năm lập hợp đồng', 'contract.duration_months' => 'Thời hạn hợp đồng (tháng tròn)', 'contract.monthly_rent' => 'Giá thuê hàng tháng', 'contract.monthly_rent_value' => 'Giá thuê dạng số, không kèm đơn vị', 'contract.deposit' => 'Tiền cọc thỏa thuận', 'contract.deposit_amount' => 'Tiền cọc (tên field)', 'contract.deposit_value' => 'Tiền cọc dạng số, không kèm đơn vị', 'contract.payment_due_day' => 'Ngày thanh toán hàng tháng', 'contract.note' => 'Ghi chú', 'contract.electricity_opening' => 'Chỉ số điện ban đầu'],
            'Người thuê' => ['tenant.full_name' => 'Họ tên', 'tenant.date_of_birth' => 'Ngày sinh', 'tenant.gender' => 'Giới tính', 'tenant.gender_checkboxes' => 'Giới tính dạng ô chọn Nam/Nữ', 'tenant.identity_number' => 'Số định danh', 'tenant.identity_issue_date' => 'Ngày cấp', 'tenant.identity_issued_date' => 'Ngày cấp (bí danh)', 'tenant.identity_issue_place' => 'Nơi cấp', 'tenant.identity_issued_place' => 'Nơi cấp (bí danh)', 'tenant.occupation' => 'Nghề nghiệp', 'tenant.phone' => 'Số điện thoại', 'tenant.email' => 'Email', 'tenant.permanent_address' => 'Địa chỉ thường trú', 'tenant.bank_name' => 'Ngân hàng Bên B', 'tenant.bank_account_number' => 'Số tài khoản Bên B', 'tenant.emergency_name' => 'Người thân liên hệ khẩn cấp', 'tenant.emergency_phone' => 'Điện thoại khẩn cấp', 'tenant.vehicle_type' => 'Loại xe', 'tenant.vehicle_plate' => 'Biển số xe', 'tenant.vehicle_color' => 'Màu xe', 'tenant.vehicle_quantity' => 'Số lượng xe'],
            'Phòng' => ['room.room_code' => 'Mã phòng', 'room.room_name' => 'Tên phòng', 'room.area' => 'Diện tích', 'room.max_tenants' => 'Số người tối đa', 'room.occupants_count' => 'Số người đăng ký ở'],
            'Nhà trọ' => ['boarding_house.name' => 'Tên nhà trọ', 'boarding_house.address' => 'Địa chỉ nhà trọ', 'boarding_house.ward' => 'Phường/Xã'],
            'Bên cho thuê' => ['lessor.full_name' => 'Người đại diện', 'lessor.title' => 'Chức vụ', 'lessor.phone' => 'Điện thoại bên A', 'lessor.bank_name' => 'Ngân hàng nhận tiền', 'lessor.bank_account_name' => 'Chủ tài khoản', 'lessor.bank_account_number' => 'Số tài khoản'],
            'Tài khoản nhận tiền' => ['payment.bank_name' => 'Ngân hàng', 'payment.account_name' => 'Tên chủ tài khoản', 'payment.account_number' => 'Số tài khoản'],
            'Giường' => ['bed.bed_code' => 'Mã giường', 'bed.floor_label' => 'Tầng'],
            'Danh sách' => ['services_table' => 'Bảng dịch vụ thỏa thuận', 'room_rental_services' => 'Dịch vụ Điều 4 hợp đồng phòng trọ', 'occupants_table' => 'Danh sách người ở', 'vehicles_table' => 'Danh sách phương tiện', 'handover_checkin_table' => 'Biên bản bàn giao'],
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
            'contract.monthly_rent_value' => $this->number($contract['monthly_rent'] ?? null),
            'contract.deposit' => $this->number($contract['deposit_amount'] ?? null, true),
            'contract.deposit_value' => $this->number($contract['deposit_amount'] ?? null),
            'contract.payment_due_day' => isset($contract['payment_due_day']) ? 'ngày '.str_pad((string) $contract['payment_due_day'], 2, '0', STR_PAD_LEFT).' hàng tháng' : null,
            'room.room_code' => $room['room_code'] ?? null, 'room.room_name' => $room['room_name'] ?? null,
            'room.area' => isset($room['area']) ? $this->number($room['area']).' m²' : null,
            'room.max_tenants' => $room['max_tenants'] ?? null,
            'room.occupants_count' => count($snapshot['occupants'] ?? []),
            'boarding_house.name' => $room['boarding_house_name'] ?? null, 'boarding_house.address' => $room['address'] ?? null,
            'boarding_house.ward' => $room['ward'] ?? null,
            'bed.bed_code' => $snapshot['bed']['bed_code'] ?? null, 'bed.floor_label' => $snapshot['bed']['floor_label'] ?? null,
            'contract.note' => $contract['note'] ?? null,
            'contract.electricity_opening' => $snapshot['electricity_opening'] ?? null,
        ];
        foreach (['start_date', 'end_date', 'signed_date'] as $key) {
            $values['contract.'.$key] = $this->date($contract[$key] ?? null);
        }
        $signedDate = explode('T', (string) ($contract['signed_date'] ?? ''))[0];
        $signedParts = preg_match('/^\d{4}-\d{2}-\d{2}$/', $signedDate) ? explode('-', $signedDate) : [];
        $values['contract.document_day'] = $signedParts[2] ?? null;
        $values['contract.document_month'] = $signedParts[1] ?? null;
        $values['contract.document_year'] = $signedParts[0] ?? null;
        $start = explode('T', (string) ($contract['start_date'] ?? ''))[0];
        $end = explode('T', (string) ($contract['end_date'] ?? ''))[0];
        $values['contract.duration_months'] = null;
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $start, $from) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $end, $to)) {
            $months = ((int) $to[1] - (int) $from[1]) * 12 + (int) $to[2] - (int) $from[2];
            $startDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $start);
            $inclusiveEnd = $startDate === false ? null : $startDate->modify('+'.($months + 1).' months')->modify('-1 day')->format('Y-m-d');
            $duration = $from[3] === $to[3] ? $months : ($inclusiveEnd === $end ? $months + 1 : null);
            $values['contract.duration_months'] = $duration > 0 ? (string) $duration : null;
        }
        foreach (['full_name', 'identity_number', 'identity_issue_place', 'occupation', 'phone', 'email', 'permanent_address', 'bank_name', 'bank_account_number', 'emergency_name', 'emergency_phone', 'vehicle_type', 'vehicle_plate', 'vehicle_color', 'vehicle_quantity'] as $key) {
            $values['tenant.'.$key] = $tenant[$key] ?? null;
        }
        $values['tenant.gender'] = ['MALE' => 'Nam', 'FEMALE' => 'Nữ', 'OTHER' => 'Khác'][$tenant['gender'] ?? ''] ?? null;
        $values['tenant.gender_checkboxes'] = match ($tenant['gender'] ?? null) {
            'MALE' => '☒ Nam ☐ Nữ',
            'FEMALE' => '☐ Nam ☒ Nữ',
            default => '☐ Nam ☐ Nữ',
        };
        $values['tenant.date_of_birth'] = $this->date($tenant['date_of_birth'] ?? null);
        $values['tenant.identity_issue_date'] = $this->date($tenant['identity_issue_date'] ?? null);
        foreach (['full_name', 'title', 'phone', 'bank_name', 'bank_account_name', 'bank_account_number'] as $key) {
            $values['lessor.'.$key] = $snapshot['lessor'][$key] ?? null;
        }
        $payment = $snapshot['payment'] ?? [];
        $values['payment.bank_name'] = $payment['bank_name'] ?? null;
        $values['payment.account_name'] = $payment['bank_account_name'] ?? null;
        $values['payment.account_number'] = $payment['bank_account_number'] ?? null;
        $values['contract.contract_code'] = $values['contract.code'];
        $values['contract.deposit_amount'] = $values['contract.deposit'];
        $values['tenant.identity_issued_date'] = $values['tenant.identity_issue_date'];
        $values['tenant.identity_issued_place'] = $values['tenant.identity_issue_place'];

        foreach ($values as $key => $value) {
            $values[$key] = $value === null || $value === ''
                ? (str_starts_with($key, 'payment.') || str_starts_with($key, 'lessor.bank_') ? '……………………' : 'Chưa cập nhật')
                : (string) $value;
        }

        return $values;
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
