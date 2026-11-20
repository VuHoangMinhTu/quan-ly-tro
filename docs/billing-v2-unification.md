# Thống nhất Billing V2

Nhánh `refactor/unify-billing-v2`, worktree `C:\Users\Yato\Desktop\beehouse-billing-v2-unification`, xuất phát từ `origin/main` tại `11e539d`.

## Kiểm toán và thay đổi

| Luồng | Phân loại và quyết định |
|---|---|
| Phòng riêng: ghi, sửa, xóa chỉ số qua UtilityReadingController | Writer cũ từng xuất hiện từ chi tiết công tơ và khi RoomUtilitiesSection không chọn luồng Quyết toán. POST/PUT/DELETE trả 422 sau kiểm tra owner; không phụ thuộc feature flag. GET lịch sử vẫn giữ. |
| Quyết toán và đính chính công tơ | BillingV2Controller, BillingV2Access, BillingV2Evidence và UtilityPeriodBilling là đường vận hành phòng riêng. Mốc xác nhận, đính chính, khóa và snapshot hiện hữu giữ nguyên. |
| Sinh khoản thu tự động trên hóa đơn | InvoiceDetailPage và POST /invoices/{id}/generate từng gọi BillingService. Đã loại nút ở phòng riêng và khóa HTTP writer ở server; vẫn giữ phép tính cũ và test vì còn đường tương thích KTX. Các kiểm tra chống ghi đè hóa đơn V2 được giữ. |
| Tạo/cập nhật/phát hành hóa đơn tháng | InvoiceCreatePage, MonthlyInvoicePanel, DormitoryInvoicePanel, MonthlyInvoiceBilling, MonthlyDraftRecalculation và DormitoryBedBilling đã dùng đường mới. Khi tắt feature gate, luồng tháng bị khóa theo cơ chế hiện hữu. |
| Hóa đơn thủ công | POST /rooms/{roomId}/invoices tạo hóa đơn rỗng, kiểm tra quyền, hợp đồng và trùng kỳ. Giữ API cùng khả năng thêm khoản thủ công theo quy tắc chỉnh sửa hiện hữu. |
| Thanh toán và lịch sử | Giữ InvoiceList/Detail, bản in, các API đọc và writer thanh toán đang phục vụ hóa đơn cũ. Không chạy lại BillingService trên dữ liệu lịch sử. |
| Công tơ vật lý | UtilityMeterController và đăng ký/chỉnh metadata là thành phần dùng chung. Trang chi tiết vẫn dùng API đọc chỉ số và hiển thị lịch sử phòng riêng. |
| Trả phòng và cọc | Các đường xác nhận checkout, xác nhận quyết toán, ledger cọc và ContractSettlementPolicy giữ nguyên. Kiểm tra phòng riêng hiện hữu; không suy rộng sang KTX. |

UtilityReadingModal, UtilityReadingForm và utilityReadingApi vẫn có caller KTX; không có lý do xóa. Thay đổi giao diện giới hạn ở điểm vào và lời giải thích. Các validation, bảo vệ tài chính, công thức và idempotency của engine hiện tại giữ nguyên. Test công thức cũ gọi engine qua route được đăng ký trong lớp test, tách việc kiểm tra phép tính khỏi HTTP writer phòng riêng đã bị khóa.

## Ngoại lệ KTX và blocker

Hóa đơn giường V2 theo người/hợp đồng vẫn là luồng chuẩn. Điều khoản giường mặc định bao gồm dịch vụ; không tạo khoản công tơ riêng khi chưa cấu hình. KTX có đăng ký công tơ thu riêng còn đường ghi và tra cứu dành cho owner. API sinh khoản tự động cũ giữ tương thích cho hóa đơn KTX ngoài các composition/obligation V2. Đây là ngoại lệ theo loại phòng, không phải cơ chế tự chuyển khi feature flag tắt. Manager không được mở quyền gọi writer cũ.

UtilityPeriodBilling hiện chỉ hỗ trợ phân bổ công tơ phòng riêng. Chưa đủ căn cứ để dùng cùng công thức cho công tơ KTX chung, cư trú khác thời điểm hoặc phân bổ người-ngày. Cần duyệt riêng chính sách KTX và ca chuyển giường/trả phòng trước khi khóa ngoại lệ. Không tự chuyển utility_readings sang utility_checkpoints.

Không có migration, thay đổi writer thanh toán, sửa hóa đơn lịch sử hoặc thao tác production.

## Kiểm thử

- Laravel SQLite cô lập: 791 pass, 3 skip dành cho MySQL concurrency.
- MySQL Billing V2 concurrency trên datadir và schema riêng: 3/3 pass, 59 assertions, gồm utility, monthly và recalculate.
- Frontend: 262 tests; build, lint, Pint cho các file PHP đã sửa và git diff --check.
- Chromium 1440 px và 390 px qua Laravel/SQLite test: đọc chỉ số cũ tại chi tiết công tơ phòng riêng, không có nút thêm/sửa/xóa, theo liên kết Quyết toán khi feature gate tắt và xác nhận thông báo tạm khóa. Trang quyết toán kiểm tra trạng thái trước khi gọi endpoint dữ liệu để tránh lỗi chung thay cho thông báo này.
