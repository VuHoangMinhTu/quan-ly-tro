<?php

return [
    'link_expiry_hours' => (int) env('SIGNING_LINK_EXPIRY_HOURS', 168),
    'otp_expiry_minutes' => (int) env('SIGNING_OTP_EXPIRY_MINUTES', 5),
    'otp_max_attempts' => (int) env('SIGNING_OTP_MAX_ATTEMPTS', 5),
    'otp_resend_seconds' => (int) env('SIGNING_OTP_RESEND_SECONDS', 60),
    'request_resend_seconds' => (int) env('SIGNING_REQUEST_RESEND_SECONDS', 60),
    'mailer' => env('SIGNING_MAILER', env('MAIL_MAILER', 'smtp')),
    'display_timezone' => env('SIGNING_DISPLAY_TIMEZONE', 'Asia/Ho_Chi_Minh'),
    'trusted_proxies' => array_values(array_filter(explode(',', (string) env('TRUSTED_PROXIES', '')))),
];
