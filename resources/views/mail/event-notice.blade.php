@php
    $logo = public_path('madina-header-logo.png');
    $company = (string) (config('mail.from.name') ?: config('app.name'));
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $noticeTitle }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f6f8;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8;padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border:1px solid #e3e8ee;border-radius:8px;font-family:'Hind Siliguri',Arial,Helvetica,sans-serif;color:#1f2933;">
                <tr>
                    <td style="padding:24px 28px 8px;text-align:center;">
                        @if (is_file($logo))
                            <img src="{{ $message->embed($logo) }}" alt="{{ $company }}" height="56" style="height:56px;width:auto;display:inline-block;">
                        @else
                            <strong style="font-size:18px;">{{ $company }}</strong>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td style="padding:8px 28px 4px;">
                        <h1 style="font-size:19px;line-height:1.4;margin:0 0 14px;color:#14532d;">{{ $noticeTitle }}</h1>
                        <p style="font-size:15px;line-height:1.6;margin:0 0 12px;">{{ __('event_emails.greeting', ['name' => $recipientName]) }}</p>
                        <p style="font-size:15px;line-height:1.6;margin:0 0 12px;">{!! nl2br(e($noticeBody)) !!}</p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:8px 28px 24px;">
                        <a href="{{ $portalUrl }}" style="display:inline-block;background:#14532d;color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:6px;font-size:15px;">{{ __('event_emails.open_portal') }}</a>
                    </td>
                </tr>
                <tr>
                    <td style="padding:14px 28px;border-top:1px solid #e3e8ee;font-size:12px;line-height:1.5;color:#616e7c;">{{ __('event_emails.footer', ['company' => $company]) }}</td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
