# BeeHouse — Full Website Performance & Responsive UI/UX Audit

> Bản báo cáo được lưu vào Git ngày 29/10/2026. Revision được audit là 80666d8. Ảnh, gallery HTML, log và dữ liệu đo tự sinh không đưa vào Git; chúng nằm tại tmp/full-audit/ trên máy audit. Các đường dẫn artifact trong báo cáo được tính từ thư mục đó, nên người clone repository sẽ không có sẵn các file này.

Ngày 28/10/2026. Revision được kiểm tra: `80666d8` (`feat: add searchable paginated tenant picker`). Audit đọc source và chạy local. Không sửa code ứng dụng, dependency, schema, dữ liệu production; không commit/push/deploy. Toàn bộ artifacts nằm trong `tmp/full-audit/`.

## Kết luận

Đã kiểm tra render **49 màn hình/trạng thái × 6 viewport = 294 ảnh đúng khung màn hình**, kèm **198 ảnh toàn trang** cho 33 màn hình ban đầu. Không ghi nhận tràn ngang cấp document hoặc uncaught JavaScript error trong các ca fixture đã render; điều này không có nghĩa tất cả thao tác nghiệp vụ đã PASS.

Điểm nghẽn rõ nhất là **Tenant CRUD list chưa phân trang**. TenantPicker mới dùng lookup phân trang, không có cùng đặc điểm tải toàn bộ. Responsive nhìn chung giữ bố cục ở sáu kích thước; mobile 320px cần cải thiện vùng danh sách popup, kích thước hit target và label form. Bản in có các vùng cuộn ngang có chủ đích trên màn hình nhỏ.

**Chưa thể kết luận hiệu năng production/MySQL hoặc sức chịu tải đồng thời.** MySQL local mở cổng 3306 nhưng credentials local bị từ chối `1045` ngay lúc kết nối. Không có SQL thực nào chạy trên MySQL; chưa có EXPLAIN/slow query/FPM/CPU/RAM VPS/TTFB production. Không coi API fixture là backend thật.

## Phương pháp và giới hạn

- UI: production build Vite, Chromium `156.0.8078.48`, loopback static server + API intercept bằng dữ liệu tổng hợp. Mọi request ra ngoài origin bị chặn. Ảnh demo hợp đồng dùng renderer và dữ liệu minh họa sẵn trong source, không đọc database production. Screenshot điện thoại là **viewport giả lập**, không phải Safari/iPhone hoặc Android vật lý.
- Bộ UI chính dùng 8 nhà trọ, 30 phòng, 60 tenant, 13 mục bàn giao, 24 readings, 6 khoản thu. Trạng thái khác được mở rõ ràng: form dài, KTX, bulk, editor, menu, modal, Toast. Các lựa chọn source catalog không có fixture phong phú không được xem là đã kiểm thử mọi giá trị.
- Backend: Laravel feature request thật qua kernel và ORM, **SQLite `:memory:`**, không có HTTP/TLS/Nginx/PHP-FPM. Fixture độc lập: 10 landlord, 10 nhà, 1.000 phòng, 10.000 tenant, 10.000 invoice/item/payment; owner đo có 100 phòng/1.000 tenant. 100 active memberships cùng một phòng là **stress fixture đọc**, không phải mô hình cư trú được khuyến nghị.
- Mỗi endpoint có 1 warm-up + 7 mẫu. `p95*` dùng nearest-rank nên với 7 mẫu chính là giá trị lớn nhất, độ tin cậy thấp; không dùng làm SLO hay năng lực production. SQL time là tổng do Laravel query log đo, khác tổng execution time.
- Thời gian scale Tenant ** là một lượt Chromium không throttle/loopback, gồm navigation và barrier networkidle; JS heap là working set ở thời điểm đo, không phải bằng chứng memory leak. Không suy throughput từ thời gian test.
- Không chạy Lighthouse score, audit contrast tự động hay field Web Vitals. Event timing lab không thay thế field INP; Safari/Android keyboard, iOS zoom, bàn phím ảo và screen reader thật chưa kiểm tra.
- Các runner Vite cũ xung đột cache khi chạy song song lần đầu; kết quả đó bị loại và đã chạy lại **tuần tự**. Locator Toast ban đầu mơ hồ được sửa trong harness audit và chụp lại; không sửa application.

## Các phát hiện và thứ tự đề xuất

| Ưu tiên | Phát hiện | Bằng chứng | Đề xuất, chưa triển khai | Nguồn |
| --- | --- | --- | --- | --- |
| P1 | Tenant CRUD list không phân trang | 10.000 người: ~8.979 ms, 470.248 nodes, 634 MB JS heap; trang cao hơn 2 triệu px. API thực trả 319.392 bytes cho 1.000 tenant của owner. | Cân nhắc phân trang server + DTO tối thiểu trước khi tối ưu vi mô. | front-end/src/pages/tenants/TenantListPage.jsx; backend-api/app/Http/Controllers/Api/TenantController.php |
| P1 / gate | Suite backend chưa xanh | 632 tests: 623 pass, 9 fail; không có sửa code trong audit. | Triage test expectations/fixture trước; không đổi business rules chỉ để làm test xanh. | backend-tests.log |
| P2 | Bundle vào trang đầu còn lớn | Main JS 1.002,56 kB raw / 273,70 kB gzip Vite; AppRoutes import tĩnh các pages. | Xem xét route-level splitting; jsQR đã có chunk riêng, không đề xuất làm lại phần đã lazy. | front-end/src/routes/AppRoutes.jsx; build.log |
| P2 | TenantPicker trên mobile nhỏ quá chật | 320×568: vùng list 50,9 px; 390×844: 310,4 px. Footer vẫn nằm trong viewport nhưng dòng đầu chỉ thấy một phần. | Cân nhắc thu gọn filter/pagination trên màn hình thấp; giữ focus/Confirm/Cancel. | viewport/screenshots/tenant-picker-320x568.png; ui-probes.json |
| P2 | Touch target nhỏ ở thao tác phòng | 390px: Xem/Sửa/Ngừng sử dụng trên house detail chỉ cao 20px; nút Xóa mục bàn giao rộng khoảng 25px. | Tăng vùng hit target độc lập typography, tránh bấm nhầm. Không tuyên bố toàn site fail WCAG từ phép đo này. | viewport/screenshots/house-detail-390x844.png; ui-probes.json |
| P2 | Label form hợp đồng chưa gắn lập trình | 7 trường trong create form không có input.labels/aria-label/aria-labelledby: mã, ba ngày, giá thuê, cọc, ghi chú. | Liên kết label với id; xác minh screen reader riêng. | front-end/src/components/forms/ContractForm.jsx; ui-probes.json |
| P2 | Bill mobile thiếu chỉ dẫn cuộn bảng | Ở 390px, container bảng rộng 330px, table rộng 550px; hai cột tiền nằm bên phải. Đã cuộn kiểm tra, không mất dữ liệu. | Thêm affordance cuộn hoặc cách xem mobile phù hợp; giữ bản in chính thức. | bill-mobile-scrolled-right.png; front-end/src/pages/invoices/InvoicePrintPage.css |
| P2 / risk | Một số danh sách vẫn get() toàn bộ | Room list 100 phòng: ~39 KB, 3 queries; residence context 100 occupants: ~54 KB, 7 queries; đây là scope owner/room, không phải tất cả workspace. | Đo tiếp ở cardinality thực trước khi chọn pagination/lazy sections; chưa có bằng chứng để thêm index. | backend-api/app/Http/Controllers/Api/RoomController.php; backend-api/app/Services/ResidenceDeclarationService.php |
| P3 | Placeholder cũ trên chi tiết người thuê | UI còn câu hợp đồng sẽ triển khai ở phase tiếp theo trong khi module hợp đồng đã tồn tại. | Xác nhận intended flow trước khi sửa nội dung/điều hướng. | front-end/src/pages/tenants/TenantDetailPage.jsx |


## Hiệu năng frontend

Build: pass, 2,06s theo Vite; main JS 1.002,56 kB raw / 273,70 kB gzip; jsQR chunk 129,97 kB / 47,49 kB gzip; CSS 71,78 kB / 13,76 kB gzip. Đây là kích thước build; chưa xác nhận gzip/Brotli/cache header trên Nginx production. Các route dùng import tĩnh nên login tải cùng main bundle.

### Tenant CRUD list — scale dữ liệu

| Tenant trên màn hình | Ready ms** | DOM nodes | JS heap MB | Chiều cao trang px |
| --- | --- | --- | --- | --- |
| 100 | 715 | 4948 | 13.4 | 22174 |
| 1000 | 879 | 47248 | 69.0 | 215674 |
| 10000 | 8979 | 470248 | 634.4 | 2150674 |


Trang có cả markup desktop và mobile trong DOM, dù một nhánh ẩn bởi CSS; số DOM nodes tăng tuyến tính theo danh sách. Đề xuất pagination trước khi cân nhắc tối ưu render. Số liệu này không áp dụng cho lookup TenantPicker.

### Mobile lab có throttle

390×844, CPU slowdown 4×, static assets latency 100ms, download 200.000 bytes/s (~1,6 Mbps), cache tắt, 3 mẫu/route. **Static server audit không nén gzip; API được intercept nên không có latency API thật.** Thời gian dưới đây thể hiện điều kiện lab tải JS không nén, không được gán cho production. Long task là một task >50ms, không phải Lighthouse TBT.

| Route | LCP median ms | CLS max | Longest task median ms | Click → picker ms |
| --- | --- | --- | --- | --- |
| /dashboard | 6576 | 0 | 235 | — |
| /login | 6272 | 0 | 242 | — |
| /rooms/1/contracts/new | 6536 | 0 | 239 | 298 |


CLS ở các mẫu throttle trên là 0, không chứng minh mọi dữ liệu/ngôn ngữ/trạng thái không shift. Bộ ảnh chính không throttle có max CLS 0.3746. LCP loopback ở các màn hình chính có median theo nhóm desktop 1920 khoảng 264ms/mobile 390 khoảng 204ms, **không so ngang** với mạng giả lập hay RUM.

## Backend/API và database

| Endpoint/module | Median ms | p95 ms* | Queries | SQL ms median | Payload bytes |
| --- | --- | --- | --- | --- | --- |
| houses | 1.7 | 2.6 | 1 | 0.09 | 419 |
| rooms | 20.8 | 36.9 | 3 | 0.90 | 39278 |
| room_detail | 2.7 | 3.1 | 5 | 0.23 | 484 |
| tenants_crud | 47.9 | 62.5 | 4 | 3.00 | 319392 |
| tenant_picker | 12.0 | 16.8 | 6 | 5.04 | 2873 |
| tenant_picker_search | 13.9 | 31.4 | 6 | 6.40 | 2873 |
| contract_context | 25.4 | 44.2 | 12 | 0.99 | 31234 |
| services | 2.5 | 3.6 | 3 | 0.18 | 689 |
| meters | 3.0 | 3.1 | 4 | 0.24 | 1155 |
| invoices | 4.0 | 4.5 | 3 | 0.27 | 4069 |
| residence_list | 1.9 | 2.0 | 1 | 0.18 | 117 |
| residence_context | 24.0 | 36.8 | 7 | 0.91 | 54427 |
| dashboard | 25.3 | 47.1 | 13 | 14.47 | 3201 |


Query count ghi nhận ổn định giữa 7 lần cùng dataset. Eager loading dùng ở Room/Tenant lookup; chưa thấy tăng N+1 trong các ca đo này. Không kết luận mọi endpoint miễn N+1: các quan hệ rỗng/lịch sử/permission state khác cần profiler riêng. `residence_list` đang rỗng trong stress fixture; dịch vụ và đồng hồ mỗi loại chỉ có 2 dòng. Không benchmark latency create/generate/import/OTP/payment thật bằng bảng này; các mutation được kiểm tra đúng/sai bởi suite feature và browser fixture riêng.

MySQL: TCP 3306 có phản hồi; kết nối read-only bị từ chối mã 1045. Do đó chưa xác minh query plan, rows examined, filesort, index selectivity, lock contention, transaction contention, IO hoặc concurrency. Không thêm index/migration dựa trên suy đoán. Source có FK/index và phân quyền, nhưng cần DB local/staging đúng credentials cùng dataset được phép trước khi kết luận sức chịu tải SaaS nhiều landlord.

## Responsive và UI/UX

| Viewport | Ảnh viewport | Lỗi JS lúc render | Tràn ngang cấp document |
| --- | --- | --- | --- |
| 1920×1080 | 49 | 0 | 0 |
| 1366×768 | 49 | 0 | 0 |
| 768×1024 | 49 | 0 | 0 |
| 390×844 | 49 | 0 | 0 |
| 360×800 | 49 | 0 | 0 |
| 320×568 | 49 | 0 | 0 |


Không tràn document ≠ không cần cuộn ngang. Table Bill có scroll container 550px; CT01/ở nhờ và hợp đồng có bố cục giấy in cố định, cần cuộn/preview theo thiết kế. Đã xem ảnh đầu trang và ảnh toàn trang của các form dài. Contract Detail ở 390px dài khoảng 7.976px với 13 mục bàn giao; thao tác cuối trang có Toast nổi trong regression, nhưng điều hướng giữa section còn nhiều cuộn.

Desktop 1920/1366: dashboard chia cột, sidebar cố định, bảng có không gian; form `max-w` có khoảng trắng bên phải là lựa chọn độ dài dòng, không tự kết luận lỗi layout. Tablet/mobile: sidebar chuyển drawer, user menu và các modal nằm trong viewport ở các trạng thái thử; native keyboard/safe-area phần cứng chưa xác minh.

Các hit target nhỏ và 7 label không liên kết đã đo bằng DOM, không chỉ suy từ ảnh. Chưa chạy audit WCAG đầy đủ. Ảnh 320px TenantPicker cho thấy list 50,9px, trong khi nút Confirm bottom ~535px vẫn nằm trong viewport 568px; vấn đề là khả năng đọc/chọn dòng, không phải nút bị rơi khỏi màn hình.

## Phạm vi theo module

| Module | Màn hình/trạng thái | Bằng chứng | Giới hạn |
| --- | --- | --- | --- |
| 1. Tài khoản | Login, register, forgot password, change password | UI ×6; unit/feature validation | Không gửi mail thật, không OAuth Google thật, không reset tài khoản production |
| 2. Dashboard | Thẻ KPI, chart, bảng nhà trọ, hóa đơn/hợp đồng | UI ×6; API/query measurements; dashboard feature tests | Số hiển thị trong ảnh là fixture; không phải dữ liệu doanh thu thật |
| 3. Nhà trọ | List, detail, edit | UI ×6; backend tests | Tenant/owner permissions qua test SQLite |
| 4. Tạo nhà trọ/phòng hàng loạt | Bước nhà trọ; sinh 20 phòng; regression 50 phòng | UI ×6; back/cancel/floors/double click regression pass | Thao tác ghi chỉ gọi fixture HTTP; không tạo nhà trọ thật |
| 5. Phòng/phân tầng/sắp xếp | List, order dialog, create bulk/KTX, edit | UI ×6; room ordering unit/feature tests | Chưa thử với 10.000 phòng trên MySQL |
| 6. Chi tiết phòng | Người ở, đồng hồ, dịch vụ, hợp đồng, hóa đơn | UI ×6; backend feature tests | Không tự chỉnh dữ liệu phòng thực |
| 7. Người thuê | List/create/detail/edit; scale 100/1.000/10.000 | UI ×6; scale measurement; 206 frontend tests | CRUD list có điểm nghẽn đã đo; không đồng nhất với TenantPicker |
| 8. Import Excel | Mở modal, hướng dẫn/file input; validation/preview/confirm logic | UI ×6; TenantImport frontend/backend tests trong suite | Không upload danh sách người thật; không xác nhận file production |
| 9. TenantPicker | Search/filter/pagination/single/multi/confirm/cancel | UI ×6; browser regression pass; backend authorization tests | 320×568 vùng list quá thấp; MySQL search collation chưa đo |
| 10. Hợp đồng thường/KTX | Create/edit/detail, 13 mục bàn giao | UI ×6; wizard payload regression pass | Một BedContract test fail; cần đối chiếu fixture PRIVATE_ROOM và DORM_BED |
| 11. Mẫu/Demo/PDF | List/new editor; demo room/dorm từ renderer source; print PDFs | UI ×6; source demo; Word/render tests trong suite | Catalog biến editor trong fixture rỗng; chưa duyệt tất cả biến/phiên bản template tùy chỉnh |
| 12. Dịch vụ/đơn giá | List; form thêm/sửa dịch vụ | UI ×6; pricing unit/feature tests | Không benchmark hàng nghìn bậc giá |
| 13. Điện nước | Đồng hồ, readings detail, meter/reading forms | UI ×6; meter/reading tests; API query metrics | Không đọc đồng hồ hay gửi chỉ số thật |
| 14. Hóa đơn/Bill | List/create/edit/detail/print; QR Bill | UI ×6; QR decode/PDF regression pass | Một nhóm test invoice fixture cũ fail; chưa xác nhận latency HTTP thật của generator |
| 15. QR/Cài đặt thanh toán | Profile, QR display/crop regression | UI ×6; QR 180×180 ở 320/390/1365; PDF 1 trang | Không kết nối ngân hàng/PayOS thật; không upload QR của người dùng |
| 16. CT01/ở nhờ | List, form dài, print hai tài liệu | UI ×6; draft/reload/error Toast/print browser pass | Không nộp hồ sơ cư trú; không tạo lại dữ liệu đã xóa |
| 17. Shell/Modal/Toast | Sidebar, header, account menu, picker/import/order modals, validation Toast | UI ×6; keyboard/focus and reliability browser pass | Không kiểm thử screen reader thực, Safari/Android native keyboard |


## Regression và độ ổn định

- Frontend: **206/206** test pass; build và lint exit 0. Lint còn 3 warning đã có: RoomForm/RoomTenantForm incompatible-library và InvoiceListPage set-state-in-effect; build cảnh báo main chunk >500kB.
- Backend toàn suite: **623/632 pass, 9 fail, 4.295 assertions**, duration runner ~49,9s (bao gồm setup, không phải latency API). Không bỏ qua fail để báo toàn bộ xanh.
- TenantPicker browser pass: debounce, page/filter, stale response, selected/cancel/multi, ID payload room/KTX/CT01, no all-tenant call, focus/ESC/mobile.
- Bulk browser pass: 50 phòng; back giữ draft; xóa tầng cancel/confirm; rapid clicks chỉ 1 mutation mỗi hành động trong fixture.
- Reliability browser pass: offline, request treo, validation, 422/403/500/network retry, chuyển SPA 9 module, idle 20 phút giả lập, triple click 1 write, session 401 đăng nhập lại không F5. Số listener quan sát document 4→4/window 9→9; không suy ra đã loại mọi memory leak.
- Bill QR browser pass: QR hiển thị 180×180 ở width 320/390/1365, decode đúng payload tổng hợp; PDF một trang; không mutation. Banking app quét/giao dịch thật không thực hiện.
- Residence browser pass: draft/reload, thiếu cấu hình, finish fail có một Toast, chữ ký để trống, CT01 và ở nhờ xuất PDF. Không nộp hồ sơ hay gửi cơ quan nhà nước.

### 9 backend failures cần triage

| Test | Dòng |
| --- | --- |
| Tests\Feature\BedContractApiTest::test_different_beds_can_have_active_contracts_but_the_same_bed_cannot_overlap | 74 |
| Tests\Feature\BoardingHouseWithRoomsApiTest::test_invoice_without_contract_does_not_invent_zero_rent_for_unconfigured_room | 212 |
| Tests\Feature\BoardingHouseWithRoomsApiTest::test_existing_contract_snapshot_still_updates_and_bills_if_room_rent_is_cleared | 232 |
| Tests\Feature\DocumentCodesApiTest::test_auto_and_manual_codes_are_scoped_to_landlord with data set #1 | 43 |
| Tests\Feature\DocumentCodesApiTest::test_auto_skips_manual_collision_and_sequence_rolls_back_atomically with data set #1 | 66 |
| Tests\Feature\DocumentCodesApiTest::test_invoice_code_can_change_only_in_unissued_unpaid_draft | 93 |
| Tests\Feature\DocumentCodesApiTest::test_database_rejects_duplicate_code_even_when_model_validation_is_bypassed with data set #1 | 110 |
| Tests\Feature\DocumentCodesApiTest::test_sequence_is_shared_across_rooms_and_resets_by_year_and_document_type | 127 |
| Tests\Feature\SessionIsolationApiTest::test_switching_owners_scopes_lists_and_returns_404_for_foreign_resources_and_401_for_revoked_token | 30 |


Phân loại từ source và response: 7 ca invoice trong BoardingHouseWithRooms/DocumentCodes kỳ vọng tạo/generate mà không dựng occupancy/contract active phù hợp quy tắc hiện tại; cần rà fixture/expected behavior, không nới business rules. SessionIsolation dừng ở assert count cũ trên object pagination `items/pagination`; không đủ cơ sở gọi đây là leak tenant. BedContract fixture tạo giường trên room mặc định PRIVATE_ROOM rồi kỳ vọng hai DORM_BED active; cần xác nhận room type/fixture và quy tắc thực tế. **Chưa sửa hoặc tuyên bố 9 ca đều là bug production.** Các phần sau vị trí assert fail chưa được chính test đó chạy tới.

## Việc còn cần môi trường thật

1. Cấp credentials trong cấu hình local/staging an toàn (không gửi mật khẩu vào chat); chạy read-only EXPLAIN/slow query và benchmark HTTP với fixture được phép. Chưa có số concurrent users/requests per second đáng tin cậy.
2. Xác minh compression/cache/TLS/TTFB và tài nguyên FPM/database của môi trường triển khai; không dùng static server local để kết luận.
3. Kiểm tra Safari iPhone/Android thật, zoom/keyboard/safe-area, screen reader, contrast và touch scrolling vùng bảng/print.
4. Mail/OAuth/OTP/PayOS webhook/QR ngân hàng thật và import Excel nghiệp vụ trên staging có kiểm soát. Các luồng này không tự chạy production trong audit.
5. Review 9 backend failures rồi lựa chọn scope sửa. Audit này chưa tối ưu, chưa redesign.

## Artifacts và tái lập

- `index.html`: báo cáo và gallery lọc theo viewport/màn hình; click ảnh mở PNG gốc. `REPORT.md`: bản đọc text.
- `viewport/screenshots/` + `extra/screenshots/`: 294 ảnh viewport; `screenshots/`: 198 ảnh full page. `qr-bill-*` và `bulk-*`: bằng chứng regression bổ sung.
- `browser-results.json`, `viewport/browser-results.json`, `extra/browser-results.json`, `ui-probes.json`, `scaling.json`, `mobile-throttled.json`, `backend-metrics.json`, `mysql.json`: dữ liệu thô/phương pháp.
- `build.log`, `lint.log`, `frontend-tests.log`, `backend-tests.log`, các `*-regression.log`: kết quả chạy. Scripts audit ở cùng thư mục chỉ phục vụ local; browser chặn external origin, metrics PHP assert SQLite memory.
- Dùng scripts sau với đúng cwd root và PHP CLI cấu hình test: `browser.mjs`, `browser-viewport.mjs`, `browser-extra.mjs`, `mobile-throttled.mjs`, `probes.mjs`; PHPUnit `-c backend-api/phpunit.xml tmp/full-audit/FullAuditMetricsTest.php`. Chạy các Vite regression cũ tuần tự để tránh xung đột cache. Không chuyển scripts sang URL production.

Git working tree được kiểm tra sau audit; code ứng dụng giữ nguyên commit 80666d8. Các artifacts đang được ignore trong `tmp/`, không được stage/commit.
