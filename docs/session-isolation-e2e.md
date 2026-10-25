# Browser verification — cache/session isolation

Ngày kiểm tra: 25/10/2026, Asia/Saigon. Kết quả: **4/4 browser tests pass**, **160/160 frontend regression tests pass**, **54/54 Laravel feature tests pass (341 assertions)**, **frontend build pass**. Không sửa tính năng ứng dụng trong lượt này.

## Môi trường thực thi

- Playwright **1.62.1** có sẵn trong runtime Codex; Chromium headless **140.0.7339.16** có sẵn trong cache browser. Không cài dependency, tải browser hoặc sửa package/lockfile.
- Chạy production build thực của React/Vite, không dùng component harness/SSR cho các ca browser bên dưới.
- Build E2E có `VITE_API_BASE_URL=/api`, xuất riêng vào `tmp/e2e-session-dist/`. Node HTTP server tự chọn port và chỉ bind `127.0.0.1`.
- Server test phục vụ static build và API double cùng origin. Chỉ có tài khoản A/B synthetic `example.invalid`, phòng/hóa đơn/người thuê giả trong RAM; không đọc `.env`, không import Laravel, không kết nối database.
- Browser context/profile mới cho mỗi ca; không dùng profile người dùng. Request ứng dụng ra ngoài origin test bị chặn và làm test fail. Service workers bị chặn. Không có external request bị phát hiện trong 4 ca pass.
- Tắt server, đóng context/browser sau test. Không deploy, không gọi production/PayOS/email/webhook, không load test.

## Kết quả trên browser thật

| Ca | Hành vi được thực thi/assert | Kết quả |
| --- | --- | --- |
| A → logout → B cùng tab | Nhập email/password qua form thật; A tải phòng/hóa đơn/người thuê; logout qua menu thật; B login; B chờ tenant response không được thấy A | PASS |
| Chuyển route và refresh | B chuyển tenants/invoices/rooms; mở lại URL nhà/phòng của A không thấy cache A; refresh route phòng B gọi lại `/me` và hiển thị B; logout B rồi refresh vẫn ở login | PASS, nằm trong ca thứ nhất |
| Late 200, abort thật | Request tìm tenant của A đang chạy tại server; logout A/login B; server hoàn tất 200 sau B. XHR cũ bị client hủy; token/UI B giữ đúng | PASS |
| Late 200 vẫn đến browser | Fault injection chỉ trong test: XHR cũ bỏ qua physical abort. Server trả response A sau B login, browser `load` ghi nhận status 200; không render dữ liệu A vào DOM B | PASS |
| Late 401 vẫn đến browser | Cùng cơ chế trên nhưng trả 401. Browser nhận status 401 sau B login; không xóa token B, không redirect B về login; B tiếp tục đọc invoices/rooms | PASS |

Tổng cộng 4 Node test cases; ca đầu gộp switch/route/loading/refresh. Một lần chạy hoàn chỉnh khoảng 12.1 giây, đây là thời gian chạy test, không phải benchmark API.

Các bước chuyển tài khoản và route trước refresh giữ nguyên `window` document marker, chứng minh không dùng reload để vô tình xóa cache và né lỗi. Mỗi context chỉ có **một tab**. Login/logout và điều hướng tenant/invoice dùng UI thật; URL phòng chính xác/URL foreign được chuyển bằng History API + popstate để giữ nguyên SPA document.

MutationObserver giám sát cả nội dung DOM và node được thêm sau khi bắt đầu chuyển sang B; nếu thấy marker dữ liệu A thì lưu cờ failure xuyên refresh. Assertion cũng kiểm tra DOM hiện tại không chứa A. Theme localStorage và thiết lập sessionStorage synthetic vẫn giữ nguyên sau switch/refresh.

## Regression và build chạy lại

- Frontend: toàn bộ `src/**/*.test.mjs`, **160 pass, 0 fail**.
- Laravel: SessionIsolationApiTest, AuthApiTest, TenantApiTest, RoomApiTest, InvoiceApiTest, DashboardApiTest: **54 pass, 0 fail, 341 assertions**. Env ép testing, SQLite `:memory:`, DB_URL rỗng, mail/cache/session array; không thay MySQL hay dữ liệu thật.
- Build E2E và build frontend bình thường đều thành công. Build bình thường xuất riêng `tmp/session-isolation-verified-dist/`; cảnh báo chunk >500 kB vẫn có từ trước, ngoài phạm vi kiểm tra này.

## File mới/thay đổi trong lượt kiểm tra

- `tests/e2e/session-isolation.browser.mjs`: browser tests, network guard, DOM leak monitor, response race và refresh.
- `tests/e2e/synthetic-session-server.mjs`: local-only static server và synthetic API double, response barriers có giới hạn thời gian.
- `tests/e2e/README.md`: hướng dẫn chạy lại bằng Playwright có sẵn hoặc path được cung cấp.
- `docs/session-isolation-e2e.md`: báo cáo này.
- `docs/session-isolation-fix.md`: cập nhật trạng thái kiểm chứng browser, giữ nội dung lịch sử lượt sửa.

## Giới hạn còn lại

Đây là browser E2E của **frontend thật + API double**, không phải chuỗi browser → Laravel → MySQL end-to-end. Denial ở URL A trong browser dùng ownership fixture. Ownership/auth Laravel thật được kiểm tra riêng bằng feature tests qua kernel và database SQLite trong RAM. Chưa kiểm chứng reverse proxy Nginx, HTTP caching production, MySQL, hoặc Google OAuth thật qua browser.

Các ca browser chỉ chạy Chromium nêu trên, chưa chạy Firefox/WebKit/mobile. Fault injection giữ XHR sống chỉ tồn tại trong browser context test, không thay đổi Axios/application code. Client abort không hoàn tác mutation đã xử lý trên server.

Không tìm thấy lỗi mới yêu cầu sửa application code trong các ca đã chạy.
