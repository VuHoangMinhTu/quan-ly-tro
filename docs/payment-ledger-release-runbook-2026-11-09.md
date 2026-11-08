# Payment ledger release — review gate, chưa triển khai

## Quyết định hiện tại

**NO-GO cho triển khai production; có thể review PR.** Regression code PASS: 109 MySQL tests / 646 assertions, gồm 8 concurrency tests; 266 frontend tests trên workspace QA, build/lint/Pint PASS. Cần CI của đúng commit PR xanh và hoàn tất các gate dưới đây. Không có quyền merge/deploy trong nhiệm vụ này.

Gate chưa đạt:

1. Production có **1 payment MANUAL/CASH, 5.132.000đ không có invoice cha**. Đã xác nhận bằng SELECT; không sửa/xóa/reparent hay suy diễn ledger. Đây là dữ liệu tồn tại trước migration, không phải lỗi do ledger. Cần người phụ trách đối soát và phê duyệt cách xử lý riêng; không dùng migration để che dữ liệu lỗi.
2. Dump được duyệt ngày 05/11 restore được, nhưng chỉ có 47 bảng và thiếu các migration Billing V2 mà production đã chạy. Cần backup mới, restore rehearsal và so sánh lại với snapshot production trước cửa sổ release. Không coi dump cũ là bản rollback đủ mới.
3. Tài khoản SSH hiện tại không có sudo không tương tác: chưa kiểm tra được cron root, timer/worker cấu hình riêng cần quyền quản trị. Không suy ra không có writer chỉ từ việc không thấy process.
4. Production còn **1 PayOS request PENDING**, ngoài 5 CANCELLED và 2 STALE. Cần kiểm chứng phương án giữ/re-deliver/reconcile webhook trong maintenance; không mặc định PayOS tự retry đủ lâu.

## Phạm vi commit

- PaymentController, StorePaymentRequest, Payment model, InvoicePaymentService và PayOSService.
- Service ManualPaymentIdempotency và hai migration additive theo thứ tự dưới đây.
- PaymentForm: UUID ổn định cho lần gửi/retry, lỗi inline, ngày giờ Việt Nam; không gom layout/toast/draft preview đang làm dở.
- paymentDateTime và test; regression API/ledger/migration/concurrency, worker fixture, lifecycle QA liên quan; workflow CI riêng cho 109 MySQL tests.
- Báo cáo và runbook này. Không đưa .env, dump, log, token, dữ liệu production hoặc file tmp vào commit.

## Client và tính tương thích

Tìm toàn bộ `front-end/src` và backend writer: manual POST chỉ từ `InvoiceDetailPage` gọi `createPayment` trong `api/paymentApi.js`, nhận payload từ `PaymentForm`. Tạo mới gửi UUID bằng `crypto.randomUUID()`, giữ UUID khi lỗi/409/422; sau thành công UUID mới dành cho thao tác mới. PUT sửa payment không gửi key vì không tạo receipt. Wrapper `createPayment(invoiceId,payload)` chỉ chuyển payload, không tự tạo key.

Backend chỉ có hai writer application: PaymentController (manual) và PayOSService (verified webhook). PayOS không dùng namespace ledger manual, giữ unique provider reference, merchant/signature/config/amount validation.

**Client không có key vẫn tồn tại:** các tab/browser asset cũ đang mở trước release; caller API bên ngoài/cURL/integration không được source repo quản lý; test/legacy POST cố ý không có key. `StorePaymentRequest` vẫn nullable để giữ contract. Không thể chứng minh toàn bộ client production có key bằng tìm kiếm source. Các request thiếu key không có dedupe guarantee; không dùng amount/reference label để đoán key. Kế hoạch tương thích: phát hành frontend và yêu cầu reload, thông báo rõ đối soát trước khi gửi lại sau mất phản hồi; theo dõi tỷ lệ key hiện diện bằng metadata an toàn trong bước vận hành được duyệt. Chỉ chuyển sang bắt buộc key qua kế hoạch version/deprecation riêng sau xác nhận integration, không làm trong PR này. UUID trong form không tồn tại qua reload/đóng modal; cần kiểm tra danh sách receipt trước khi bắt đầu thao tác mới sau response không rõ trạng thái.

## Bằng chứng production preflight — chỉ đọc

Đã SSH vào `/var/www/quan-ly-tro`: `main` sạch tại `c01c077c70a643fc4569a473eebfba26040ea5e0`. Nginx và PHP8.3-FPM active. MySQL 8.0.46, port 3306, database `quan_ly_tro`. Toàn bộ migration có trong source production hiện tại đã Ran; hai migration mới chưa nằm trong source VPS và chưa chạy.

Schema đã đọc bằng SHOW CREATE TABLE: payments/invoices/payos_payment_requests đều InnoDB; payments có unique `(payment_method, external_reference)`, FK invoice ON DELETE CASCADE; chưa có receipt key/fingerprint hoặc ledger. Invoice có FK landlord/room/contract; PayOS request có unique order_code và payment_link_id, FK invoice CASCADE/config RESTRICT. Không có schema mutation trên VPS.

Payments: 1 MANUAL/CASH, tổng 5.132.000đ, 0 PAYOS receipt; 1 orphan invoice và thiếu owner do không có parent. Invoices: 6 DRAFT tổng 20.765.000đ, 2 UNPAID tổng 0đ; paid_amount tổng 0đ; 0 mismatch SUM(receipts) trên các invoice còn tồn tại. Không diễn giải con số 0 mismatch này thành không có orphan.

jobs = 0. Không thấy queue/horizon/supervisor service/process đang hoạt động trong danh sách có quyền đọc; cron service active, crontab user yato không có schedule:run, không thấy entry schedule:run trong file system cron có quyền đọc. Root cron và writer ngoài ứng dụng/chạy máy khác vẫn chưa được loại trừ. Không gọi PayOS thật hoặc tạo invoice/payment production để thử.

## Restore rehearsal

Backup SHA-256: `508991b91695cbc06d1dfb9193531688205a4f825c74c23d621d5cc2c9400c7e`, 676.288 bytes. File giữ ngoài Git; không đưa nội dung hoặc thông tin cá nhân vào báo cáo.

Đã restore vào **hai database mới**, không ghi đè database có sẵn, tại MySQL test riêng `127.0.0.1:13368`: `beehouse_ledger_restore_1108` và `beehouse_ledger_fault_1108`. Không kết nối port 3306 để migration. Test account chỉ có quyền trên các database clone. Môi trường Laravel testing, cache/session array, queue sync, không gọi outbound PayOS.

| Kiểm tra | Kết quả |
|---|---|
| Restore đầy đủ 47 bảng | PASS, lần lượt 1,935s và 2,331s |
| Hai migration đúng thứ tự | PASS, 0,514s trên clone thường |
| Ngắt sau CREATE TABLE ledger đã commit, migration record chưa ghi, chạy tiếp | PASS, 0,508s gồm lỗi giả lập và resume |
| So sánh từng row/cột cũ payments, invoices, payos_payment_requests bằng SHA-256 trước/sau | PASS, không thay đổi |
| Đối chiếu count/amount/invoice status/paid_amount | PASS, 1 receipt / 5.132.000đ, các trạng thái/paid giữ nguyên |
| Backfill | 0 claim vì 1 payment cũ NULL key; không tự suy diễn key cho orphan |
| Chạy lại up của cả hai migration | PASS, snapshots/ledger không đổi |
| Hai unique index payments, ledger unique, ledger không FK | PASS |

Migration trên 2.000 payment giả lập với 500 key thực sự lưu đã PASS riêng trong 109-test suite, gồm fault injection tại CREATE/index/backfill, fingerprint không hợp lệ và chặn destructive down. Bản backup này không có keyed receipt để kiểm chứng backfill có dữ liệu; không thay thế fixture 500 key hoặc rehearsal với backup hiện thời. Timing trên dữ liệu nhỏ không phải benchmark tải/metadata lock production.

## Runbook sau khi được duyệt merge và deploy

### 1. Lên lịch và đóng gate

Chọn cửa sổ ít giao dịch ít nhất 30 phút, thông báo người dùng; giờ cụ thể do người phụ trách xác nhận. Xác nhận PR review/CI/merge SHA, clean VPS, quyền admin và người đối soát PayOS sẵn sàng. Lưu snapshot và bản backup mới ngoài repo, quyền 0600, bao gồm toàn bộ bảng, schema, ledger khi đã có; backup .env nguyên trạng, không đổi APP_KEY. Restore backup vào database riêng và so hash/count/sums/schema trước khi chấp nhận khả năng phục hồi.

Không tiếp tục nếu orphan/owner/fingerprint/unique conflicts chưa được đối soát và chấp thuận; không sửa payment để làm migration PASS. Không thực hiện bất kỳ lệnh trong phần sau chỉ dựa trên báo cáo này — nhiệm vụ hiện tại dừng trước merge.

### 2. Dừng mọi writer, bảo toàn PayOS

Inventory admin: supervisor/systemd/Horizon/queue workers, cron của mọi user, systemd timers, import/SQL scripts, ứng dụng tích hợp và DB sessions đang ghi. Dừng/disable theo đúng unit/program đã xác nhận, ghi lại trạng thái để khôi phục; chờ job đang chạy hoàn tất, không kill giữa transaction. `queue:restart` một mình không phải cách dừng writer vì process monitor có thể khởi chạy lại.

Maintenance chặn cả HTTP write và webhook; **không exclude webhook để nó ghi trong lúc backfill**. Trước khi chặn phải có phương án provider redelivery đã xác nhận hoặc durable webhook capture/replay đã được kiểm chứng, giữ đầy đủ signature/body trong kho bí mật, không log payload ra Git; không ACK 200 nếu chưa lưu/xử lý bền vững. Nếu chưa có cơ chế đó, NO-GO cho cửa sổ này. Không thêm proxy capture hoặc thay webhook config trong PR này.

Lập danh sách order/link PENDING và mốc thời gian cutover trong nơi riêng được bảo vệ; đối soát giao dịch đến trong khoảng bảo trì với merchant tương ứng. Replay chỉ qua đường verify signature/config hiện hữu, cùng external_reference, không dùng POST manual hoặc bypass signature để bù tiền. Cần người phụ trách xác nhận không mất giao dịch trước mở lại UI tạo QR. Không mặc định CANCELLED/STALE đồng nghĩa ngân hàng không chuyển tiền.

Sau khi cơ chế bảo toàn webhook sẵn sàng:

```bash
cd /var/www/quan-ly-tro/backend-api
php artisan down --retry=60 --no-interaction
```

Dừng các writer đã inventory, chờ transaction đang mở kết thúc, lấy backup/snapshot cuối trong write freeze. Billing V2 flag không chặn Payment API. Không chỉ dựa vào maintenance khi còn SQL/import/worker ngoài HTTP.

### 3. Cập nhật source và schema trong write freeze

```bash
cd /var/www/quan-ly-tro
test "$(git branch --show-current)" = main || exit 1
test -z "$(git status --porcelain)" || exit 1
git fetch origin
# Kiểm tra origin/main đúng SHA đã review/merge trước lệnh tiếp theo.
git merge --ff-only origin/main
cd backend-api
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
php artisan migrate:status --no-interaction
```

Chỉ tiếp tục nếu pending đúng hai migration đã review; migration khác cần đánh giá riêng. Code mới chưa nhận traffic/writer khi schema chưa sẵn sàng. Không sửa .env ngoài bước cấu hình đã được duyệt; giữ Billing V2/PayOS/APP_KEY hiện tại.

```bash
php artisan migrate --force --no-interaction --path=database/migrations/2026_11_08_063357_add_idempotency_to_manual_payments.php
php artisan migrate --force --no-interaction --path=database/migrations/2026_11_08_210959_create_manual_payment_requests_table.php
php artisan migrate:status --no-interaction
```

Nếu lỗi DDL, giữ write freeze, kiểm tra cột/index/FK/ledger record đã commit, lưu trạng thái migration; sửa nguyên nhân được review và chạy lại cùng migration. Không tự xóa bảng/index/key hoặc đánh dấu migration Ran bằng tay; không chạy migrate:fresh/wipe/seed. Backfill additive chỉ copy key/fingerprint còn tồn tại; thiếu evidence dừng để đối soát.

Build frontend bằng cấu hình API production hiện có; không copy .env local:

```bash
cd /var/www/quan-ly-tro/front-end
npm ci
npm run build
cd ../backend-api
php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction
php artisan config:show billing
```

Chỉ in config billing, không dump config database/services/env. Xác nhận v2_enabled=true. Admin kiểm tra Nginx config và reload PHP-FPM để OPcache nhận đúng source; không thay config dịch vụ ngoài kế hoạch được duyệt.

### 4. Đối chiếu read-only trước mở writer

Lưu output aggregate trong nơi bảo vệ; so với snapshot ngay trước migration. Không xuất dữ liệu người thuê/token/note/reference vào báo cáo công khai.

```sql
SELECT payment_source,payment_method,COUNT(*) n,SUM(amount) amount
FROM payments GROUP BY payment_source,payment_method;
SELECT status,COUNT(*) n,SUM(total_amount) total,SUM(paid_amount) paid
FROM invoices GROUP BY status;
SELECT COUNT(*) orphan_payments FROM payments p LEFT JOIN invoices i ON i.id=p.invoice_id WHERE i.id IS NULL;
SELECT COUNT(*) paid_mismatch FROM invoices i
LEFT JOIN (SELECT invoice_id,SUM(amount) paid FROM payments GROUP BY invoice_id) p ON p.invoice_id=i.id
WHERE i.paid_amount<>COALESCE(p.paid,0);
SELECT COUNT(*) missing_owner FROM payments p LEFT JOIN invoices i ON i.id=p.invoice_id
LEFT JOIN rooms r ON r.id=i.room_id LEFT JOIN boarding_houses h ON h.id=r.boarding_house_id
WHERE h.landlord_id IS NULL;
SELECT COUNT(*) owner_mismatch FROM payments p JOIN invoices i ON i.id=p.invoice_id
JOIN rooms r ON r.id=i.room_id JOIN boarding_houses h ON h.id=r.boarding_house_id
WHERE i.landlord_id<>h.landlord_id;
SELECT COUNT(*) invalid_key_evidence FROM payments
WHERE (idempotency_key IS NULL AND idempotency_fingerprint IS NOT NULL)
   OR (idempotency_key IS NOT NULL AND (idempotency_fingerprint IS NULL OR idempotency_fingerprint NOT REGEXP '^[a-f0-9]{64}$'));
SELECT COUNT(*) ledger_missing FROM payments p
JOIN invoices i ON i.id=p.invoice_id JOIN rooms r ON r.id=i.room_id
JOIN boarding_houses h ON h.id=r.boarding_house_id
LEFT JOIN manual_payment_requests l ON l.landlord_id=h.landlord_id AND l.invoice_id=p.invoice_id AND l.idempotency_key=p.idempotency_key
WHERE p.idempotency_key IS NOT NULL AND (l.id IS NULL OR l.payment_id<>p.id OR l.state<>'APPLIED' OR l.idempotency_fingerprint<>p.idempotency_fingerprint);
SELECT landlord_id,invoice_id,COUNT(*) duplicate_count FROM manual_payment_requests
GROUP BY landlord_id,invoice_id,idempotency_key HAVING COUNT(*)>1;
SHOW INDEX FROM payments;
SHOW CREATE TABLE manual_payment_requests;
```

So sánh hash đầy đủ cột cũ ở nơi riêng như rehearsal, không chỉ sums. NULL key cũ không có ledger là bình thường. Không diễn giải UUID của row đã mất trong quá khứ. Kiểm tra ledger không có FK và scope unique đúng; hai unique index payments phải còn.

### 5. Smoke và mở lại có kiểm soát

Read-only bằng tài khoản hợp lệ: `/api/billing-v2/status` enabled=true, invoice V1 lịch sử/V2 chi tiết, quyền owner/manager và cách ly dữ liệu. Không tạo/phát hành invoice/payment trên tenant thực để smoke. Các phép ghi/lost-response/delete/concurrency dùng database test riêng; không chạy PHPUnit trên production.

Đối soát/replay webhook được bảo toàn trong write freeze với đội vận hành, giám sát external_reference uniqueness, paid_amount/status/reconciliation và lỗi 500/deadlock. Không vừa mở manual writer vừa đổi schema/backfill. Khôi phục đúng các worker/scheduler đã dừng, kiểm tra health và log đã redact, rồi `php artisan up --no-interaction` theo thứ tự đã duyệt. Bắt người dùng reload frontend trước thao tác mới; theo dõi legacy keyless requests. Nếu webhook chưa đối soát xong, giữ đóng thao tác thanh toán.

### 6. Dừng/rollback

Nếu count/hash/số tiền/trạng thái thay đổi ngoài kế hoạch, lỗi schema/500 hay nguy cơ mất webhook: **giữ/dựng lại write freeze và NO-GO**. Không rollback migration tài chính hoặc restore backup đè giao dịch mới. Không drop ledger/cột/index đã lưu key, không tự rollback DDL; migration down có guard nhưng không thay thế quy trình.

Có thể checkout source đã duyệt để điều tra/read-only và rebuild/cache/reload trong maintenance, nhưng **không mở lại payment writer cũ không hiểu ledger**: nó có thể xóa key hoặc nhận retry không được bảo vệ. Rollback phục vụ ghi cần bản application tương thích ledger được review/test, hoặc giữ payments đóng cho đến forward fix. Tắt Billing V2 một mình không cô lập payment/webhook V1. Ledger/tombstone/audit luôn được giữ và đưa vào backup; phục hồi dữ liệu cần đánh giá riêng từng giao dịch đã đến sau snapshot.
