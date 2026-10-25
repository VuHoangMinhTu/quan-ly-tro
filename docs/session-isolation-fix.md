# Cache/session isolation — kết quả xử lý

Ngày 25/10/2026. Chỉ xử lý chuyển tài khoản và request/cache thuộc phiên cũ. Không thay đổi production, schema, dependency hoặc tối ưu hiệu năng khác.

## Cơ chế thực tế và nguyên nhân

- `localStorage.access_token` chứa Bearer token. Không có dữ liệu nghiệp vụ persist vào localStorage/sessionStorage, SWR, Redux hoặc custom Context trong source được kiểm tra.
- Dữ liệu API nằm trong TanStack QueryClient ở RAM; trước sửa chỉ có một instance sống suốt tab. Query keys của nhiều list không chứa identity.
- State/form/modal/tài liệu nằm trong React useState/useRef và React Hook Form. Các Map trong utils chỉ là cấu trúc xử lý dữ liệu, không phải persistent cache.
- Axios client dùng chung gắn token từ localStorage. Public signing có Axios client riêng, không gắn token chủ trọ; giữ nguyên luồng public này.
- Logout trước sửa chỉ xóa một số prefix. Regression trên chính luồng MainLayout → LoginPage đã thất bại đúng với `Data A survived account switch: rooms`.
- Response 401 trước sửa xóa token vô điều kiện. Request cũ của A về sau login B có thể ảnh hưởng token B. Chỉ cancel query không đủ cho manual Axios requests hoặc mutation callbacks còn giữ tham chiếu.

## Bản sửa

Mỗi lần login/logout/token expiry tạo một session object và QueryClient mới. Session cũ bị abort, cancel query và clear query/mutation cache. Callback giữ client cũ không thể ghi vào client B. Vùng ProtectedRoute có key theo session để remount toàn bộ private page/form/state, kể cả khi URL không đổi; chờ `/me` của phiên mới trước khi hiển thị Outlet.

Axios chụp session ngay lúc khởi tạo request bằng synchronous interceptor, kết hợp signal phiên với signal của caller. Response success/error đều kiểm tra session hiện tại; nếu đã đổi phiên thì reject bằng CanceledError, kể cả transport không tôn trọng abort. 401 chỉ kết thúc đúng phiên gửi request, không xóa token của B. Credential login thất bại không được coi là token API hết hạn. Thông báo hết phiên chỉ giữ chuỗi lỗi đã chuẩn hóa, không giữ error object chứa cấu hình/token.

Login mật khẩu và Google callback dùng chung bước chuyển session + xác minh `/me`; lỗi xác minh cũ không rollback phiên mới hơn. Logout callback cũ không được redirect/clear phiên B. Storage event cho token cũng đổi client; thay đổi theme/settings khác bị bỏ qua. Chỉ ghi/xóa key `access_token`, không gọi localStorage.clear/sessionStorage.clear.

## File thay đổi trong lượt này

| File | Vai trò |
| --- | --- |
| `front-end/src/api/session.js` (mới) | Identity/session snapshot, client riêng, abort/reset và guard phiên |
| `front-end/src/api/sessionTransport.js` (mới) | Bind request/response vào phiên, cancellation và 401 guard |
| `front-end/src/api/authSession.js` (mới) | Xác minh login dùng chung, rollback có điều kiện |
| `front-end/src/api/SessionQueryProvider.jsx` (mới) | Provider theo phiên, đồng bộ token storage event |
| `front-end/src/api/axiosClient.js` | Cài session interceptors |
| `front-end/src/main.jsx` | Thay singleton provider bằng SessionQueryProvider |
| `front-end/src/routes/ProtectedRoute.jsx` | Remount private subtree; giữ thông báo hết phiên |
| `front-end/src/pages/auth/LoginPage.jsx` | Chuyển phiên và guard điều hướng |
| `front-end/src/pages/auth/GoogleCallbackPage.jsx` | Cùng ranh giới phiên, giữ single exchange khi effect replay |
| `front-end/src/layouts/MainLayout.jsx` | Kết thúc toàn bộ phiên thay cho xóa từng cache prefix |
| `front-end/src/api/sessionIsolation.test.mjs` (mới) | Regression đổi tài khoản, response race, route gate, provider và Google |
| `front-end/src/layouts/MainLayoutFlow.test.mjs` | Cập nhật harness theo endSession và giữ kiểm tra menu/logout |
| `front-end/src/components/forms/ApiFieldErrorsFlow.test.mjs` | Cập nhật harness route; giữ hồi quy thông báo 401 |
| `backend-api/tests/Feature/SessionIsolationApiTest.php` (mới) | Login/logout/token revocation và ownership room/invoice/tenant |
| `docs/session-isolation-fix.md` (mới) | Báo cáo này |

Tài liệu/script audit của lượt trước và các file build tạm được giữ nguyên. Không sửa controller/model/policy Laravel vì kiểm tra quyền hiện có pass.

## Kết quả kiểm tra

- **Frontend: 160/160 tests pass**, 0 fail, chạy toàn bộ `src/**/*.test.mjs` bằng Node test runner.
- **Backend: 54/54 tests pass, 341 assertions**, 0 fail, gồm SessionIsolationApiTest, AuthApiTest, TenantApiTest, RoomApiTest, InvoiceApiTest, DashboardApiTest.
- **Build frontend pass**: `node node_modules/vite/bin/vite.js build --outDir ../tmp/session-isolation-dist`. Không thay dist đang có. Cảnh báo chunk lớn vẫn tồn tại từ audit trước, ngoài phạm vi lượt này.
- **Oxlint các file frontend thay đổi pass**; Pint `--dirty --format agent` đã chạy cho test PHP; `git diff --check` pass.

Regression bao phủ:

1. A tải cache phòng/hóa đơn/người thuê/thanh toán, logout rồi login B trong cùng môi trường tab: không tái sử dụng cache A, cache mới có `/me` B, theme vẫn giữ.
2. Query và manual Axios A đang chạy bị abort/reject khi đổi B; không chạy callback cập nhật state và không ghi vào cache B. Adapter giả bỏ qua abort cũng bị response guard chặn.
3. 401/logout thuộc A về muộn không xóa token B. 401 hiện tại xóa phiên hiện tại và giữ thông báo an toàn.
4. B đang chờ dữ liệu không render private content A. Key của private route thay đổi để reset form/state; QueryObserver mới không nhận dữ liệu A.
5. `/me` xác minh A thất bại muộn không rollback B. Google callback dùng cùng ranh giới và không exchange code hai lần khi effect replay.
6. Provider đổi QueryClient khi token storage event, bỏ qua event thiết lập khác.
7. Laravel: A login/read/logout thật qua kernel test, B login/read chỉ own lists; B GET/DELETE tài nguyên A nhận 404, tài nguyên A vẫn tồn tại và chưa soft delete; token A cũ nhận 401; B vẫn authenticated. Guards được reset giữa request test để không tái dùng user của request trước.

Backend chạy với APP_ENV testing, SQLite `:memory:`, DB_URL rỗng, mail/cache/session array; dữ liệu giả bị hủy cùng database RAM. Không gọi API production, PayOS, webhook hay email thật. Feature test không chứng minh query behavior MySQL nhưng thực thi chính ownership/auth code Laravel.

## Giới hạn còn lại

- Ở lượt sửa ban đầu, frontend dùng QueryClient/Axios thật với adapter synthetic, component harness/SSR và QueryObserver. **Lượt kiểm tra tiếp theo đã chạy 4/4 browser tests pass trên Chromium thật**, với production build + synthetic local API; xem [session-isolation-e2e.md](session-isolation-e2e.md). Chưa kiểm chứng browser → Laravel → MySQL full-stack hoặc Google OAuth thật.
- Abort phía client không hoàn tác một mutation đã được server xử lý. Bản sửa ngăn response/callback cũ vào phiên B; không thay đổi tính atomic hoặc việc revoke token khi mạng logout thất bại.
- Quyền owner/manager Laravel vẫn theo implementation hiện tại. Không mở rộng quyền hay thay đổi policy. Không chứng minh toàn bộ endpoint trong hệ thống bằng test mới, nhưng đã chạy các suite liên quan và dashboard manager tests.
- Browser/CDN/server cache và bfcache của trình duyệt không được audit lại trong lượt này. Ranh giới sửa ở React state, TanStack Query và authenticated Axios transport hiện có.
