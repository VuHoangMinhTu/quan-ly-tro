<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $kind === 'OTP' ? 'Mã xác thực ký hợp đồng' : 'Yêu cầu ký hợp đồng thuê phòng' }} - BeeHouse</title>
</head>
<body style="margin:0;padding:0;background:#f3f5f4;color:#334155;font-family:Arial,Helvetica,sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
        <tr><td align="center" style="padding:24px 12px;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:600px;table-layout:fixed;background:#fff;border:1px solid #e2e8f0;border-radius:12px;">
                <tr><td style="padding:22px 24px;background:#12372d;border-bottom:3px solid #d2b36a;border-radius:12px 12px 0 0;color:#fff;">
                    <strong style="font-size:22px;">BeeHouse</strong><br>
                    <span style="font-size:13px;line-height:24px;">Quản lý trọ · Hợp đồng điện tử</span>
                </td></tr>
                <tr><td style="padding:24px;font-size:15px;line-height:25px;overflow-wrap:anywhere;">
                    <h1 style="margin:0 0 20px;color:#12372d;font-size:22px;line-height:30px;">{{ $kind === 'OTP' ? 'Xác thực ký hợp đồng' : 'Yêu cầu ký hợp đồng thuê phòng' }}</h1>
                    <p style="margin:0 0 16px;">Xin chào {{ $details['name'] }},</p>
                    @if ($kind === 'REQUEST')
                        <p style="margin:0 0 16px;">Bên cho thuê đã gửi bạn yêu cầu xem và ký hợp đồng qua BeeHouse.</p>
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f8faf9;border:1px solid #e2e8f0;border-radius:8px;table-layout:fixed;">
                            <tr><td style="padding:14px 16px;word-break:break-word;">
                                <strong>Hợp đồng:</strong> {{ $details['code'] }}<br>
                                <strong>Nhà trọ:</strong> {{ $details['house'] }}<br>
                                <strong>Phòng:</strong> {{ $details['room'] }}
                            </td></tr>
                        </table>
                        <p style="margin:16px 0;">Vui lòng kiểm tra nội dung hợp đồng. Nếu đồng ý, bạn có thể xác thực bằng mã OTP gửi qua email và vẽ chữ ký trên trang ký.</p>
                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:20px 0;">
                            <tr><td bgcolor="#12372d" style="border-radius:8px;">
                                <a href="{{ $details['url'] }}" style="display:inline-block;padding:12px 20px;color:#fff;text-decoration:none;font-weight:bold;">Xem và ký hợp đồng</a>
                            </td></tr>
                        </table>
                        <p style="margin:0 0 16px;font-size:13px;">Liên kết có hiệu lực đến {{ $details['expires'] }}. Vui lòng không chuyển tiếp email hoặc chia sẻ liên kết ký.</p>
                        <p style="margin:0 0 8px;font-size:13px;">Nếu nút không mở được, sao chép liên kết sau vào trình duyệt:</p>
                        <p style="margin:0 0 16px;font-size:12px;line-height:20px;word-break:break-all;"><a href="{{ $details['url'] }}" style="color:#245d4b;">{{ $details['url'] }}</a></p>
                        <p style="margin:0 0 16px;font-size:13px;">Nếu thông tin không đúng hoặc bạn không liên quan đến hợp đồng này, vui lòng liên hệ bên cho thuê trước khi ký.</p>
                    @else
                        <p style="margin:0 0 16px;">Bạn vừa yêu cầu mã xác thực trên trang ký hợp đồng BeeHouse. Nhập mã dưới đây để tiếp tục:</p>
                        <p style="margin:20px 0;padding:16px;background:#f8faf9;border:1px solid #e2e8f0;border-radius:8px;text-align:center;font-family:monospace;font-size:30px;line-height:40px;font-weight:bold;letter-spacing:5px;color:#12372d;">{{ $details['otp'] }}</p>
                        <p style="margin:0 0 16px;">Mã có hiệu lực {{ $details['minutes'] }} phút. Không chia sẻ mã này với người khác.</p>
                        <p style="margin:0 0 16px;font-size:13px;">Nếu bạn không yêu cầu mã, vui lòng bỏ qua email này. Nhận mã OTP không đồng nghĩa hợp đồng đã được ký.</p>
                    @endif
                    <p style="margin:20px 0 0;">Trân trọng,<br><strong>BeeHouse - Quản lý trọ</strong></p>
                </td></tr>
                <tr><td style="padding:16px 24px;border-top:1px solid #e2e8f0;color:#64748b;font-size:12px;line-height:20px;">Đây là email giao dịch tự động phục vụ việc ký hợp đồng trên BeeHouse, không phải email quảng cáo.</td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>
