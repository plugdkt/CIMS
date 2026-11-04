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
                <p style="font-size:15px; color:#111827; margin:0 0 12px;">
                    {{ __('loans.mail.advisor_greeting', ['name' => $advisorName]) }}
                </p>
                <p style="font-size:14px; color:#374151; margin:0 0 12px;">
                    {{ __('loans.mail.advisor_body', ['doc_no' => $loan->doc_no, 'due' => $loan->due_date->format('d/m/Y')]) }}
                </p>
                <ul style="font-size:14px; color:#374151; margin:0 0 16px; padding-left:20px;">
                    @foreach ($loan->lines as $line)
                        <li>{{ $line->asset?->item?->name_th }} × {{ rtrim(rtrim((string) $line->qty, '0'), '.') }}</li>
                    @endforeach
                </ul>
                <p style="text-align:center; margin:24px 0;">
                    <a href="{{ $signedUrl }}" style="background:#6d28d9; color:#ffffff; text-decoration:none; font-weight:600; font-size:14px; padding:12px 24px; border-radius:8px; display:inline-block;">
                        {{ __('loans.mail.advisor_cta') }}
                    </a>
                </p>
                <p style="font-size:12px; color:#9ca3af; margin:0;">
                    {{ __('loans.mail.advisor_expiry', ['hours' => (int) config('loans.advisor_link_hours', 72)]) }}
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
