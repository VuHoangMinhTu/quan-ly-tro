{{ $appName }}

Xác minh địa chỉ email của bạn

Xin chào,

Cảm ơn bạn đã đăng ký tài khoản tại {{ $appName }}.

Vui lòng xác minh địa chỉ email của bạn bằng cách mở liên kết dưới đây:

{!! $verificationUrl !!}

Liên kết xác minh sẽ hết hạn sau {{ $expiresInMinutes }} phút kể từ khi email này được gửi.

Nếu bạn không thực hiện đăng ký tài khoản này, bạn có thể bỏ qua email.

Nếu nút trong email không hoạt động, vui lòng sao chép và dán đường dẫn trên vào trình duyệt.

Nếu quý khách không tìm thấy email xác minh, vui lòng kiểm tra thư mục Spam / Thư rác trong hộp thư.

Trân trọng,
{{ $appName }}

Email này được gửi tự động, vui lòng không trả lời.
© {{ now()->year }} {{ $appName }}
