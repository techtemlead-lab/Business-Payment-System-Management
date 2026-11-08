{!! $noticeTitle !!}

{!! __('event_emails.greeting', ['name' => $recipientName]) !!}

{!! $noticeBody !!}

{!! __('event_emails.open_portal') !!}: {!! $portalUrl !!}

{!! __('event_emails.footer', ['company' => (string) (config('mail.from.name') ?: config('app.name'))]) !!}
