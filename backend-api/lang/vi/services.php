<?php

return [
    'types' => [
        'ELECTRICITY' => 'Điện', 'WATER' => 'Nước', 'INTERNET' => 'Internet',
        'PARKING' => 'Giữ xe', 'TRASH' => 'Rác', 'CLEANING' => 'Vệ sinh', 'OTHER' => 'Khác',
    ],
    'billing_methods' => [
        'FIXED' => 'cố định', 'PER_UNIT' => 'theo đơn vị',
        'PER_PERSON' => 'theo người', 'TIERED' => 'bậc thang',
    ],
    'missing_meter' => 'Dịch vụ :type đang tính :method nhưng phòng chưa có đồng hồ đang hoạt động cho dịch vụ này.',
    'missing_reading' => 'Dịch vụ :type chưa có chỉ số công tơ trong hoặc trước kỳ hóa đơn này. Vui lòng ghi chỉ số.',
];
