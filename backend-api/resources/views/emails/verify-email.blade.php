<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="format-detection" content="telephone=no">
    <title>Xác minh địa chỉ email của bạn</title>
    <style>
        @media only screen and (max-width: 600px) {
            .email-wrapper { padding: 16px 12px !important; }
            .email-content { padding: 28px 20px !important; }
            .email-header { padding: 24px 20px !important; }
            .email-heading { font-size: 24px !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #f3f5f4; color: #334155; font-family: Arial, Helvetica, sans-serif; -webkit-text-size-adjust: 100%;">
    <div style="display: none; max-height: 0; overflow: hidden; mso-hide: all;">
        Xác minh email để bắt đầu sử dụng {{ $appName }}. Liên kết có hiệu lực trong {{ $expiresInMinutes }} phút.
    </div>
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width: 100%; background-color: #f3f5f4;">
        <tr>
            <td class="email-wrapper" align="center" style="padding: 36px 16px;">
                <!--[if mso]><table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0"><tr><td><![endif]-->
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width: 100%; max-width: 600px; table-layout: fixed; background-color: #ffffff; border: 1px solid #e2e8e5; border-radius: 16px; box-shadow: 0 8px 28px rgba(18, 55, 45, 0.06);">
                    <tr>
                        <td class="email-header" style="padding: 28px 36px; background-color: #12372d; border-radius: 16px 16px 0 0; border-bottom: 3px solid #d2b36a;">
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td aria-hidden="true" align="center" width="40" style="width: 40px; height: 40px; border: 1px solid #d2b36a; border-radius: 8px; font-size: 16px; font-weight: bold; color: #e9d6a5;">QT</td>
                                    <td style="padding-left: 12px; color: #ffffff; font-size: 18px; line-height: 26px; font-weight: bold; overflow-wrap: anywhere;">{{ $appName }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td class="email-content" style="padding: 36px;">
                            <h1 class="email-heading" style="margin: 0 0 24px; color: #12372d; font-size: 28px; line-height: 1.3; font-weight: bold;">Xác minh địa chỉ email</h1>
                            <p style="margin: 0 0 16px; font-size: 16px; line-height: 26px;">Xin chào,</p>
                            <p style="margin: 0 0 16px; font-size: 16px; line-height: 26px;">Cảm ơn bạn đã đăng ký tài khoản tại <strong>{{ $appName }}</strong>.</p>
                            <p style="margin: 0; font-size: 16px; line-height: 26px;">Vui lòng xác minh địa chỉ email của bạn bằng cách nhấn vào nút bên dưới.</p>
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin: 28px 0;">
                                <tr>
                                    <td align="center" bgcolor="#12372d" style="border-radius: 8px; mso-padding-alt: 16px 28px;">
                                        <a href="{{ $verificationUrl }}" target="_blank" style="display: inline-block; padding: 16px 28px; background-color: #12372d; border-radius: 8px; color: #ffffff; font-size: 16px; line-height: 22px; font-weight: bold; text-decoration: none;">Xác minh email</a>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin: 0 0 16px; color: #64748b; font-size: 14px; line-height: 23px;">Liên kết xác minh sẽ hết hạn sau <strong>{{ $expiresInMinutes }} phút</strong> kể từ khi email này được gửi.</p>
                            <p style="margin: 0 0 24px; font-size: 14px; line-height: 23px;">Nếu bạn không thực hiện đăng ký tài khoản này, bạn có thể bỏ qua email.</p>
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width: 100%; table-layout: fixed; border-top: 1px solid #e2e8f0;">
                                <tr>
                                    <td style="padding-top: 24px;">
                                        <p style="margin: 0 0 10px; color: #64748b; font-size: 13px; line-height: 21px;">Nếu nút trên không hoạt động, vui lòng sao chép và dán đường dẫn sau vào trình duyệt:</p>
                                        <p style="margin: 0; font-size: 12px; line-height: 20px; word-break: break-all; overflow-wrap: anywhere;">
                                            <a href="{{ $verificationUrl }}" style="color: #245d4b; text-decoration: underline; word-break: break-all; overflow-wrap: anywhere;">{{ $verificationUrl }}</a>
                                        </p>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin: 24px 0; padding: 14px 16px; background-color: #f5f7f6; border-radius: 8px; color: #64748b; font-size: 13px; line-height: 21px;">Nếu quý khách không tìm thấy email xác minh, vui lòng kiểm tra thư mục Spam / Thư rác trong hộp thư.</p>
                            <p style="margin: 0; font-size: 14px; line-height: 23px;">Trân trọng,<br><strong>{{ $appName }}</strong></p>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding: 20px 24px; background-color: #f8faf9; border-top: 1px solid #e2e8e5; border-radius: 0 0 16px 16px; color: #64748b; font-size: 12px; line-height: 20px;">
                            Email này được gửi tự động, vui lòng không trả lời.<br>
                            &copy; {{ now()->year }} {{ $appName }}
                        </td>
                    </tr>
                </table>
                <!--[if mso]></td></tr></table><![endif]-->
            </td>
        </tr>
    </table>
</body>
</html>
