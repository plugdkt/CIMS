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
                <p style="font-size:14px; color:#374151; margin:0 0 16px;">
                    {{ __('requisitions.mail_otp_body', ['doc_no' => $requisition->doc_no]) }}
                </p>
                <p style="text-align:center; margin:24px 0;">
                    <span style="font-size:28px; font-weight:700; letter-spacing:6px; color:#6d28d9;">{{ $code }}</span>
                </p>
                <p style="font-size:12px; color:#9ca3af; margin:0;">
                    {{ __('requisitions.mail_otp_expiry') }}
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
