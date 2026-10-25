# Performance audit — BeeHouse / quản lý nhà trọ

Ngày audit: 25/10/2026, Asia/Saigon. Phạm vi: checkout local `backend-api/` và `front-end/`. Production Ubuntu/Nginx/MySQL và domain `quanlynhatro.com.vn` là thông tin người dùng cung cấp, chưa được xác minh qua truy cập VPS. Không gọi production trong audit này.

## 1. Kết luận và mức độ chắc chắn

Đã hoàn thành kiểm kê route chạy thực tế bằng Artisan, build frontend, phân tích artifact nén, đọc luồng nghiệp vụ/query/migration và chạy một tập feature test cô lập. Chưa hoàn thành benchmark HTTP/MySQL, Lighthouse hoặc đo tài nguyên VPS vì chưa có môi trường synthetic được xác nhận và công cụ browser/k6 cài sẵn. Báo cáo không suy ra sức chịu tải từ code review.

Không sửa application code, dependency, schema hiện hữu hay cấu hình triển khai. Chỉ thêm tài liệu/script audit và build ra `tmp/performance-audit-dist/`. Feature tests dùng SQLite `:memory:` (tạo schema tạm qua cơ chế test), không kết nối hay thay đổi database MySQL hiện hữu. Không tạo dữ liệu thật, không gửi mail, gọi PayOS hay webhook.

Nhãn bằng chứng:

- **Đo/kiểm chứng**: có lệnh thực thi và kết quả bên dưới.
- **Code**: hành vi nhìn thấy trực tiếp trong source; ảnh hưởng định lượng chưa đo.
- **Giả thuyết**: cần profiler/EXPLAIN/trace hoặc browser để xác minh mức độ.

## 2. Số liệu đã thu được

| Hạng mục | Kết quả thực tế | Giới hạn diễn giải |
| --- | --- | --- |
| Runtime local | Windows, Node 20.20.2; PHP CLI 8.3.30; MySQL CLI 8.4.3 | Không đại diện VPS; version server MySQL chưa truy vấn |
| Route Laravel đăng ký | 148 route entries: 56 GET\|HEAD, 47 POST, 30 PUT, 15 DELETE | HEAD gộp với GET, không đếm thành request riêng |
| Sanctum | 131 entries có Authenticate:sanctum, 17 entries còn lại | Source runtime local, không xác nhận revision production |
| Build frontend | Vite 8.3.0, thành công, 2.66s do Vite báo, 2246 modules | Một lần build; thời gian không phải metric trải nghiệm người dùng |
| JavaScript | 1 chunk `index-BvuaA58L.js`, 949,718 bytes | Toàn bộ route import tĩnh; không có route chunk tách riêng |
| CSS | `index-BAp9_vWR.css`, 69,152 bytes | CSS dùng chung gồm print/document styles |
| Gzip Vite báo | JS 257.25 kB; CSS 13.19 kB | Đơn vị kB thập phân, compressor của Vite |
| Gzip Node zlib mặc định | JS 255,603 bytes; CSS 13,107 bytes | Phương pháp khác Vite; không trộn hai số làm baseline |
| Brotli Node mặc định | JS 208,832 bytes; CSS 11,252 bytes | Nén offline; chưa xác nhận Nginx bật Brotli |
| Tổng build output | 1,456,598 bytes; 30 file font phát sinh | Tổng artifact, không phải lượng tải của một trang; font chỉ tải khi CSS/glyph/weight cần |
| PHPUnit | 57 passed, 285 assertions, runner `duration_ms=8038`, exit 0 | DashboardApiTest, TenantFilterApiTest, RoomApiTest; SQLite trong RAM, không phải MySQL/HTTP benchmark |
| MySQL local | TCP 127.0.0.1:3306 có phản hồi | Không chạy SQL/EXPLAIN; chưa xác định database test riêng |

Trong RoomApiTest có log lỗi **cố ý** `Simulated pivot write failure` của test rollback; kết quả cuối cùng passed. Đây không phải lỗi production hay error rate benchmark. Tổng thời gian test bao gồm setup/fixture/assertions; không thể chia số test để suy ra latency API.

Lệnh đã dùng từ root, trừ khi ghi khác:

```powershell
# Chạy trong front-end; output riêng, không đụng dist hiện có
node node_modules/vite/bin/vite.js build --outDir ../tmp/performance-audit-dist
# Chạy từ root
node tests/performance/inspect-build.mjs
# Chạy trong backend-api với PHP CLI local
php artisan route:list --path=api --except-vendor --json --no-interaction
# Chạy trong backend-api, env ép testing/sqlite :memory:/mail array/cache array/session array
php vendor/phpunit/phpunit/phpunit --do-not-cache-result tests/Feature/DashboardApiTest.php tests/Feature/TenantFilterApiTest.php tests/Feature/RoomApiTest.php
```

`php` không có trong PATH; thực tế dùng `C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe`. Lần build qua npm đầu tiên bị sandbox EPERM; lần npm sau truyền sai tham số outDir. Lệnh Vite trực tiếp ở trên chạy thành công sau khi được phép chạy local ngoài sandbox. Không cài thêm analyzer/browser/dependency.

| Metric được yêu cầu nhưng chưa đo | Lý do / bước bổ sung |
| --- | --- |
| HTTP p50/p95/p99, throughput, error rate | Chưa có test API server + token/dataset synthetic được xác nhận; k6 chưa cài trong PATH |
| SQL count/time per request và query plan MySQL | Chỉ đọc source/migration và chạy test SQLite; chưa truy cập database test MySQL |
| Full table scan/filesort/temporary table thực tế | Cần exact query, bindings, cardinality và EXPLAIN trên DB test; không kết luận từ thiếu một index |
| LCP/FCP/CLS/INP/TBT, Lighthouse score | Không có Lighthouse/Playwright/Puppeteer local; chưa có browser trace/profile phù hợp |
| CPU/RAM/FPM saturation/IOPS | Không có SSH/monitoring VPS; không đo CPU/RAM Windows để thay cho VPS |
| Nginx TLS/TTFB/gzip/cache headers | Chưa có cấu hình triển khai trong source được tìm thấy, chưa request production |

## 3. Kiến trúc và endpoint thực tế

React 19 SPA → Axios + TanStack Query 5 → Laravel REST API (framework constraint `^13.17`, Sanctum 4) → Eloquent/MySQL. Frontend dùng Vite/Tailwind, React Hook Form/Zod, Day.js, QR. Backend có Service layer cho billing, hợp đồng, ký, quyền nhà trọ và dashboard. Package versions ở manifest không đồng nghĩa version production.

Danh mục **đầy đủ 148 route** ở [performance-endpoints.md](performance-endpoints.md), sinh từ route:list, gồm method/controller/middleware. Nhóm quan trọng:

| Nhóm | Endpoint đọc thực tế, tiền tố `/api` | Quyền / đặc điểm |
| --- | --- | --- |
| Identity | `/me`, `/dashboard` | Sanctum; dashboard hỗ trợ owner và manager được phân công |
| Nhà/tầng/phòng/giường | `/boarding-houses`, `/boarding-houses/{id}`, `/boarding-houses/{boardingHouseId}/floors`, `/boarding-houses/{boardingHouseId}/rooms`, `/rooms/{id}`, `/rooms/{roomId}/beds` | Nhiều CRUD cũ chỉ owner; không giả định mọi manager gọi được |
| Người thuê/người ở | `/tenants`, `/tenants/{id}`, `/rooms/{roomId}/tenants` | `/tenants` có lọc thực, không có pagination contract |
| Hợp đồng | `/rooms/{roomId}/contracts`, `/rooms/{roomId}/contract-context`, `/contracts/{id}`, `/contracts/{id}/services`, `/contracts/{id}/handover-check-in` | Service áp dụng access scope, snapshot/version/document |
| Mẫu/ký | `/contract-templates`, `/contracts/{id}/signing`, `/contracts/{id}/document`, `/sign/{token}` | Public signing có privacy + throttle; không benchmark ký/OTP |
| Dịch vụ/giá | `/boarding-houses/{boardingHouseId}/services`, `/services/{id}/price-tiers`, `/rooms/{roomId}/services` | Eager load priceTiers tại danh sách dịch vụ |
| Điện nước | `/rooms/{roomId}/utility-meters`, `/utility-meters/{id}`, `/utility-meters/{meterId}/readings` | Latest reading eager loaded, lịch sử chưa giới hạn |
| Hóa đơn/thanh toán | `/rooms/{roomId}/invoices`, `/invoices/{id}`, `/invoices/{invoiceId}/payments` | Manager được đọc ở các endpoint áp dụng access service; không benchmark tạo QR |
| Cư trú | `/residence/boarding-houses`, `/residence/boarding-houses/{id}/rooms`, `/residence-declarations` | Luồng thực ngoài danh sách nghiệp vụ ban đầu; chưa phân trang danh sách |

Các POST `/invoices/{id}/generate`, `/invoices/{invoiceId}/payos`, `/webhooks/payos*`, auth register/reset/OTP có tác dụng phụ; không nằm trong script load đọc. Không tồn tại API toàn cục GET `/api/invoices` hay GET `/api/contracts` trong route inventory: frontend điều hướng chọn nhà/phòng trước. Không thiết kế benchmark dựa trên các endpoint giả định này.

## 4. Mười vấn đề ưu tiên

Không có **Critical đã được xác minh** qua audit hiện tại. High là rủi ro từ source hoặc bundle đã đo, không phải tuyên bố production đang chậm. Độ phức tạp: S ~ nhỏ, M ~ vừa, L ~ cần thay đổi contract/phối hợp nhiều lớp; không phải cam kết thời gian.

| # / Mức | File / bằng chứng | Nguyên nhân và tác động | Hướng cải thiện đề xuất, chưa triển khai | Độ khó / đo lại |
| --- | --- | --- | --- | --- |
| 1 High | `front-end/src/routes/AppRoutes.jsx`, toàn bộ import; bundle đã đo | Mọi màn hình, editor, print/ký cùng chunk 949,718 bytes; trang login phải tải/parse cùng graph | Route-level `React.lazy`/Suspense; tách tài liệu/in/QR; đo module graph trước khi tách vendor | M; so initial JS gzip, cold navigation LCP/TBT và tổng chunk khi đi route |
| 2 High | `backend-api/app/Http/Controllers/Api/{Room,Tenant,Invoice,Contract,UtilityReading,ResidenceDeclaration,BoardingHouse}Controller.php`, các `index`; frontend list `.map` | `get()` không giới hạn: tăng payload, PHP hydrate/serialize, DOM và memory theo lịch sử/dataset. RoomDetail tải tất cả invoice chỉ để `.slice(0,3)` | Pagination có cap, stable tie-breaker và metadata; endpoint/params summary cho preview; UI pagination hoặc virtualization sau đo | L; p95, query rows/bytes, PHP peak memory, DOM nodes ở 100/1k/10k records |
| 3 High, rủi ro isolation cần tái hiện browser | `front-end/src/layouts/MainLayout.jsx:55`, `pages/auth/LoginPage.jsx:24`, `api/contractDocumentCache.js`, query keys | Logout/login chỉ xóa một số prefix; `tenants`, `boarding-houses`, `rooms`, `contracts`, `invoices`, `payments` còn trong QueryClient và keys không có user/tenant. Khi đổi A→B trong cùng tab, cached list có thể render trước refetch; API scope vẫn có thể đúng | Cancel toàn bộ in-flight query và clear cache riêng tư khi identity đổi; hoặc QueryClient/session namespace riêng. Không nâng staleTime trước khi xử lý identity | M; browser A→logout→B cùng tab, chặn/delay network, xác minh không thấy fixture A; chưa chạy trong audit |
| 4 High | `TenantController.php:23–107`, `Http/Requests/Tenant/TenantIndexRequest.php` | Contains LIKE `%q%` trên 4 cột + nhiều EXISTS và latest; index phone/identity đơn không giải quyết leading wildcard. Lọc residence/contract có scope đúng nhưng chi phí có thể lớn | Giữ scope landlord; giới hạn trang, tách exact/prefix cho phone/identity, thiết kế search theo semantics tiếng Việt; cân nhắc index sau EXPLAIN | M/L; test q có/không match, lọc kết hợp, rows examined/p95 khi một landlord lớn |
| 5 High | `Services/DashboardService.php:48`, `pages/dashboard/DashboardPage.jsx:6`, `api/queryClient.js:9` | Nhiều aggregate qua scoped subquery mỗi request, không cache summary; poll 30s + focus + mọi mutation invalidate. Query count có thể cố định nhưng lượng rows/CPU tăng | Đo riêng query time; gom/reuse aggregate hoặc summary ngắn hạn có key user/access/period/timezone và invalidation đúng khi revoke manager. Không cache chỉ theo landlord | M/L; cold/warm dashboard p95, SQL total time, QPS khi nhiều tab/owner và revoke quyền |
| 6 Medium | `RoomController.php:21`, `Services/RoomOrderingService.php:13` | Fetch toàn bộ room, correlated counts + 6 EXISTS/room, PHP natural sort O(n log n). Đây không phải N+1 round-trip nhưng DB vẫn làm probe theo row; index sort hiện tại không tự loại PHP sort | List projection nhỏ; đánh giá materialized sort key giữ đúng natural/floor order; paginate trên thứ tự ổn định; chỉ tính history khi cần | M/L; query plan, sort PHP time, memory ở 200/1000/5000 phòng/nhà |
| 7 Medium | `Services/BillingService.php:19–145`, `InvoiceController.php:143`, `UtilityReadingController.php:64` | Trong loop mỗi service có count người/opening/meter/2 reading queries; giữ room/invoice lock trong tính tiền. `whereDate` bọc DATE có thể cản date-range index. Không khẳng định N+1 tại GET list đã eager load | Prefetch/group counts/openings/meters/readings cho invoice; direct date comparisons nếu cột DATE và semantics tương đương; giữ transaction và tính đúng tiền | L; query count/time theo 2/10/50 services; lock wait p95, hồi quy billing VAT và rounding; chỉ sandbox writes |
| 8 Medium | `RoomOrderingService.php:32–63`, `RoomController.php:storeBulk/reorder`, `BoardingHouseController.php:storeWithRooms` | Mỗi room tạo gọi house lock/MAX lại; reorder một UPDATE/ID, cap 5000 IDs; batch create cap 200 vẫn giữ lock cùng house. Chủ trọ khác không nhất thiết bị cùng row lock | Cấp sort range một lần dưới lock; batch update theo chunk với validation/atomicity giữ nguyên | M/L; so query count và lock wait 10/100/200 create, 100/1000 reorder; không chạy trong audit |
| 9 Medium | `pages/payments/PayOSReturnPage.jsx:15`, `pages/invoices/InvoiceDetailPage.jsx:63` | Poll 5s chỉ dừng khi PAID; return cancel vẫn có thể poll, lỗi/UNPAID kéo dài tải invoice detail + relations lặp lại | Dừng cancel/terminal/error, deadline và backoff có jitter; cân nhắc status projection chỉ sau thiết kế API (hiện chưa có endpoint đó) | M; request count cho cancel/unpaid/error trong 5 phút, p95 và số poll sau deadline |
| 10 Medium | `front-end/src/api/axiosClient.js:2`, phần lớn wrapper API | Không đặt timeout (Axios mặc định 0), đa số wrapper không nhận AbortSignal. Slow request có thể tồn tại qua navigation, tăng công việc server và UI chờ | Timeout theo lớp endpoint, propagate signal như tenantApi đã làm; không tự retry financial mutations | S/M; giả lập server chậm/hủy navigation; pending request count, thời gian thoát loading, lỗi timeout rõ ràng |

## 5. Những điểm bổ sung theo lớp

### Frontend

- **Tốt, Code:** `TenantListPage` debounce 350ms, `keepPreviousData`, signal truyền qua `tenantApi`; `RoomListPage` house/rooms query song song; room/utility keys đã canonicalize string ID. Không coi StrictMode dev là bằng chứng API production gọi đôi.
- **Medium, Code:** `boardingHouseKeys.detail`, `contractKeys.byRoom/detail`, `invoiceKeys`, `paymentKeys` giữ ID nguyên kiểu; route string và model number có thể tạo cache entry khác/invalidation không khớp. Chuẩn hóa cùng kiểu sau khi kiểm tra call sites; đo request waterfall/cache hit khi chuyển list→detail→edit→back.
- **Medium, Code:** `RoomServicesSection` và `RoomUtilitiesSection` cùng query meters/services với cùng canonical key. TanStack Query có thể dedupe khi overlap; staleTime mặc định 0 có thể refetch khi observer mount sau khi query xong. Không gọi đây là request trùng đã đo. HAR cần xác minh. `ProtectedRoute` `/me` chặn outlet; RoomDetail sau room response mới mount occupants/services/meters là một lớp phụ thuộc thực. Room, contract, invoice query đầu chạy song song; không ghi thành waterfall toàn bộ.
- **Medium, Code:** `ContractService::context` đã gom context để giảm waterfall nhưng trả toàn bộ người thuê của landlord (id/name/phone). Chuyển autocomplete có scope/cap nếu data lớn; context template/service vẫn phải giữ nghiệp vụ snapshot.
- **Medium, Giả thuyết:** components source lớn: InvoiceDetailPage 20,388 bytes, ContractWizard 18,714, ResidenceDeclarationForm 18,017, RoomDetailPage 15,541, ContractDetailPage 14,339. Nhiều JSX nén thành dòng dài, LOC không phải render cost. InvoiceDetail dựng cả mobile cards và desktop table rồi ẩn CSS, làm hai cây DOM. Profile với React DevTools/Performance trước khi dùng memo; inline props/handlers khiến memo cần thiết kế ổn định, không thêm blanket useMemo.
- **Low, Code/Artifact:** 30 font files emitted do 5 weights × 3 subsets × 2 formats, import từ print/ký document. Tổng emitted không bằng tải mạng. Ưu tiên tách route styles, chỉ subset/weights thực dùng sau trace. `src/assets/hero.png` không xuất hiện trong build nên không đổ lỗi asset đó.

### Backend, middleware, serialization, tenant

- Eager loading hiện có ở rooms (amenities/floor), contracts (tenant/bed), services (priceTiers), meters (service/latestReading), dashboard bounded recent lists. Không tìm được N+1 điển hình đã xác minh trên các GET danh sách chính. DashboardApiTest có test query count không tăng khi từ 1 lên 100 nhà/1000 phòng và đã passed; không có numeric query count/time xuất ra để báo cáo.
- Authorization phần lớn là relation/whereHas/whereIn trong controller và `BoardingHouseAccessService`, không có lớp `app/Policies` riêng trong inventory. Không có policy không đồng nghĩa thiếu quyền. Dashboard/contracts/residence/invoice read có manager assignment; rooms/tenants/services legacy owner-only dùng `$user->landlord`. Khi test manager, phản hồi 403/404 hoặc lỗi do thiếu landlord phải phân loại chức năng/quyền, không nhập vào success latency của owner.
- Form Requests validate và controller/service tiếp tục scope resource; `authorize() => true` không tự tạo lỗ hổng nếu resource được scope sau đó. Bulk room create giới hạn 200, reorder giới hạn 5000. Cần benchmark validation/DB uniqueness queries với payload test, không xóa validation để giảm latency.
- Sanctum token lookup/user load/landlord load là chi phí cố định cần đo riêng; middleware SetApiLocale nhỏ, chưa có số đo. Signing privacy/throttle chỉ áp dụng route ký; login không có route throttle trong inventory và AuthController::login không dùng RateLimiter, khác forgot-password. **Medium:** đánh giá giới hạn login để tránh hash CPU/token write bị lạm dụng, tách khỏi workload nghiệp vụ (không load login trong script).
- `ApiResponse` serialize trực tiếp model/collection. ContractController list chọn response fields sau `toArray` nhưng query vẫn `SELECT *`, có thể hydrate/cast signing_snapshot lớn. Tenant list `attributesToArray()` trả nhiều trường cá nhân không cần trên table. Invoice show eager loads nhiều relation; chỉ vài lazy loads cho một object không phải list N+1. Đề xuất projections/resources có contract rõ, cân đối privacy và payload. Đo SQL bytes/PHP peak memory/JSON size trước/sau.
- `AppServiceProvider` chưa bật preventLazyLoading trong dev; cân nhắc query budget regression sau đo. Không thêm debug toolbar/SQL bindings log production.

### MySQL và index: chỉ xác nhận từ migrations

Chưa đọc `SHOW INDEX` trên MySQL; migrations không chứng minh index đã tồn tại trên production. FK thường được MySQL index tự động; không đề xuất thêm lại chỉ dựa trên việc source không có `$table->index`.

| Query shape | Index nhìn thấy trong migrations | Điều cần kiểm chứng / candidate, chưa áp dụng |
| --- | --- | --- |
| Ownership | landlords.user_id unique; boarding_houses.landlord_id FK; managers unique(house,user) và index(user,house) | EXISTS owner OR manager cần EXPLAIN cho cả owner và manager nhiều assignment |
| Room list/sort | unique(boarding_house_id,room_code); index(boarding_house_id,floor_id,sort_order) | Soft-delete predicate và floor/natural-code comparator; index không thay được comparator PHP hiện tại |
| Occupancy | room_tenants(room_id,status,tenant_id), (contract_id,status), (bed_id,status) | Có thể hỗ trợ room occupancy; tenant filter đi ngược qua tenant_id cần thử (tenant_id,status,room_id), không tự thêm |
| Tenant search/order | landlord_id FK, phone, identity_number đơn | Candidate (landlord_id,deleted_at,created_at,id) cho unfiltered page/order; wildcard vẫn không có range seek hiệu quả trên phone/email/name |
| Invoice | (room_id,billing_period,status), (status,due_date,room_id), landlord/code unique sau migration mới | Period index giúp list; dashboard CASE + whereColumn(amounts) có thể cần filesort/residual filter. Không khẳng định full table scan |
| Contract | room_id/tenant_id/status/end_date đơn, (start_date,end_date), (bed_id,status) | Candidate (room_id,status,end_date) hoặc (room_id,deleted_at,id) tùy measured hot query; không thêm cả hai mù quáng |
| Readings | unique(utility_meter_id,reading_date) | Phù hợp history + boundary reading khi dùng range trực tiếp; xác minh DATE wrapper và latestOfMany SQL |
| Payments | invoice_id, paid_at, payment_method; unique(method,external_reference) | Candidate (invoice_id,paid_at) cho history/revenue; thử join order + time range, chọn theo selectivity |

`tests/performance/explain-templates.sql` cung cấp SELECT shape tương ứng source và EXPLAIN FORMAT=JSON dưới transaction read-only/timeout 2s. **Chưa chạy**, có ghi rõ các scope/eager-load bị lược trong template. Bước kế tiếp phải capture exact SQL + bindings trên synthetic request, bao gồm soft deletes, manager access, correlated count, eager-load window query; chạy EXPLAIN exact SQL ấy. Không dùng template rút gọn để tuyên bố plan thực tế. Không chạy EXPLAIN ANALYZE trong audit vì nó thực thi query.

## 6. Kiểm tra theo luồng nghiệp vụ

| Luồng thực tế | Đánh giá source và tình trạng đo |
| --- | --- |
| Dashboard/thống kê | Tổng hợp occupancy, contract, invoice month/debt và revenue 6 tháng; bounded 8 invoice/5 contract gần nhất. Feature tests passed. Chưa đo MySQL SQL time hoặc latency |
| Nhà/phòng/tầng/KTX | List house/room không phân trang; order floor/natural/sort_order trong PHP; beds lấy active memberships+tenant có eager load. Build/code inspected; chưa đo 5000 rooms |
| Tìm kiếm/sắp xếp | Tenant có filter thật q/boarding_house_id/room_id/occupancy_status/role/contract_status; có debounce và cancellation. Room list hiện không có server search/sort params; reorder là PUT riêng, không giả định sort query |
| Người thuê | Tenant scope landlord và membership filters tránh ghép khác row; tests filter/isolation passed. Lịch sử người ở và form chọn người thuê còn có list unbounded |
| Hợp đồng/tài liệu/ký | Context giảm nhiều client calls nhưng toàn bộ tenants; list query lấy snapshot trước map response. Detail eager load nhiều relation; template HTML/document có payload riêng. Không kích hoạt ký/mail/OTP |
| Dịch vụ/bảng giá | List eager load tiers; room services/meter dùng shared keys có thể dedupe. Chưa thấy N+1 ở list; cache catalog TTL cân nhắc sau xử lý identity |
| Chỉ số điện nước | Latest reading eager loaded; history toàn bộ và client `.map`; tier query phụ thuộc meter response khi TIERED. Đề xuất giữ đọc hiện tại/trước khi phân trang để không sai consumption |
| Hóa đơn | List theo room, sort billing period; preview tải tất cả rồi lấy 3. Detail + payments song song; chưa đo payload/cost poll. Billing generate có loop và locks, chỉ review |
| Thanh toán | Owner writes và manager reads tách; không tạo payment/QR/webhook. Poll terminal/deadline cần xử lý. Không gom quyền từ chối vào performance error |
| Cư trú/in biểu mẫu | Scope owner/manager có service; list không giới hạn, components/doc styles lớn. Chưa đo DOM/print timing; đưa vào browser plan |

## 7. Hạ tầng production cần kiểm tra ở lần đo có quyền

Không có config Nginx/FPM/MySQL/VPS thực tế để kết luận. Kiểm tra read-only sau: Nginx asset cache immutable theo hash, SPA fallback, API/private response cache, gzip/Brotli, TLS/HTTP2; FPM max_children/backlog/slowlog và OPcache; MySQL buffer pool/connection/slow query digest/lock waits; queue mail/OTP không chạy sync request nếu thiết kế cho phép. Không dùng `nginx -T`, env dump hay log thô trong báo cáo vì có thể lộ cấu hình nhạy cảm. Không chạy optimize/reload/migration hay đổi production config.

Quy trình, SLO dự kiến, dữ liệu synthetic và lệnh k6 nằm ở [performance-test-plan.md](performance-test-plan.md). Mọi latency/throughput/CPU mục tiêu trong kế hoạch là **mục tiêu thử nghiệm**, không phải kết quả đo. Audit dừng ở đây, chưa áp dụng đề xuất tối ưu.
