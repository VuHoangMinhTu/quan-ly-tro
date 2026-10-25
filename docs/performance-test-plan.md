# Kế hoạch benchmark và load test

Ngày: 25/10/2026. Kế hoạch dựa trên route inventory local trong `performance-endpoints.md`; metric hiện có và hạn chế ở `performance-audit.md`. Các mức/SLO dưới đây là đề xuất, **chưa phải kết quả đo**.

## 1. Các giai đoạn và điểm dừng

1. **Đã thực hiện:** route inventory, source/migration review, build, gzip/Brotli offline, 57 feature tests SQLite trong RAM. Không load test production.
2. **Đã chuẩn bị, chưa chạy k6:** bộ GET-only smoke local, fixture contract, EXPLAIN templates và kiểm tra an toàn script offline. Không có request HTTP benchmark thực tế.
3. **Cần môi trường riêng:** xác nhận MySQL database synthetic có tên riêng như `perf_audit_*`, backend local chỉ vào database đó, tắt/fake outbound mail/PayOS/webhook, ghi rõ revision và cấu hình. Không dùng dữ liệu người thuê clone từ production. Không tự chạy migrate/seed vào database đang dùng. Việc chuẩn bị dữ liệu chỉ thực hiện trên DB disposable được xác định rõ.
4. **Sau khi smoke ổn:** baseline 2/5 VU local trên MySQL, rồi staging cô lập với monitoring. Mức vượt 5 VU hoặc kiểm thử ghi/concurrency cần một lượt được chấp thuận riêng; không nằm trong script smoke này.
5. **Production:** không nằm trong kế hoạch thực thi hiện tại. Không trỏ k6 vào domain thật, không tunnel loopback đến DB/API thật. Kiểm tra read-only config/metrics chỉ khi có quyền truy cập hợp lệ.

## 2. Dữ liệu synthetic và fixture contract

Không cung cấp seeder tự động chạy để tránh vô tình ghi nhầm DB và bỏ qua invariant nghiệp vụ. Dùng factories và workflow fixture trong `backend-api/database/factories/`, `tests/Feature/{DashboardApiTest,TenantFilterApiTest,RoomApiTest,BillingServiceApiTest,ContractLifecycleApiTest}.php` làm chuẩn khi dựng DB disposable. Data loader phải guard APP_ENV testing/local + tên DB `perf_audit_*`, từ chối DB_URL/cached config trỏ nơi khác, vô hiệu mail/outbound, và không bootstrap từ credentials production. Không benchmark SQLite thay cho MySQL.

| Dataset đề xuất | Landlord | Nhà / landlord | Phòng / nhà | Người thuê / landlord | Lịch sử |
| --- | --- | --- | --- | --- | --- |
| Smoke nhỏ | 2 | 1 | 10 | 20 | 3 tháng invoice và 12 readings/meter |
| Baseline | 10 | 2 | 50 | 150 | 24 tháng invoice, 2 meters/room × 36 readings, 4 services/house |
| Scale / skew | 100 | 2 (thêm 1 owner lớn) | 50; owner lớn 1 nhà × 1000 phòng | 200; owner lớn 10,000 | 36 tháng, 2 meters/room × 120 readings, 8 services/house |

Ví dụ scale 100 owner thường tạo khoảng 10k rooms, 360k invoices, 2.4 triệu readings trước owner lớn. Đây là **kích thước thiết kế**, chưa sinh/chạy trong audit. Tăng theo checkpoint và giới hạn dung lượng; không tự tạo hàng triệu rows trên máy đang dùng.

Mỗi owner cần private room và DORMITORY/bed, floor/null floor, active/moved-out tenants, draft/active/expired contracts, FIXED/PER_PERSON/PER_UNIT/TIERED services, nhiều bậc giá, unpaid/partially-paid/paid/cancelled invoices và payments synthetic. Dùng DECIMAL/BigDecimal đúng nghiệp vụ, không giả paid_amount thiếu payment đối ứng. Ngày cố định và phân bố 70/20/10 occupancy, có due_date null/overdue, soft-deleted records để kiểm tra scope. Names có dấu, tenant search với từ khóa synthetic, exact phone và no-match. Số điện thoại/CCCD/email chỉ là dữ liệu giả; email `example.invalid`.

Manager matrix riêng: M được phân công nhà A1 nhưng không A2/B1; manager không có landlord; revoke assignment và kiểm tra ngay sau revoke. Không nhập test owner-only endpoint thành lỗi hiệu năng manager. Script smoke hiện chỉ chạy **owner độc lập**, không thay thế matrix manager/browser.

`tests/performance/fixtures.example.json` là **mẫu**, IDs không phải tài nguyên đã tồn tại. Copy ra `fixtures.local.json` (đã ignore) hoặc đường dẫn ngoài repo rồi điền ID synthetic thật. Mỗi `*Ids` phải là allowlist **đầy đủ các record có thể được response trả về** của owner, không chỉ một sample; arrays hợp đồng/hóa đơn/readings/payment đều phải không rỗng. Phần dashboard là expected counts của synthetic dataset. Không put token vào JSON; chỉ lưu tên env `PERF_TOKEN_A/B`, thêm env tương ứng khi chạy. Hai user/landlord/token env độc lập và ID không overlap trong cùng loại tài nguyên.

Fixture đầu tiên của mỗi loại phải liên kết đúng: `houseIds[0]` sở hữu `roomIds[0]`; `meterIds[0]` thuộc room; `invoiceIds[0]` thuộc room và có các payments tương ứng. Allowlists có thể chứa toàn bộ các nhà/phòng của owner. Script nhận 2–10 owner để giữ preflight có giới hạn; dataset 100 owner có thể chọn một cohort 10 owner, gồm owner lớn và owner nhỏ. Không log response body, SQL bindings hoặc banking profile. Chỉ issued token cho user test đã verify, tạo ngoài thời gian đo; không login/register trong load loop.

## 3. Chạy script đã chuẩn bị

Từ root, sau khi có k6 local được cài hợp lệ và đã xác nhận server localhost chỉ dùng synthetic DB:

```powershell
# Kiểm tra artifact build và safety harness: không network/database
node tests/performance/inspect-build.mjs
node tests/performance/validate-harness.mjs

# Tokens được cung cấp qua cơ chế secret/env, không paste vào command history/log.
# Chỉ chạy sau khi PERF_TOKEN_A và PERF_TOKEN_B đã được inject.
$env:PERF_BASE_URL = 'http://127.0.0.1:8000/api'
$env:PERF_DATASET_CONFIRMED = 'synthetic-test-only'
$env:PERF_FIXTURES = './fixtures.local.json'
$env:PERF_VUS = '1'
$env:PERF_SECONDS = '30'
k6 run tests/performance/read-only.k6.js
```

`open()` resolve fixture relative to k6 script, vì vậy `./fixtures.local.json` ở `tests/performance/`; nếu cần dùng absolute path. Không có token/fixture thì script từ chối chạy. Không có production override: base chỉ chấp nhận literal `http://127.0.0.1:PORT/api`, tắt redirects, chỉ dùng GET. Base loopback không tự chứng minh dữ liệu an toàn: người vận hành phải xác nhận DB và không dùng proxy/tunnel tới production.

Mặc định 1 VU/30s, hard guard env 1–5 VU và 10–120s, timeout từng request 5s, nghỉ 1s giữa workload; isolation probe bổ sung sau mỗi 5 iteration và có nghỉ. Preflight gọi `/me` cho mọi owner, rồi 12 workload endpoints + 12 foreign probes cho mỗi owner A/B đầu tiên: tổng 50 GET với 2 owner (58 với 10 owner), có nghỉ 0.2s giữa probe, setup timeout 60s. Default 1 VU luân phiên A/B; muốn concurrent landlords dùng 2 VU trở lên. Không override scenario/rate/threshold bằng CLI hoặc K6_* environment để vượt giới hạn.

Tỷ lệ workload default là round-robin **12 loại GET**, chọn resource đầu tiên để baseline dễ tái lập: dashboard, houses, rooms, tenants, tenant search, contracts, services, meters, readings, invoices, invoice detail, payments. Đây là endpoint mix có cache nóng, không mô phỏng hoàn toàn traffic người dùng. Tập lớn cần biến thể chọn seeded random resource/house thay vì chỉ đầu tiên, và trọng số dự kiến dashboard 15%, rooms 20%, tenants/search 20%, contracts 10%, invoice/detail/payment 20%, utilities 10%, services 5%; ghi lại seed/mix và không so với default round-robin như cùng bài test.

Script kiểm tra 404 cho tài nguyên foreign cả hai chiều ở preflight; kiểm tra response allowlist + landlord_id nếu field có mặt; dashboard kiểm tra IDs và 3 aggregate counts. Abort ngay khi thấy ID bất ngờ, foreign response khác 404, sai token/fixture hoặc transport timeout. Đây không chứng minh toàn bộ nested content/aggregate đúng: cần backend functional matrix và browser cache-switch test riêng. 404 probe mong đợi có `expectedStatuses(404)` nên không bị tính là error thông thường; 401/403/404/422 ở owned request là lỗi fixture/quyền, abort và không diễn giải thành latency thành công.

Script không gọi PayOS, payment write, webhook, email, OTP, public signing, tài liệu HTML, QR ảnh hay URL ngoài base. Các endpoint GET hiện được kiểm tra source là đọc; vẫn phải kiểm tra revision sau này nếu GET đổi hành vi.

### Ngưỡng dừng

- Bất kỳ sai cách ly dữ liệu: dừng ngay, không log body, chỉ điều tra riêng.
- Timeout/transport failure hoặc latency đơn ≥5s: dừng ngay.
- Workload unexpected error rate ≥1% hoặc p95 ≥2000ms: abortOnFail sau 10s; dưới 1% không phải SLO đã được thống nhất. Threshold tích lũy có thể bỏ lỡ spike ngắn, nên quan sát sliding windows ngoài k6 trước test lớn.
- Manual stop nếu FPM queue xuất hiện kéo dài, CPU >80% trong 30s, memory >80%, swap tăng hoặc lock wait/deadlock tăng. Các ngưỡng hạ tầng này cần monitoring, script không tự đọc VPS.
- 1 VU smoke ít mẫu: dùng để bắt lỗi/rủi ro, không dùng p99 làm kết luận thống kê đáng tin.

## 4. Các mức tải đề xuất sau smoke

| Stage | Mức tải | Thời lượng | Điều kiện |
| --- | --- | --- | --- |
| Smoke | 1 VU | 30s + preflight | Defaults script, synthetic nhỏ |
| Concurrency thấp | 2 VU rồi 5 VU | 60s mỗi mức | Local synthetic MySQL, dừng nếu vượt ngưỡng |
| Baseline staging | 5/10/20 VU từng checkpoint | 5 phút/mức, nghỉ phục hồi | Cần chấp thuận và script mở rộng riêng; không dùng script smoke để vượt cap |
| Arrival-rate | 5/10/20 req/s với VU cap | warm-up 1 phút + 5 phút/mức | Staging cô lập, đủ metrics, xem dropped_iterations; không extrapolate closed-loop VU thành capacity |
| Soak | Mức đã pass có headroom | 30–60 phút | Chấp thuận riêng, theo dõi memory leak/queue/lock/DB connections |

Test riêng noisy neighbor: giữ owner nhỏ traffic cố định, tăng owner lớn; so owner nhỏ p95/SQL CPU và tránh nhầm shared DB saturation với cross-tenant data leak. Cold/warm run tách; không flush buffer pool/cache production. Nginx/FPM staging tương đương VPS; `php artisan serve` chỉ phù hợp smoke chức năng, không phải capacity benchmark triển khai.

## 5. Thu metric và so sánh

- k6 xuất workload_latency p50/p95/p99 và per-endpoint threshold submetrics, workload_requests count/rate, workload_errors, auth_or_fixture_errors, isolation_failures. Workload custom latency/request/error loại preflight; HTTP builtin tổng có cả preflight/isolation, không dùng tổng đó làm workload-only throughput. `response_characters` là UTF-16 string length, **không phải bytes**. Dùng HTTP data_received hoặc Content-Length/DevTools để đo network bytes (ghi rõ compression).
- Mẫu report: revision, date/time/timezone, host resources, generator placement, DB server/version/config, dataset/row counts, route/mix, concurrency/rate, warmup, sample count, latency p50/p95/p99, success throughput, unexpected failures theo status, query count/time, response bytes, CPU/RSS/DB lock/FPM queue. Không điền giá trị nếu không đo.
- Lặp 3 lần cùng cấu hình và đủ ≥1000 successful samples/endpoint cho tail latency thăm dò; p99 vẫn cần confidence/variation, không khẳng định chỉ với vài chục mẫu. So cùng tenant cardinality/skew, cùng index/code và cold/warm policy. Giữ failure latency riêng; không chỉ report success khi có failure cao.
- Trên staging được phép: `pidstat -u -r -d 1 60`, `vmstat 1 60`, Nginx request_time/upstream_response_time và FPM status private; xem MySQL performance_schema digest/query count/rows examined/lock wait. Tách DB/query time khỏi network/TLS/JSON. Không kích hoạt logging/reload/config mới nếu chưa được phép.
- Log chỉ tên endpoint chuẩn hóa và số liệu; script bỏ system tag URL và không in token/body. Không bật `--http-debug`, raw HAR/trace hoặc k6 cloud export với secrets. Kết quả local riêng trong thư mục `tests/performance/results/` ignored nếu xuất file; redact trước khi chia sẻ.

## 6. EXPLAIN và N+1

Chưa chạy EXPLAIN. Từ một request synthetic đã auth, capture SQL + bindings trong profiler test-only bằng DB::listen, không lưu PII/token. Tách auth queries, ownership scope, query chính, eager loads và casts/serialization. Dùng exact SQL trên DB test với `EXPLAIN FORMAT=JSON` và timeout 2s; template ở `tests/performance/explain-templates.sql` chỉ là gợi ý query shape, không phải query trace. Không dùng EXPLAIN ANALYZE mặc định.

Ghi chosen key/key parts, access type, estimated rows/filter, join order, filesort/temporary; trên data test có quyền mới thu actual rows examined/query time. Table scan trên bảng nhỏ có thể tối ưu, không đánh lỗi chỉ vì `ALL`. Thử 1/10/100/1000 parent rows: N+1 round-trip thể hiện query count tuyến tính, còn correlated EXISTS có thể query count cố định nhưng thời gian tăng. Billing service-loop phải theo số services và lock duration. Candidate index chỉ tiến hành ở giai đoạn tối ưu riêng, chưa sửa schema.

## 7. Browser và luồng ghi cần lượt đo riêng

Lighthouse: production build được serve local/staging, fixed mobile viewport/CPU/network throttling, 3 cold và 3 warm runs cho login/dashboard/rooms/tenants/invoice. Dùng synthetic auth profile lưu private; không gắn token vào URL. Thu LCP/FCP/CLS/TBT, JS parse/eval, main-thread long tasks, transferred bytes, waterfall. INP dùng browser interaction/RUM với typing/filter/open modal/route navigation, không suy từ Lighthouse navigation score.

React Profiler trên 100/1000 rows: commit count/duration khi gõ search, mở modal, poll, cập nhật 1 record. HAR xác minh `/me` gate, room→children, meter→tiers, request dedupe và numeric/string key. Browser isolation: A tải tenants/rooms→logout→B trong cùng tab, delay/block network và back button; không được render A dù API mới có scope đúng.

Luồng ghi chỉ trên disposable DB, fake mail/PayOS và rollback/reset dataset ngoài timed segment: bulk rooms 10/100/200, reorder 100/1000, contract draft→active/beds, reading validation, generate invoice, cash payment concurrency. Với payment tests không đi qua SDK/webhook thật; xác minh tổng tiền đúng và không duplicate. Không có script ghi tự động trong giai đoạn audit này.

API k6 dùng theo tài liệu chính thức [execution/test.abort](https://grafana.com/docs/k6/latest/javascript-api/k6-execution/), [thresholds/abortOnFail](https://grafana.com/docs/k6/latest/using-k6/thresholds/), [options](https://grafana.com/docs/k6/latest/using-k6/k6-options/reference/). Offline harness không thay thế `k6 inspect` và smoke trên runtime thật; k6 chưa có nên hai bước đó chưa được chạy.
