BeeHouse - Quản lý trọ

{{ $kind === 'OTP' ? 'Xác thực ký hợp đồng' : 'Yêu cầu ký hợp đồng thuê phòng' }}

Xin chào {{ $details['name'] }},

@if ($kind === 'REQUEST')
Bên cho thuê đã gửi bạn yêu cầu xem và ký hợp đồng qua BeeHouse.

Hợp đồng: {{ $details['code'] }}
Nhà trọ: {{ $details['house'] }}
Phòng: {{ $details['room'] }}

Vui lòng kiểm tra nội dung hợp đồng. Nếu đồng ý, xác thực bằng mã OTP gửi qua email và vẽ chữ ký trên trang ký.

Xem và ký hợp đồng:
{!! $details['url'] !!}

Liên kết có hiệu lực đến {{ $details['expires'] }}. Không chuyển tiếp email hoặc chia sẻ liên kết ký.

Nếu thông tin không đúng hoặc bạn không liên quan đến hợp đồng này, vui lòng liên hệ bên cho thuê trước khi ký.
@else
Bạn vừa yêu cầu mã xác thực trên trang ký hợp đồng BeeHouse.

Mã xác thực: {{ $details['otp'] }}

Mã có hiệu lực {{ $details['minutes'] }} phút. Không chia sẻ mã này với người khác.

Nếu bạn không yêu cầu mã, vui lòng bỏ qua email này. Nhận mã OTP không đồng nghĩa hợp đồng đã được ký.
@endif

Trân trọng,
BeeHouse - Quản lý trọ

Đây là email giao dịch tự động phục vụ việc ký hợp đồng trên BeeHouse, không phải email quảng cáo.
