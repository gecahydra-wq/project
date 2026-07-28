<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $subject ?? 'AbaiMarket' }}</title>
    <style>
        @media only screen and (max-width: 600px) {
            .fm-container { width: 100% !important; }
            .fm-content { padding: 28px 20px !important; }
            .fm-header { padding: 24px 20px !important; }
            .fm-footer { padding: 20px !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;background-color:#f8fafc;font-family:Arial,Helvetica,sans-serif;">
    <span style="display:none;font-size:1px;color:#f8fafc;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">{{ $preheader ?? '' }}</span>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc;">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" class="fm-container" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:100%;background-color:#ffffff;border-radius:16px;border:1px solid #e2e8f0;">
                    <tr>
                        <td class="fm-header" style="background-color:#0b5a8a;background-image:linear-gradient(135deg,#073b5c 0%,#0b5a8a 55%,#0f9b8e 100%);border-radius:16px 16px 0 0;padding:28px 32px;text-align:center;">
                            <span style="display:inline-block;width:40px;height:40px;line-height:40px;border-radius:10px;background-color:rgba(255,255,255,0.18);color:#ffffff;font-size:20px;vertical-align:middle;">&#128031;</span>
                            <span style="display:inline-block;vertical-align:middle;margin-left:10px;color:#ffffff;font-size:20px;font-weight:700;font-family:Arial,Helvetica,sans-serif;">AbaiMarket</span>
                        </td>
                    </tr>
                    <tr>
                        <td class="fm-content" style="padding:36px 32px;">
                            @if(!empty($eyebrow))
                                <p style="margin:0 0 8px;font-size:12px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;color:#0b5a8a;">{{ $eyebrow }}</p>
                            @endif
                            <h1 style="margin:0 0 16px;font-size:22px;line-height:1.3;color:#0f172a;">{{ $headline }}</h1>
                            <div style="font-size:15px;line-height:1.65;color:#334155;">
                                @yield('content')
                            </div>
                            @isset($ctaUrl)
                                <table role="presentation" cellpadding="0" cellspacing="0" style="margin:28px 0 4px;">
                                    <tr>
                                        <td style="border-radius:8px;background-color:#0b5a8a;">
                                            <a href="{{ $ctaUrl }}" style="display:inline-block;padding:13px 26px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;font-family:Arial,Helvetica,sans-serif;">{{ $ctaLabel }}</a>
                                        </td>
                                    </tr>
                                </table>
                            @endisset
                        </td>
                    </tr>
                    <tr>
                        <td class="fm-footer" style="padding:24px 32px;border-top:1px solid #e2e8f0;background-color:#f8fafc;border-radius:0 0 16px 16px;text-align:center;">
                            <p style="margin:0 0 6px;font-size:12px;color:#64748b;">AbaiMarket &ndash; LGU, Sellers, and Fish Farmers working together for local aquaculture.</p>
                            <p style="margin:0;font-size:12px;color:#94a3b8;">This is an automated message &ndash; please do not reply directly to this email.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
