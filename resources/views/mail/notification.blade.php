<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: 'Sarabun', 'Segoe UI', sans-serif; background:#f5f3f9; margin:0; padding:24px;">
    <table role="presentation" width="100%" style="max-width:480px; margin:0 auto; background:#ffffff; border-radius:12px; overflow:hidden; border:1px solid #e5e0ef;">
        <tr>
            <td style="background:#6d28d9; padding:20px 24px;">
                <span style="color:#ffffff; font-size:18px; font-weight:700;">CMIS</span>
            </td>
        </tr>
        <tr>
            <td style="padding:24px;">
                <p style="font-size:15px; color:#111827; margin:0 0 12px; font-weight:600;">
                    {{ $title }}
                </p>
                @if ($body)
                    <p style="font-size:14px; color:#374151; margin:0 0 16px; white-space:pre-line;">
                        {{ $body }}
                    </p>
                @endif
                @if ($linkUrl)
                    <p style="text-align:center; margin:24px 0;">
                        <a href="{{ $linkUrl }}" style="background:#6d28d9; color:#ffffff; text-decoration:none; font-weight:600; font-size:14px; padding:12px 24px; border-radius:8px; display:inline-block;">
                            {{ __('notifications.mail_cta') }}
                        </a>
                    </p>
                @endif
            </td>
        </tr>
    </table>
</body>
</html>
