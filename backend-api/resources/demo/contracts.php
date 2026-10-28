<?php

use App\Services\ContractHandoverService;

// Render-only examples: these values must never be saved as customer records.
$lessor = ['full_name' => 'Minh Tú', 'title' => 'Chủ trọ', 'phone' => '0983407941',
    'bank_name' => 'MB Bank', 'bank_account_name' => 'VU HOANG MINH TU', 'bank_account_number' => '0385528994'];
$tenant = ['full_name' => 'Bùi Quang Duy', 'date_of_birth' => '2000-01-01', 'gender' => 'MALE',
    'identity_number' => '052098006132', 'identity_issue_date' => '2020-01-01', 'identity_issue_place' => 'Dĩ An',
    'phone' => '0983407941', 'email' => 'tenant@example.invalid', 'permanent_address' => '15/3, đường Lê Quý Đôn, Dĩ An, Bình Dương',
    'occupation' => 'Freelancer', 'bank_name' => 'MB Bank', 'bank_account_number' => '0983407941',
    'emergency_name' => 'Người thân', 'emergency_phone' => '0983407941',
    'vehicle_type' => 'Không đăng ký', 'vehicle_plate' => 'Không áp dụng', 'vehicle_color' => 'Không áp dụng', 'vehicle_quantity' => '0'];
$services = [
    ['service_name' => 'Điện', 'service_type' => 'ELECTRICITY', 'billing_method' => 'PER_UNIT', 'base_price' => 3800, 'unit' => 'kWh'],
    ['service_name' => 'Nước', 'service_type' => 'WATER', 'billing_method' => 'PER_PERSON', 'base_price' => 100000, 'unit' => 'người/tháng'],
    ['service_name' => 'Phí dịch vụ', 'service_type' => 'SERVICE', 'billing_method' => 'FIXED', 'base_price' => 150000, 'unit' => 'tháng'],
];
$snapshot = ['schema_version' => 2, 'lessor' => $lessor, 'tenant' => $tenant,
    'payment' => ['bank_name' => $lessor['bank_name'], 'bank_account_name' => $lessor['bank_account_name'], 'bank_account_number' => $lessor['bank_account_number']],
    'services' => $services, 'vehicles' => [], 'electricity_opening' => '1000',
    'room' => ['room_code' => '101', 'room_name' => 'Phòng có gác', 'area' => 25, 'max_tenants' => 2,
        'room_type' => 'PRIVATE_ROOM', 'private_room_type' => 'LOFT', 'boarding_house_name' => 'Nhà trọ 115M',
        'address' => '15/3, đường Lê Quý Đôn, Dĩ An, Bình Dương', 'ward' => 'Dĩ An'],
    'contract' => ['contract_code' => 'DEMO-PHONG-101', 'contract_type' => 'ROOM_RENTAL', 'signed_date' => '2026-11-01',
        'start_date' => '2026-11-01', 'end_date' => '2027-10-31', 'monthly_rent' => 3000000, 'deposit_amount' => 3000000,
        'payment_due_day' => 5, 'note' => 'Thanh toán tiền thuê vào ngày 05 hàng tháng.'],
    'occupants' => [[...$tenant, 'is_primary' => true], [...$tenant, 'full_name' => 'Người ở cùng', 'identity_number' => '000000000000', 'is_primary' => false]],
    'handover_checkin' => array_map(fn (string $name) => ['name' => $name, 'quantity' => 1, 'condition' => 'GOOD',
        'note' => 'Hoạt động tốt', 'key_count' => $name === 'Khóa cửa' ? 2 : null, 'remote_count' => $name === 'Máy lạnh' ? 1 : null], ContractHandoverService::ROOM_RENTAL_ITEMS),
];

return [
    'room' => ['name' => 'Hợp đồng phòng trọ', 'filename' => 'BeeHouse_HopDong_PhongTro_DEMO.pdf', 'template' => 'contracts-room-rental-word.html',
        'summary' => 'Nhà trọ 115M · Phòng 101 · Có gác · 25 m² · 2 người · 12 tháng · 3.000.000đ/tháng · Cọc 3.000.000đ · Hạn trả ngày 05',
        'snapshot' => $snapshot,
        'paper_fields' => [
            '(Bằng chữ:' => [' Ba triệu đồng'],
            'Số lượng xe được để trong nhà là:' => [' 0 '],
            'Loại xe:' => [' Không đăng ký ', ' Không áp dụng ', ' Không áp dụng'],
            'Tiền phòng từ ' => ['01/11/2026 ', ' 30/11/2026 ', ' 3.000.000 '],
            'Tiền nước ban đầu khi ký hợp đồng:' => ['200.000 '],
            'Tiền dịch vụ ban đầu khi ký hợp đồng:' => ['150.000 '],
            'Tiền cọc phải đóng khi ký HĐ:' => ['3.000.000 '],
            'Tiền cọc đã đóng trước ' => ['0 '],
            'Tiền phòng đã đóng khi ký HĐ:' => ['3.000.000 '],
            'Tiền cọc đóng thêm khi ký HĐ:' => ['3.000.000 '],
            'Khoản tiền còn nợ:' => ['0 '],
            'Khách hàng cam kết thanh toán đủ khoản nợ vào ngày:' => ['Không áp dụng (minh họa đã thanh toán đủ)'],
        ]],
    'dorm' => ['name' => 'Hợp đồng KTX', 'filename' => 'BeeHouse_HopDong_KTX_DEMO.pdf', 'template' => 'contracts-dorm-bed-word.html',
        'checked_options' => ['Gói tính theo định mức/tiêu thụ:'],
        'summary' => 'Nhà trọ 115M · Phòng K201 · Giường G01 · Tầng 2 · 6 tháng · 1.500.000đ/giường/tháng · Cọc 1.500.000đ · Hạn trả ngày 05',
        'snapshot' => [...$snapshot,
            'tenant' => $tenant,
            'room' => [...$snapshot['room'], 'room_code' => 'K201', 'room_name' => 'Phòng KTX', 'max_tenants' => 6, 'room_type' => 'DORMITORY', 'private_room_type' => null],
            'bed' => ['bed_code' => 'G01', 'floor_label' => 'Tầng 2', 'monthly_rent' => 1500000],
            'contract' => [...$snapshot['contract'], 'contract_code' => 'DEMO-KTX-G01', 'contract_type' => 'DORM_BED', 'end_date' => '2027-04-30', 'monthly_rent' => 1500000, 'deposit_amount' => 1500000],
            'occupants' => [[...$tenant, 'is_primary' => true]],
            'handover_checkin' => array_map(fn (string $name) => ['name' => $name, 'quantity' => 1, 'condition' => 'GOOD', 'note' => 'Hoạt động tốt'], ContractHandoverService::DORM_BED_ITEMS)],
        'paper_fields' => [
            '{{boarding_house.ward}}, ngày' => [' 01 ', ' 11 ', ' 2026'],
            'Thời hạn: Thời hạn thuê là ' => ['6'],
            'Tiền điện (' => ['3.800'], 'Tiền nước (' => ['100.000'], 'Phí dịch vụ & rác (' => ['150.000'], 'Phí giữ xe (' => ['0'],
            '4. Kỳ thanh toán đầu:' => ['01/11/2026', '30/11/2026', '1.500.000'],
            '3. Hiệu lực: Hợp đồng gồm có ' => ['06'],
            '1. Tiền thuê giường kỳ đầu (' => ['01/11/2026', '30/11/2026', '1.500.000', '1.500.000', '0', '3.000.000'],
        ]],
];
