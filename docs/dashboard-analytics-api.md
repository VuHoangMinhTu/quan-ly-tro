# BeeHouse Dashboard · Kiểm toán dữ liệu và đề xuất API

## Quy ước triển khai

Bản release giảm phạm vi mặc định trả `availability.cash=unsupported` và bỏ `cash_received` khỏi totals, series và kỳ trước. Không chạy Payment aggregate trong hai endpoint mới khi tắt. Dashboard mới tải danh sách qua `GET /api/dashboard?include_revenue=0`; client cũ bỏ tham số vẫn nhận hợp đồng cũ. Chỉ bật lại `DASHBOARD_CASH_SERIES_ENABLED` sau khi đạt các điều kiện trong [kế hoạch Payment Timezone Fix](payment-timezone-fix-plan.md). Các ví dụ cash bên dưới mô tả hợp đồng khi capability được bật.

- Hai endpoint dùng guard Sanctum riêng tắt cập nhật `last_used_at`; API cũ dùng guard hiện tại. GET chỉ đọc.
- Summary trả `contracts.expiry_buckets` gồm `from_day,to_day,count` cho 0–7, 8–14, 15–21 và 22–30 ngày. Ngày ghi trong hợp đồng không phải ngày chuyển đi.
- Payment MySQL là DATETIME. Form Request nhận ngày hợp lệ, PaymentController lưu qua cast Eloquent datetime; PayOSService parse timestamp rồi lưu bằng Eloquent, nguồn PAYOS_WEBHOOK, còn migration mặc định MANUAL. Không có điều kiện trạng thái Payment đủ căn cứ áp dụng cho toàn bộ lịch sử. Fixture dùng datetime không offset. Analytics diễn giải giờ lưu theo `app.timezone` (APP_TIMEZONE, mặc định UTC), sau đó đặt biên kỳ theo giờ Việt Nam; đây là quy ước đọc, không xác nhận mọi timestamp legacy có cùng timezone. Kiểm tra APP_TIMEZONE và mẫu bản ghi của hai writer ở staging trước merge.
- SQL hiện dùng SUM(CASE …) trên mỗi nguồn với một range predicate ngoài, tối đa 366 bucket (1.464 cột cho nguồn hóa đơn). So sánh kỳ trước thêm bốn aggregate theo khoảng. Đo EXPLAIN và chi phí CPU trên MySQL test trước khi chốt rollout; nếu cần tối ưu, ưu tiên bucket GROUP BY có semantics tương đương.

Trạng thái: triển khai trên nhánh API riêng. Baseline backend được kiểm tra: `b287ee2`. Đối chiếu mã nguồn và migration trong repo; cấu hình, dữ liệu và execution plan trên production chưa được kiểm tra.

## 1. Kiểm toán API hiện tại

`GET /api/dashboard` được xác thực. `DashboardController` gọi `DashboardService::summary(user)`; query string không thay đổi phạm vi hay kỳ. `BoardingHouseAccessService::houses` chỉ trả cơ sở có landlord tồn tại và thuộc user hoặc có phân công quản lý. Các aggregate đi qua Room thuộc tập cơ sở này. Các model BoardingHouse, Landlord, Room, Tenant, Contract và Invoice áp dụng soft-delete qua Eloquent.

| KPI hoặc biểu đồ | Nguồn và công thức hiện tại |
|---|---|
| Tổng phòng | `boarding_houses[].total_rooms`, đếm Room hiện hữu; frontend cộng các cơ sở được chọn. |
| Phòng đang thuê | `occupied_rooms`, Room có ít nhất một RoomTenant ACTIVE liên kết Tenant hiện hữu. Trạng thái RENTED nhập tay có thể lệch. |
| Phòng chưa có người | Tổng phòng − phòng có người; có thể gồm phòng bảo trì hoặc đã đặt. |
| Tỷ lệ lấp đầy | Phòng có người / tổng phòng × 100, tổng bằng 0 trả 0; tỷ lệ chung tính lại theo số phòng. |
| Thực thu tháng / Area | `revenue_chart` cộng Payment.amount theo paid_at trong tháng, sáu tháng theo timezone cấu hình app. Mỗi Payment phải có Invoice hợp lệ và Room trong scope. KPI lấy bucket tháng hiện tại. |
| Còn phải thu | `invoice_summary.outstanding_amount`, SUM(total_amount − paid_amount) cho billing_period tháng hiện tại; mỗi cơ sở có tổng riêng. |
| Hóa đơn chưa thanh toán | `unpaid_invoice_count` mọi kỳ, trạng thái UNPAID/PARTIALLY_PAID và total_amount > paid_amount. Quá hạn nếu due_date < hôm nay. Danh sách tối đa 8. |
| Hợp đồng sắp hết hạn | ACTIVE, start_date ≤ hôm nay, end_date trong [hôm nay, hôm nay + 30 ngày]. Danh sách tối đa 5; không dùng danh sách này để dựng histogram. |
| Donut / thanh lấp đầy | Cùng nguồn phòng và tổng từng cơ sở; hai nhóm theo cư trú, không tách đặt trước/bảo trì. |
| Hợp đồng hoạt động / người thuê | `active_contracts`: ACTIVE, đã bắt đầu, chưa đến sau ngày kết thúc hoặc chưa có ngày kết thúc. `active_tenants`: DISTINCT tenant_id trên RoomTenant ACTIVE có Tenant hiện hữu. Hai chỉ số trong snapshot API; giao diện hiện dùng tổng người đang ở. |
| Hóa đơn theo kỳ / thu–chi / hết hạn theo khoảng | API hiện không có chuỗi tương ứng. Thẻ Thu–chi dùng tổng tài chính hiện tại; ba biểu đồ giàu dữ liệu chỉ có trong demo. |

`Invoice.total_amount` là nghĩa vụ phải trả; `Invoice.paid_amount` là số thanh toán tích lũy gắn với hóa đơn và được cập nhật bởi `InvoicePaymentService`. Ví dụ hóa đơn kỳ tháng 10 trả 300.000 đ trong tháng 11: tiền đã thanh toán của cohort tháng 10 tăng 300.000 đ, còn thực thu ghi vào tháng 11. Công nợ cohort là số dư **tại lúc truy vấn**, không phải lịch sử công nợ cuối kỳ. Hóa đơn được tính khi trạng thái là UNPAID, PARTIALLY_PAID hoặc PAID. DRAFT, CANCELED và hóa đơn soft-delete bị loại, cùng Payments không còn invoice hợp lệ. Không dùng `Invoice.paid_at` cho thực thu.

`Payment` không dùng SoftDeletes; đường xóa payment thủ công xóa bản ghi và tính lại hóa đơn. Snapshot tiền thu trong quá khứ có thể đổi khi bản ghi được chỉnh sửa hoặc xóa. Chưa xác minh được ledger hoàn tiền/hủy bất biến cho toàn bộ Payment, nên không công bố thực thu ròng sau hoàn tiền hoặc số dư as-of. Truy vấn hiện tại không join PayOS request và không lọc Payment bằng một enum refund tự suy đoán. PayOS chỉ được tính khi có Payment hợp lệ; trạng thái request PENDING/CANCELLED hay tín hiệu PAID chưa ghi Payment không phải dòng tiền. Không nối theo mã giao dịch/số tiền để phục hồi Payment mồ côi. Payment 1, PayOS request 9 và invoice ID 10 nằm ngoài mọi thao tác đối soát.

RoomTenant có move_in_date/move_out_date, nhưng chưa xác minh tính đầy đủ cho mọi luồng/record lịch sử; thay đổi phòng không nhất thiết là vào/ra cơ sở. Chưa tìm thấy model chi phí đủ căn cứ để tổng hợp. Trạng thái phòng quá khứ cũng chưa đủ bằng chứng để tái dựng.

## 2. Hợp đồng API đề xuất

Giữ nguyên GET /api/dashboard cho client cũ, bootstrap danh sách cơ sở và danh sách việc cần xử lý. Bổ sung hai GET để snapshot có thể cache độc lập với analytics; dùng chung query scope và aggregate hiện có. Các API theo biểu đồ riêng sẽ nhân số request và lặp phạm vi, bucket và kiểm tra quyền.

### GET /api/dashboard/summary

Query: `boarding_house_id` tùy chọn, số nguyên dương; bỏ trống là mọi cơ sở trong quyền. Filter thời gian trả 422. Ví dụ `GET /api/dashboard/summary?boarding_house_id=7`.

```json
{"data":{"as_of":"2026-11-14T09:00:00+07:00","timezone":"Asia/Ho_Chi_Minh","boarding_house_id":7,"rooms":{"total":42,"occupied":38,"unoccupied":4,"occupancy_pct":"90.48"},"contracts":{"active":37,"expiring_30d":6},"tenants":{"active":51},"houses":[{"id":7,"name":"An Phú","operational_role":"OWNER","total_rooms":42,"occupied_rooms":38,"occupancy_pct":"90.48"}]}}
```

`as_of` là lúc đọc, `houses` là breakdown trong scope đang xem. Số phòng, hợp đồng và người thuê dùng công thức mục 1, nhưng giờ cả ba nhóm nhận cùng bộ lọc cơ sở. Không có phòng thì các số đếm và tỷ lệ bằng 0. Không có cơ sở được cấp quyền thì houses rỗng. Resolve ID bằng `BoardingHouseAccessService::house`; ID không tồn tại hoặc ngoài quyền cùng trả 404. Dùng room/occupancy aggregate theo house, contract count và COUNT DISTINCT tenant_id theo scope; tránh cộng distinct từng nhà để suy ra tổng cuối. Tái sử dụng index phòng và `room_tenants(room_id,status,tenant_id)`.

### GET /api/dashboard/analytics

Filter `boarding_house_id` giống summary. `preset` nhận `today|yesterday|last_7_days|last_30_days|day|week|month|quarter|year|custom`. Preset mặc định là month hiện tại; day/week/month/quarter/year nhận `anchor_date=YYYY-MM-DD` để chọn kỳ, mặc định hôm nay. Custom bắt buộc `from` và `to` theo ngày địa phương, bao gồm cả hai đầu. `group_by=day|week|month|quarter|year` tùy chọn; mặc định theo kỳ (ngày cho hôm nay/hôm qua/7 ngày/tuần, ngày cho tháng và 30 ngày, tháng cho quý/năm, tự chọn ngày/tháng cho custom dựa trên số bucket). `compare=0|1`, mặc định 0. Trả 422 cho ngày không tồn tại, tham số thừa hoặc xung đột, from > to, khoảng trên 366 ngày hoặc trên 366 bucket. Chỉ nhận timezone Asia/Ho_Chi_Minh dưới dạng quy ước cố định, không nhận timezone tùy ý.

Tuần bắt đầu thứ Hai. Quý theo lịch. last_7_days và last_30_days gồm ngày hôm nay và N−1 ngày trước. Tạo biên local midnight: `[start, dayAfterEnd)`; chuyển biên một lần sang timezone lưu trữ thực tế của từng trường datetime sau khi xác minh config DB và writer (payments.paid_at là DATETIME, không mặc định coi là UTC). DATE như billing_period, start_date và end_date so sánh trực tiếp với ngày địa phương. Bucket đầu/cuối được cắt theo khoảng yêu cầu. Kỳ trước là đơn vị lịch liền trước cho ngày/tuần/tháng/quý/năm; rolling và custom lùi đúng số ngày. So sánh theo tổng; trả kỳ trước nguyên vẹn, giao diện ghi rõ hai khoảng và không ghép ngày giả khi số ngày khác nhau.

Ví dụ `GET /api/dashboard/analytics?preset=day&anchor_date=2026-11-14&group_by=day&boarding_house_id=7&compare=1`.

```json
{"data":{
  "as_of":"2026-11-14T09:00:00+07:00","timezone":"Asia/Ho_Chi_Minh","currency":"VND",
  "scope":{"boarding_house_id":7},
  "period":{"from":"2026-11-14","to_exclusive":"2026-11-15","group_by":"day"},
  "availability":{"cash":"supported","invoices":"supported","contracts":"supported","tenant_movements":"unsupported","expenses":"unsupported","historical_occupancy":"unsupported"},
  "totals":{"cash_received":"300000.00","invoice_count":0,"invoice_value":"0.00","invoice_paid_current":"0.00","invoice_due_current":"0.00","contracts_started":1,"contracts_ended":0},
  "series":[{"from":"2026-11-14","to_exclusive":"2026-11-15","cash_received":"300000.00","invoice_count":0,"invoice_value":"0.00","invoice_paid_current":"0.00","invoice_due_current":"0.00","contracts_started":1,"contracts_ended":0}],
  "previous":{"from":"2026-11-13","to_exclusive":"2026-11-14","totals":{"cash_received":"0.00","invoice_count":0,"invoice_value":"0.00","invoice_paid_current":"0.00","invoice_due_current":"0.00","contracts_started":0,"contracts_ended":0}}
}}
```

- `period` và `scope` echo phạm vi đã chuẩn hóa; `series` có cùng trường với totals theo từng bucket, tiền là DECIMAL string. Dãy luôn theo thứ tự thời gian. Bucket hỗ trợ nhưng không có bản ghi trả 0. Nguồn chưa hỗ trợ chỉ có capability, không trả số 0 thay thế. `previous` bằng null khi compare=0.
- `cash_received` dùng Payment và thời điểm đã ghi nhận như mục 1. `invoice_count`, `invoice_value`, `invoice_paid_current`, `invoice_due_current` dùng billing_period. Bucket theo ngày chứa cohort mang ngày billing_period, không coi đó là ngày phát hành và không chia tiền hóa đơn theo số ngày ở. Công nợ là SUM(total_amount − paid_amount) của cùng cohort, mỗi invoice đúng một bucket. Nếu dữ liệu âm do lệch sổ, ghi nhận lỗi chất lượng dữ liệu để đối soát; không sửa sổ hoặc âm thầm đặt về 0.
- `contracts_started/ended` là số ngày bắt đầu/kết thúc **theo hợp đồng**, không phải ngày chuyển vào/ra hoặc lịch sử chuyển trạng thái. Allowlist đề xuất là ACTIVE, EXPIRED, TERMINATED, với is_demo=false và deleted_at IS NULL; loại record chưa có ngày tương ứng. Dữ liệu TERMINATED vẫn chỉ mô tả ngày ghi trong hợp đồng. Nếu cần ngày chấm dứt thực tế, chỉ dùng trường/sự kiện đã xác minh; nếu thiếu thì đánh dấu unsupported. Khoảng hết hạn tương lai cho hợp đồng ACTIVE cần aggregate riêng theo các nhóm 0–7, 8–14, 15–21, 22–30 ngày của snapshot, không nhầm với contracts_ended; bổ sung vào summary khi chốt contract.
- Tenant movements và expense chờ xác minh dữ liệu. Không tính lợi nhuận từ chênh lệch giữa thực thu và giá trị hóa đơn.
- Phạm vi và 404 giống summary. Mọi SQL aggregate cùng subquery room/invoice đã scope; dùng EXISTS hoặc join nhiều-một có khóa để Payment không lặp. Dùng range predicates trên cột gốc cho WHERE; phép bucket chỉ dùng ở SELECT/GROUP BY. Chạy query nhóm riêng cho cash, invoice và start/end hợp đồng, ghép kết quả nhỏ theo bucket ở PHP. Giữ số DECIMAL cả trong SQL và phần tổng hợp. Dùng index paid_at/invoice_id, invoices(room_id,billing_period,status), contract start/end; kiểm tra EXPLAIN với phạm vi một nhà và nhiều nhà trước khi đề xuất index bổ sung. Trả Cache-Control private, no-store cho HTTP. TanStack Query dùng query key theo phiên đăng nhập, gồm house, phạm vi chuẩn hóa, group, compare; hủy request cũ khi đổi bộ lọc. Không dùng cache chung ngoài session; hủy cache khi logout hoặc đổi quyền.

## 3. Tích hợp frontend và endpoint hiện có

- `GET /api/dashboard` tái dùng cho client cũ, cơ sở và danh sách ngắn trong giai đoạn chuyển tiếp. Chuyển snapshot sang summary và chỉ đổi Analytics query khi đổi thời gian; label kỳ hiển thị từ response đã áp dụng. Biểu đồ tiền thực thu đọc cash_received, không đọc invoice_paid_current.
- `GET /api/boarding-houses/{id}/rooms` và route frontend danh sách phòng dùng cho drill-down cơ sở; trang hiện còn tải detail cơ sở qua API chỉ dành owner, nên link lấp đầy chỉ bật cho owner. `GET /api/contracts/{id}` và `GET /api/invoices/{id}` hỗ trợ link từng mục hiện tại, quyền vẫn do server quyết định.
- `GET /api/rooms/{id}/contracts`, `GET /api/rooms/{id}/invoices`, `GET /api/invoices/{id}/payments`, `GET /api/tenants` dùng cho trang nghiệp vụ; không fan-out các endpoint này để dựng thống kê. Chưa có trang danh sách hóa đơn theo scope + trạng thái hoặc hợp đồng theo bucket tương ứng, nên chưa nối biểu đồ/KPI tổng hợp vào các trang đó.

## 4. Kế hoạch kiểm thử backend trước khi triển khai

Chạy trên database test riêng với schema và fixtures cô lập. Dùng MySQL test cho integration kiểm tra DECIMAL, timezone và execution plan. Không dùng ID incident thật làm đầu vào sửa chữa.

| Nhóm | Điều kiện cần kiểm tra |
|---|---|
| Ranh giới lịch | Hôm nay/hôm qua, 7/30 ngày, ngày/tuần/tháng/quý/năm/custom, thứ Hai, 29/02, 31/12 → 01/01, start inclusive/end exclusive, UTC 17:00 cắt ngày UTC+7; ngày sai, đảo khoảng, quá giới hạn, tham số mâu thuẫn trả 422. |
| Kỳ trước | Tháng 28/29/30/31 ngày, quý và năm qua ranh giới; rolling/custom cùng độ dài; snapshot không đổi khi đổi kỳ. |
| Phân quyền | Hai landlord, cơ sở owner và manager; bỏ phân công, cơ sở thuộc người khác và không tồn tại cùng 404; mọi bucket/list không lộ tên, số tiền hoặc tenant ngoài scope. |
| Tài chính | Payment tháng khác billing_period; partial và nhiều payments một invoice; cùng tenant nhiều phòng; DRAFT/CANCELED/soft-delete; PayOS PENDING/CANCELLED và PAID chưa tạo Payment; webhook trùng chỉ một Payment; payment không có invoice hợp lệ bị loại. |
| An toàn | Fixture mồ côi tái hiện hình dạng incident trong DB test. GET không liên kết Payment 1 vào invoice 10, không đổi PayOS request 9, không INSERT invoice/payment, không UPDATE sổ hoặc chạy webhook. So sánh trạng thái trước/sau và trace SQL. |
| Rỗng và tải lớn | Zero buckets, capability unsupported, không có house, 366 ngày và nhiều cơ sở. Xác nhận số query không tăng theo số cơ sở/bucket, query plan dùng index, tổng tiền chính xác. |

## 5. Cô lập khi triển khai sau duyệt

Backend triển khai trên branch `feature/dashboard-analytics-api`, worktree `C:\Users\Yato\Desktop\beehouse-dashboard-api`. Frontend được commit độc lập. Schema, Billing V2 và các luồng ghi dữ liệu tài chính nằm ngoài diff.

## 6. Kiểm chứng triển khai

Worktree trên được tạo từ origin/main `e279a12`. Laravel API đã được kết nối vào worktree frontend riêng; API cũ vẫn cấp danh sách cơ sở và danh sách nghiệp vụ toàn phạm vi.

MySQL 8.0.41 chạy trên loopback, cổng 33396 và datadir riêng; 5 Analytics tests/100 assertions pass. Khi tăng từ 1 lên 21 cơ sở, GET analytics 366 ngày vẫn chạy 6 SELECT, gồm xác thực và bốn aggregate. EXPLAIN của SQL thực chọn `payments_paid_at_index` kiểu range và join hóa đơn qua PRIMARY; truy vấn cohort chọn `invoices_room_id_index`, với `invoices_dashboard_period_index` trong possible_keys. Đây là fixture nhỏ, chưa xác nhận hiệu năng ở khối lượng staging. Trước merge, xác minh timezone writer legacy và lặp lại EXPLAIN trên dữ liệu staging có phân bố thực tế.
