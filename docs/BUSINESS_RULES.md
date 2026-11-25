# BeeHouse — Quy tắc nghiệp vụ

Phiên bản khởi tạo: 2026-11-24. Đây là tài liệu sống, không phải bản audit toàn bộ hệ thống.

## Cách sử dụng

- **Đã chốt:** chủ dự án đã đồng ý về hành vi mong muốn; chưa mặc nhiên là đã triển khai đầy đủ.
- **Đối chiếu code:** có bằng chứng ở revision được ghi rõ; không đồng nghĩa với test PASS hoặc đã deploy.
- **Cần xác minh:** chưa đủ bằng chứng, không dùng làm yêu cầu triển khai bắt buộc nếu chưa được chốt.

Nguồn đối chiếu ban đầu: nhánh `feature/contract-checkout-deposit-ledger`, revision `56ab419960d153a5e82ced7a32d8196cd758e635` đã đọc trong cuộc trao đổi. Các hotfix local sau revision này chưa được audit. Các quy tắc dưới đây lấy từ quyết định đã trao đổi và những đoạn code đã kiểm tra; không suy diễn toàn bộ hệ thống từ chúng.

## 1. Hợp đồng và bàn giao

### BR-CONTRACT-001 — Hợp đồng nháp và chỉ số đầu
**Trạng thái:** Đã chốt; đối chiếu một phần code frontend.

- Khi lập/chỉnh sửa hợp đồng DRAFT, nhập chỉ số đầu cho dịch vụ điện/nước tính theo đơn vị tại bước 2 — Người ở & dịch vụ. Không bắt buộc hợp đồng ACTIVE để nhập dữ liệu này.
- Chỉ số đầu phải là số không âm; ngày ghi trong wizard lấy từ ngày bắt đầu hợp đồng. Dữ liệu này chưa phải tiền điện nước kỳ thuê, cũng không đồng nghĩa đã xác nhận CHECK_IN V2.
- Nếu không chọn dịch vụ điện/nước tính theo đơn vị, wizard không yêu cầu chỉ số đầu. Wizard thông báo công tơ chưa tồn tại sẽ được đăng ký khi lưu hợp đồng; cần xác minh việc thực thi ở backend và các ngoại lệ.
- Khi đã lưu nháp, có thể hoàn thiện biên bản bàn giao. Hợp đồng thực tế cần xác nhận bàn giao trước khi kích hoạt theo luồng UI đã đối chiếu; cần rà lại các endpoint kích hoạt để xác nhận mọi ràng buộc backend.

**Nguồn:** `front-end/src/components/contracts/ContractWizard.jsx` (validation, utility_openings, bước 2–3); `ContractHandoverSection.jsx`; `ContractMeterCheckInSection.jsx` (cùng thư mục).

### BR-CONTRACT-002 — Mốc CHECK_IN V2
**Trạng thái:** Đã chốt theo luồng đang trao đổi; đối chiếu frontend và một phần backend.

- Phân biệt dữ liệu chỉ số đầu trong nháp với mốc CHECK_IN V2 được xác nhận sau khi kích hoạt. Không cho hợp đồng DRAFT/DEMO xác nhận CHECK_IN V2 như hợp đồng thực tế.
- Đối chiếu công tơ và ngày giờ đọc thực tế; không tự tạo số đọc hoặc thời điểm đọc. Kiểm tra mốc đã tồn tại để tránh ghi trùng.
- Kiểm tra đủ mốc CHECK_IN hợp lệ cho công tơ áp dụng trước khi tính tiền theo kỳ; không thay thế thiếu mốc bằng giá trị đoán.

**Nguồn:** `front-end/src/components/contracts/ContractMeterCheckInSection.jsx`; `backend-api/app/Services/BillingV2Evidence.php` (checkpoint). Backend từ chối DRAFT cho mốc bàn giao; các trạng thái lịch sử có thể được xử lý khác, cần xác minh riêng.

## 2. Điện nước

### BR-UTILITY-001 — Chu kỳ và mốc ghi
**Trạng thái:** Đã chốt về nguyên tắc; hotfix local chưa đối chiếu đầy đủ.

- Chu kỳ điện nước phải theo mốc thuê/chốt đã thống nhất, không ép mọi hợp đồng bắt đầu vào ngày 01. Ví dụ hợp đồng phòng riêng có chu kỳ 23/11/2026–23/12/2026; nhãn tháng không tự quyết định ngày đầu/cuối.
- Kỳ đầu căn cứ mốc bàn giao phù hợp; kỳ tiếp theo kế thừa mốc cuối kỳ trước khi hợp lệ. Không tự điền giá trị 0 hoặc tạo số đọc thực tế khi thiếu bằng chứng.
- Phân biệt khai báo/đăng ký công tơ, nhập chỉ số đầu hợp đồng, CHECK_IN, mốc kỳ PERIOD_BOUNDARY và CHECK_OUT. Không coi các loại mốc này là một nghiệp vụ.
- Các kỳ linh hoạt, kỳ lẻ, gia hạn, hết hạn, đổi người thuê, thay công tơ và phòng KTX dùng công tơ chung cần audit thêm trước khi thay đổi logic tổng quát.

### BR-UTILITY-002 — Giao diện sổ điện nước
**Trạng thái:** Đã chốt; chủ dự án đã thử giao diện local; chưa audit source mới nhất.

- Mỗi dòng tháng: chưa ghi → Ghi chỉ số; đủ dữ liệu → Đính chính; thiếu một phần → Bổ sung/đính chính (theo quyền và trạng thái hợp lệ).
- Một popup cho cả điện và nước; đính chính hiển thị dữ liệu đã lưu và yêu cầu lý do theo luồng hiện tại. Không ghi lại công tơ đã lưu thành công nếu công tơ khác thất bại. Không giả định hai API riêng là một giao dịch nguyên tử.
- Từ phòng đi đến sổ điện nước, giữ ngữ cảnh phòng và có nút quay về đúng phòng.
- UI không được thay thế kiểm tra quyền, điều kiện sửa hoặc ràng buộc hóa đơn ở backend. Dữ liệu đã phát hành/thanh toán hoặc đã liên kết thanh toán cần được bảo vệ.

**Nguồn tham chiếu trước hotfix:** `front-end/src/pages/utilities/UtilityLedgerPage.jsx`, `UtilityLedgerHistory.jsx`; `backend-api/app/Http/Controllers/Api/BillingV2Controller.php`; `backend-api/app/Services/BillingV2Evidence.php` (correction).

## 3. Hóa đơn và thanh toán

### BR-INVOICE-001 — Hóa đơn tháng
**Trạng thái:** Quy tắc đã thảo luận/chốt; cần audit code mới nhất.

- Tạo hóa đơn nháp, chủ trọ xác nhận phát hành; không tự động lập/phát hành theo lịch nếu chưa chốt thay đổi nghiệp vụ.
- Không đồng nhất tiền cọc thỏa thuận trong hợp đồng với tiền cọc đã thu. Không suy ra đã thanh toán từ việc tạo hoặc kích hoạt hợp đồng.
- Chỉ số điện nước và các điều kiện hợp đồng phải hợp lệ trước khi tính khoản tương ứng. Không tạo trùng hóa đơn cho cùng nghĩa vụ thanh toán.

### BR-INVOICE-002 — Xóa hóa đơn nháp
**Trạng thái:** Đã chốt theo yêu cầu trước đây; bản vá local chưa audit đầy đủ.

- Cho phép xóa cứng hóa đơn DRAFT chưa phát hành, chưa có thanh toán/liên kết PayOS và thỏa các ràng buộc backend; dọn dữ liệu phụ thuộc đúng cách để tạo lại kỳ hợp lệ.
- Không áp dụng xóa cứng này cho hóa đơn đã phát hành, đã thanh toán hoặc có liên kết giao dịch không an toàn. Không bỏ qua kiểm tra để giải quyết lỗi UI.

## 4. KTX và gia hạn

### BR-DORM-001 — Tiền giường và dịch vụ
**Trạng thái:** Đã thảo luận trước đây; cần audit source mới nhất.

- Mặc định thu tiền giường; dịch vụ được bao gồm trong giá nếu cấu hình/thỏa thuận như vậy. Khi thu riêng theo công tơ chung, nguyên tắc đã thảo luận là phân bổ theo người-ngày.
- Không áp dụng máy móc chu kỳ phòng riêng cho tất cả giường KTX; mỗi hợp đồng giường có thể có chu kỳ riêng. Cần kiểm chứng quy tắc phân bổ, người vào/ra giữa kỳ và tính trùng trước khi sửa.

### BR-CONTRACT-003 — Gia hạn
**Trạng thái:** Đã thảo luận; cần xác minh triển khai.

- Cung cấp luồng gia hạn rõ ràng khi hợp đồng hết hạn, hiển thị nguyên nhân và trạng thái đúng. Không mặc định thay đổi ngày thuê hoặc ghi đè lịch sử chỉ để hợp đồng tiếp tục xuất hóa đơn.
- Chi tiết cách nối mốc điện nước, công nợ, giá và cọc giữa hợp đồng cũ/mới: cần kiểm tra code và chốt nếu còn mâu thuẫn.

## 4a. Tạo tầng và phòng hàng loạt
### BR-ROOM-001 — Bổ sung phòng hàng loạt theo khoảng tầng trong nhà trọ hiện có
**Trạng thái:** Chủ dự án đã chốt luồng ngày 25/11/2026. Bản vá đề xuất triển khai từ revision `e9d25e8`; chưa xác minh tích hợp trên máy local hoặc production.

- Chỉ một chế độ **Tạo hàng loạt** trong nhà trọ hiện có. Section Tầng nhập Từ tầng – Đến tầng (bao gồm hai đầu); X → X là thêm tại một tầng. Không bổ sung chọn các tầng không liền nhau ở đợt này. Luồng tạo KTX kèm giường giữ riêng và không thay đổi.
- Nhập số phòng **bổ sung** mỗi tầng, tiền tố tùy chọn và thông tin mặc định (giá thuê, diện tích, loại/phân loại, số người tối đa). Giá bỏ trống là chưa thiết lập, không phải 0. Giữ giới hạn tổng danh sách 200 phòng/lượt.
- Mã tự sinh = tiền tố + số tầng + số thứ tự hai chữ số (01–99). Nếu có mã cùng mẫu, nối sau số thứ tự lớn nhất, không lấp khoảng trống, không ghi đè. Tính cả mã đã lưu và mã đang nháp, không phân biệt hoa thường; mã đã tồn tại trong nhà trọ ở tầng khác cũng phải được bảo lưu. Nếu vượt 99, báo lỗi và cho đổi tiền tố hoặc thêm thủ công; không tự đổi quy cách mã. Ví dụ tầng 2 có P201–P203, bổ sung 2 phòng → P204, P205.
- Tầng chuẩn sinh theo tên `Tầng N` (N nguyên không âm). Dùng lại tầng cùng tên trong nhà trọ (trim, không phân biệt hoa thường), giữ metadata hiện có. Không suy đoán tầng có tên tùy chỉnh như `Trệt` tương đương Tầng 0; phần điều chỉnh tầng thủ công vẫn có thể dùng tên chính xác. Nếu tên không xác định duy nhất, báo lỗi.
- Preview dùng cùng hàm sinh mã với thao tác sinh danh sách. Sinh danh sách chỉ thêm dữ liệu nháp; cho sửa mã, tầng, giá, diện tích, loại/phân loại và số người trước khi xác nhận. Tầng mới chỉ được tạo nếu có phòng sử dụng. Giữ luồng thủ công và trường hợp chưa phân tầng đã có.
- Backend là nguồn quyết định cuối cùng: kiểm tra quyền theo nhà trọ, giới hạn, khóa tham chiếu tầng, mã trùng trong toàn nhà. Khóa nhà trọ trong transaction; tạo tầng/phòng cùng lúc, lỗi bất kỳ thì rollback toàn bộ. Nếu có người khác tạo cùng mã sau preview, từ chối cả batch; tải lại dữ liệu đối chiếu trước khi sinh lại. Không tự đổi mã đã được xác nhận. API cũ với `floor_id` chung giữ tương thích; không tự tạo giường, công tơ, hợp đồng, hóa đơn, không thay đổi phòng cũ.
- Không yêu cầu migration mới. API bulk hiện tại chưa có idempotency key riêng: nếu mất kết nối, kiểm tra danh sách đã lưu trước khi thử lại; không tự động retry.

**Kế hoạch triển khai:**
1. Giữ quy tắc và xác định source gốc, kiểm tra trước khi sửa.
2. Nâng cấp planner thuần + test mã nối tiếp, X→X, khoảng nhiều tầng, mã trùng, giới hạn và tên tầng mơ hồ.
3. Gộp UI hiện có thành một chế độ bulk, giữ KTX và wizard tạo nhà trọ mới; hiển thị preview mã thực và bảng có thể chỉnh sửa.
4. Mở rộng API bulk tương thích ngược, lưu floor/room atomically, kiểm tra ownership, rollback và thứ tự phòng; thêm feature test.
5. Chạy test planner, test component, build/lint, Laravel feature tests trên SQLite cô lập, thử UI thật trên local. Chỉ cập nhật trạng thái kiểm thử sau khi có kết quả; không push/merge/deploy tự động.

**Nguồn:** `front-end/src/pages/rooms/RoomCreatePage.jsx`, `front-end/src/components/boarding-houses/BulkRoomForm.jsx`, `front-end/src/utils/multiFloorRooms.mjs`, `front-end/src/api/roomApi.js`, `backend-api/app/Http/Requests/Room/StoreBulkRoomsRequest.php`, `backend-api/app/Http/Controllers/Api/RoomController.php`, `backend-api/tests/Feature/ExistingBoardingHouseBulkRoomsApiTest.php`.


## 5. Danh sách cần audit tiếp (chưa phải quy tắc mới)

- Điều kiện backend ghi PERIOD_BOUNDARY theo trạng thái hợp đồng, phòng trống, kỳ lịch sử, kỳ lẻ, ngày ghi và mốc mở kỳ; UI chặn không có nghĩa backend đã chặn.
- Xác nhận CHECK_IN: quan hệ giữa chỉ số đã lưu nháp và mốc V2 thực tế; xử lý sai lệch, đính chính, công tơ được thêm/thay sau kích hoạt.
- Ràng buộc đính chính cùng lúc điện/nước, hóa đơn nháp cần tính lại, hóa đơn đã phát hành, giao dịch PayOS và retry lỗi một phần.
- Trả phòng, CHECK_OUT, tiền cọc, khấu trừ/hoàn cọc, gia hạn và thanh toán; không tự bổ sung điều kiện khi chưa đọc nguồn.
- Đối chiếu local/remote/production, cập nhật revision và test thực sự đã chạy cho từng quy tắc.

## Nhật ký thay đổi

| Ngày | Nội dung | Trạng thái |
|---|---|---|
| 2026-11-24 | Khởi tạo bộ quy tắc và hướng dẫn AI; ghi rõ DRAFT khác CHECK_IN V2, chu kỳ và thao tác sổ điện nước; ghi các nguyên tắc đã thảo luận về hóa đơn/KTX/gia hạn. | Bản nền, chưa audit toàn repo và chưa chạy test. |
