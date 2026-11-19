{!! $noticeTitle !!}

{!! $greeting ?? __('event_emails.greeting', ['name' => $recipientName]) !!}

{!! $noticeBody !!}

{!! $buttonLabel ?? __('event_emails.open_portal') !!}: {!! $portalUrl !!}

{!! $footer ?? __('event_emails.footer', ['company' => (string) (config('mail.from.name') ?: config('app.name'))]) !!}
