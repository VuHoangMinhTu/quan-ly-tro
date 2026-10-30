<?php

// Only synthetic display data. Saved contracts must use their own source snapshot.
$snapshot = [
    'lessor' => ['full_name' => 'Chủ trọ minh họa', 'title' => 'Chủ trọ', 'phone' => '0000000000', 'bank_name' => 'Ngân hàng minh họa', 'bank_account_name' => 'CHU TRO MINH HOA', 'bank_account_number' => '000000000000'],
    'tenant' => ['full_name' => 'Người thuê minh họa', 'date_of_birth' => '2000-01-01', 'identity_number' => '000000000000', 'identity_issue_date' => '2020-01-01', 'identity_issue_place' => 'Cơ quan minh họa', 'permanent_address' => 'Địa chỉ minh họa', 'phone' => '0000000000', 'occupation' => 'Nghề nghiệp minh họa', 'bank_name' => 'Ngân hàng minh họa', 'bank_account_number' => '000000000000', 'gender' => 'MALE', 'emergency_name' => 'Người thân minh họa', 'emergency_phone' => '0000000000', 'vehicle_type' => 'Không đăng ký', 'vehicle_plate' => '', 'vehicle_color' => '', 'vehicle_quantity' => 0],
    'room' => ['room_code' => 'P101', 'boarding_house_name' => 'Nhà trọ minh họa', 'address' => 'Địa chỉ nhà trọ minh họa', 'ward' => 'Phường minh họa', 'area' => 20, 'max_tenants' => 2],
    'bed' => ['bed_code' => 'G01', 'floor_label' => 'Tầng 1'],
    'contract' => ['contract_code' => 'HD-MINH-HOA', 'contract_type' => 'ROOM_RENTAL', 'signed_date' => '2026-01-01', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'monthly_rent' => 3000000, 'deposit_amount' => 1000000, 'payment_due_day' => 5, 'note' => ''],
    'electricity_opening' => 0,
];

return [
    'room' => ['name' => 'Hợp đồng thuê phòng trọ — DEMO', 'filename' => 'BeeHouse_HopDong_PhongTro_DEMO.pdf', 'template' => 'contracts-room-demo.html', 'summary' => 'Bản trải nghiệm, không phải hợp đồng đầy đủ.', 'snapshot' => $snapshot, 'paper_fields' => []],
    'dorm' => ['name' => 'Hợp đồng KTX — DEMO', 'filename' => 'BeeHouse_HopDong_KTX_DEMO.pdf', 'template' => 'contracts-dorm-demo.html', 'summary' => 'Bản trải nghiệm, không phải hợp đồng đầy đủ.', 'snapshot' => [...$snapshot, 'contract' => [...$snapshot['contract'], 'contract_type' => 'DORM_BED', 'monthly_rent' => 1500000]], 'paper_fields' => []],
];
