# Dashboard — tối ưu giai đoạn 2

Ngày: 25/10/2026, Asia/Saigon. Chỉ sửa Dashboard và các phần phụ thuộc trực tiếp. Không deploy, đổi schema, cấu hình production, load test VPS hay gọi PayOS. Các thay đổi cache/session của lượt trước được giữ nguyên.

## Kết quả chính

- Browser thật, cùng production build configuration/fixture/75ms synthetic API delay: **7 → 1 request `/api/dashboard` mỗi lượt** gồm cold load + 3 lần quay lại + 3 visibility notifications; 3/3 lượt có cùng số đếm. Giảm 6 request/lượt, khoảng 85.7%, trong cửa sổ fresh 30 giây.
- Laravel thật qua kernel, SQLite RAM và cùng fixture: **18 → 17 SQL query/request sau warm-up**. Loại 1 query nạp lại tên nhà đã có ở đầu request, giảm khoảng 5.6% số query của fixture này.
- **Chưa chứng minh giảm latency từng request.** Timing browser gần như không đổi; timing Laravel dao động và median sau sửa còn cao hơn trước ở các lượt đã đo. Không trình bày giảm request/query như bằng chứng request riêng lẻ nhanh hơn.
- Kết quả cuối: **163 frontend tests pass; 55 Laravel tests pass / 413 assertions; 5 browser tests pass; build pass; lint exit 0; Pint và diff check pass.**

Raw samples: [before](dashboard-before-metrics.json), [after và paired comparison](dashboard-after-metrics.json).

## 1. Luồng thực tế đã kiểm tra

`DashboardPage` → TanStack Query key `['dashboard']` → `dashboardApi.getDashboard` (Axios có AbortSignal) → `GET /api/dashboard`, Sanctum → `DashboardController::__invoke` → `DashboardService::summary` → scoped Eloquent queries. `ProtectedRoute` xác minh `/me` trước khi cho private Outlet hoạt động. Login mật khẩu/Google tạo QueryClient riêng qua session layer.

Đã đọc DashboardContent/RevenueChart, DashboardPage và API wrapper, QueryClient/QueryCache/MutationCache, authSession/session/provider/ProtectedRoute, DashboardController/DashboardService/BoardingHouseAccessService, DashboardApiTest và migrations liên quan từ audit trước.

Các phát hiện có bằng chứng:

1. `staleTime: 0` tại DashboardPage làm mọi remount và visibility notification khi visible có thể gọi lại API dù kết quả vừa về. Browser baseline xác nhận 1 cold + 3 revisit + 3 focus = 7 request, không chỉ suy từ source.
2. QueryCache ghi nhận invoice version bằng WeakMap nhưng invalidated dashboard ngay ở lần invoice được đọc đầu tiên. Regression mới thất bại ở hành vi này trước sửa. Một GET đầu tiên chỉ thiết lập baseline; chưa có bằng chứng thay đổi status/amount.
3. DashboardService đã lấy scoped houses đầu request nhưng recent invoices eager-load lại `room.boardingHouse:id,name`. Query log synthetic xác nhận 1 house-name reload/request. Không phải N+1 tuyến tính: các eager loads hiện có và test 100 nhà/1000 phòng vẫn giới hạn query count.

Những điểm giữ nguyên:

- Không thêm React.memo/useMemo: chưa có React Profiler chứng minh render cost là điểm nghẽn. Chart chỉ xử lý 6 tháng; chưa có bằng chứng cần đổi thuật toán/render.
- Dashboard chỉ dùng một endpoint tổng hợp; không có client waterfall giữa nhiều API dashboard để ghép lại. `/me` trước private rendering là auth boundary, không bỏ qua.
- Cold login đo được **2 GET `/me`**, gồm xác minh login rồi guard refetch dữ liệu stale. Giữ nguyên vì thay đổi freshness của guard/auth nằm ngoài phần tối ưu Dashboard cần thiết ở lượt này.
- Không thu hẹp global mutation invalidation: vẫn bảo đảm mọi mutation thành công liên quan dữ liệu có thể làm mới dashboard, thay vì đoán thiếu dependency nghiệp vụ.
- Không cache summary trong Redis/file/database, không memoize shared tenant scope trên server, không sửa aggregate/financial calculation/index.

## 2. Thay đổi nhỏ đã thực hiện

### Frontend freshness và refresh

QueryClient đặt **staleTime 30 giây chỉ cho prefix `['dashboard']`**, không thay default của room/invoice/tenant hoặc toàn ứng dụng. DashboardPage bỏ override staleTime 0, vẫn bật refetch khi focus nếu đã stale, vẫn có polling/retry UI và invalidation sau mutation.

`dashboardPolicy.js` dùng chung mốc 30 giây. Poll interval được tính theo `dataUpdatedAt`: nếu rời dashboard và quay lại ở giây 29, lần refresh tiếp theo còn khoảng 1 giây, không dời thêm 30 giây và kéo dài tuổi cache gần 60 giây. Data đã expired refetch trên remount/focus; sau response mới lịch refresh bắt đầu lại. Background-tab polling không được bật thêm.

Invoice QueryCache chỉ invalidate khi **đã có baseline trong query instance đó** và bộ `[status,total_amount,paid_amount]` thay đổi. Poll không đổi và lần đọc đầu không invalidate. Mutation success vẫn invalidate ngay kể cả dashboard còn fresh; financial change đến từ poll vẫn invalidate. Không sửa cách tính tiền, tạo QR hay invoice/payment API.

### Backend: tái sử dụng tên nhà trong request

`DashboardService` tạo map tên nhà từ scoped houses đã có, bỏ eager-load house-name thừa của recent invoices và đọc tên từ map. Nếu một house mới xuất hiện giữa các query trong cùng request, giữ fallback relation lookup như hành vi cũ. Map chỉ sống trong lần gọi `summary`, không lưu static/cache dùng chung và không thay access scope owner/manager.

Các query aggregate, tiền DECIMAL/BigDecimal, timezone, soft deletes, limit 8 recent invoices/5 contracts và authorization giữ nguyên. Next API request vẫn tính fresh; manager revoke được kiểm tra ngay ở backend như trước.

## 3. Điều kiện và kết quả đo

### Browser trên loopback với API double

Windows local, Chromium 140.0.7339.16, Playwright có sẵn, viewport 1440×1000, một browser context sạch mỗi lượt. Cùng synthetic owner, production build Vite, API delay cấu hình 75ms, 3 lượt trước và 3 lượt sau. Server chỉ bind 127.0.0.1; chặn external requests; không có Laravel/MySQL trong đường HTTP browser này.

Kịch bản cố định: login → dashboard → 3 vòng tenant/dashboard → 3 visibility notifications, hoàn tất trong cửa sổ 30 giây. Notifications visibility được phát tổng hợp trong browser và đi qua focus manager thật, không tuyên bố là thao tác OS focus thực. Khoảng settle 200ms giữa bước giống nhau trước/sau, không tính là latency API.

| Metric browser | Trước | Sau | Diễn giải |
| --- | --- | --- | --- |
| Dashboard request/lượt | 7 | 1 | Cả 3 lượt mỗi bên cùng số đếm |
| Trong đó cold | 1 | 1 | Không né lần tải đầu |
| Revisit / focus | 3 / 3 | 0 / 0 | Cùng session, data còn fresh |
| Cold `/me` request/lượt | 2 | 2 | Giữ nguyên auth boundary |
| Median HTTP dashboard duration | 90.9ms, n=21 | 90.3ms, n=3 | API double có delay 75ms, **không phải latency Laravel** |
| Min–max HTTP duration | 82.2–96.6ms | 87.3–92.2ms | Không đủ mẫu cho kết luận tail latency |
| Median submit-login → dashboard ready | 869.208ms, n=3 | 883.897ms, n=3 | Bao gồm login/guard/network/browser automation; không cải thiện rõ |
| Median warm navigation → dashboard heading | 67.954ms, n=9 | 65.525ms, n=9 | Trước sửa UI cũng có thể hiển thị cache khi background-refetch; không coi chênh nhỏ là lợi ích chắc chắn |

Count và browser timings là **đo thật trên môi trường synthetic**. Delay/API payload là mô phỏng. Không có throughput/VPS capacity hay Lighthouse kết luận từ bài đo này.

### Laravel kernel với database test thật trong RAM

PHP 8.3.30; APP_ENV testing, SQLite `:memory:`, DB_URL rỗng, mail/cache/session array. Fixture: 1 synthetic owner, 10 houses, 100 rooms, 100 invoices và 100 cash payments, 1 active contract/occupant; business date cố định 16/10/2026. Reset auth guard mỗi request, loại 1 warm-up, đo 7 request; query log chỉ xuất số lượng/time, không bindings hoặc PII.

| Metric | Baseline ban đầu | Sau, lượt độc lập | Paired trước | Paired sau |
| --- | --- | --- | --- | --- |
| Warm SQL count/request | 18 | 17 | 18 | 17 |
| House-name reload/request | 1 | 0 | 1 | 0 |
| Median kernel request | 16.144ms | 26.005ms | 53.746ms | 57.409ms |
| Median tổng SQL time | 2.90ms | 5.51ms | 8.32ms | 8.80ms |
| Kernel min–max | 15.208–21.534ms | 25.269–32.346ms | 23.969–68.714ms | 20.716–69.044ms |

Do timing giữa các lượt thay đổi nhiều, đã đo thêm **paired comparison cùng process, cùng database/fixture**, đổi thứ tự before/after luân phiên. Baseline snapshot là DashboardService trước tối ưu tại revision `cce6356`, chỉ được nạp khi bật `DASHBOARD_COMPARE_BASELINE`; không dùng trong application runtime.

Số query giảm ổn định. **Median timing sau vẫn cao hơn trước**, kể cả paired nhỏ; không có bằng chứng thống kê rằng backend latency đã giảm. N=7 và môi trường local không cố định CPU; không kết luận tăng tốc MySQL hoặc bỏ qua số đo bất lợi. Giữ thay đổi backend ở mức loại round-trip thừa đã chứng minh, không triển khai thêm các thay đổi query phức tạp dựa trên timing này. Cần staging MySQL ổn định để xác minh latency trước khi mở rộng tối ưu.

Kernel request time bao gồm auth/service/JSON/framework trong test process, không có Nginx/TLS/network PHP-FPM. SQL time là số driver báo trên SQLite, không đại diện MySQL. Không báo p95/p99 có ý nghĩa từ số mẫu nhỏ này.

## 4. Regression và isolation

- QueryObserver thật + fake clock: fresh remount/focus không refetch; expired data refetch; mutation vẫn invalidate ngay; interval khi quay lại ở giây 29 còn 1 giây.
- Invoice: initial read không invalidate, thay status/paid_amount invalidate; poll không đổi và mutation fail không invalidate.
- Dashboard rendering: KPI, tiền, date, empty states, manager UI, retry và escaping vẫn pass.
- Laravel: query budget 17 warm queries, không house-name reload, tổng tiền và recent limits đúng; tests scope owner/manager, revoke grant, soft-delete exclusion và query count không tăng theo số nhà pass.
- Browser mới: warm remount không gọi lại; fast-forward clock 30 giây nhận tổng phòng mới qua HTTP; B login phải tải dashboard riêng và không nhận total/cache A.
- Tất cả browser A→logout→B, route, refresh, response A muộn 200/401 và giữ thiết lập trình duyệt từ lượt trước đã chạy lại, pass.

Cache key `['dashboard']` không tự chứa landlord ID, nhưng **QueryClient đã tách theo phiên đăng nhập** và session cũ abort/clear. Không có shared cache phía server. WeakMap invoice version cũng nằm trong client riêng. Vì vậy TTL mới không mở đường dùng cache A cho B. Manager có cùng user/phiên xem tập nhà được phân công theo access service; không dùng cache chỉ theo landlord.

## 5. File thay đổi giai đoạn này

| File | Nội dung |
| --- | --- |
| `front-end/src/api/dashboardPolicy.js` (mới) | TTL/interval policy chỉ Dashboard |
| `front-end/src/api/queryClient.js` | Dashboard defaults; invoice baseline/change invalidation |
| `front-end/src/pages/dashboard/DashboardPage.jsx` | Áp dụng policy, giữ focus/poll/retry |
| `front-end/src/api/queryClient.test.mjs` | Freshness, expiry, mutation và invoice regression |
| `front-end/src/components/dashboard/DashboardFlow.test.mjs` | Kiểm tra wiring refresh policy và UI cũ |
| `backend-api/app/Services/DashboardService.php` | Tái sử dụng house names trong request |
| `backend-api/tests/Feature/DashboardPerformanceTest.php` (mới) | Fixture, query budget, output invariants, metrics opt-in |
| `backend-api/tests/Fixtures/Performance/DashboardServiceBefore.php` (mới) | Snapshot baseline chỉ cho paired benchmark |
| `tests/e2e/dashboard-measure.browser.mjs` (mới) | Cùng kịch bản browser đo trước/sau, không assertion latency cứng |
| `tests/e2e/synthetic-session-server.mjs` | Delay/timings và test-only control đổi tổng phòng |
| `tests/e2e/session-isolation.browser.mjs` | Thêm Dashboard freshness/poll/isolation regression |
| `tests/e2e/README.md` | Hướng dẫn chạy đo/regression Dashboard |
| `docs/dashboard-before-metrics.json`, `docs/dashboard-after-metrics.json` (mới) | Raw samples đã đo |
| `docs/dashboard-optimization.md` (mới) | Báo cáo này |

Không ghi đè hoặc gộp mất các thay đổi chưa commit của các lượt cache/session trước.

## 6. Kiểm tra cuối và rủi ro còn lại

- Frontend toàn bộ: **163 passed, 0 failed**.
- Laravel liên quan: **55 passed, 0 failed, 413 assertions**, gồm DashboardPerformance/DashboardApi/SessionIsolationApi/AuthApi/TenantApi/RoomApi/InvoiceApi.
- Browser: **5 passed, 0 failed** trên optimized production build.
- Build: **pass**, output riêng `tmp/dashboard-after-dist/`; không ghi đè dist hiện hữu, không deploy.
- Full frontend Oxlint: **exit 0**, còn một warning có sẵn tại `src/components/forms/RoomTenantForm.jsx:42` về incompatible-library; file ngoài scope nên không sửa.
- Pint `--dirty --format agent` và `git diff --check`: **pass**. Cảnh báo chunk lớn từ trước vẫn còn, ngoài scope.

Dashboard có thể hiển thị dữ liệu cùng phiên vừa cache trong 30 giây thay vì luôn refetch mỗi focus/revisit. Mutation địa phương và thay đổi nhận qua invoice poll vẫn invalidate ngay; thay đổi từ user/device khác hoặc revoke manager phản ánh khi API refresh tiếp theo. Backend vẫn kiểm tra quyền mới ở mỗi request. Không có kỳ vọng refresh tức thời khi tab ẩn/offline; không thay hành vi background polling.

Chưa có MySQL query timing/EXPLAIN, FPM/Nginx metrics, CPU/RAM staging hoặc React Profiler samples. Chưa tối ưu aggregate/index/schema, chưa thu hẹp mutation dependencies, chưa sửa 2 `/me` cold requests. Các điểm đó cần bài đo riêng trước khi thay đổi thêm.
