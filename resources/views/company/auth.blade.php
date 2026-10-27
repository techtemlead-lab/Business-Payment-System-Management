@php($ownerPortal = $ownerPortal ?? false)
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $ownerPortal ? __('portal.owner_portal') : __('portal.company_portal') }} · {{ $mode==='login'?($ownerPortal?__('auth_ui.owner_sign_in'):__('auth_ui.staff_sign_in')):($mode==='totp'?__('auth_ui.totp_title'):($mode==='forgot'?__('auth_ui.forgot_password'):__('auth_ui.reset_password'))) }} · {{ __('portal.brand_company_bar') }}</title>
    <link rel="stylesheet" href="/reference/instrument-sans-local.css">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=noto-sans-bengali:400,500,600,700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css','resources/css/dashboard.css'])
</head>
<body class="company-auth locale-{{ app()->getLocale() }}">
<main class="company-auth-main">
    <div class="company-auth-toolbar">
        <form method="post" action="/locale" class="language-switcher compact" aria-label="{{ __('portal.language') }}">@csrf
            <input type="hidden" name="locale" value="{{ app()->getLocale() === 'bn' ? 'en' : 'bn' }}">
            <button type="submit" class="active">{{ app()->getLocale() === 'bn' ? __('portal.english') : __('portal.bangla') }}</button>
        </form>
    </div>
    <div class="company-auth-brand"><img src="/madina-header-logo.png?v=8" alt="{{ __('portal.brand_alt') }}"></div>
    <section class="company-auth-card">
        <span class="eyebrow">{{ $ownerPortal ? __('portal.owner_portal') : __('portal.company_portal') }}</span>
        <h1>{{ $mode==='login'?($ownerPortal?__('auth_ui.owner_sign_in'):__('auth_ui.staff_sign_in')):($mode==='totp'?__('auth_ui.totp_title'):($mode==='forgot'?__('auth_ui.forgot_password'):__('auth_ui.reset_password'))) }}</h1>
        <p>{{ $mode==='login'?($ownerPortal?__('auth_ui.owner_help'):__('auth_ui.staff_help')):($mode==='totp'?__('auth_ui.totp_help'):__('auth_ui.recovery_help')) }}</p>
        @if(session('success'))<div class="success" role="status">{{ session('success') }}</div>@endif
        @if($errors->any())<div class="error" role="alert">{{ $errors->first() }}</div>@endif
        @if($mode === 'totp')
        <form class="action-form" method="post" action="/login/2fa">@csrf
            <label>{{ __('auth_ui.totp_code') }}<input type="text" name="code" inputmode="numeric" maxlength="20" required autocomplete="one-time-code" placeholder="{{ __('auth_ui.totp_placeholder') }}"></label>
            <p class="muted">{{ __('auth_ui.totp_hint') }}</p>
            <button class="button dark" type="submit">{{ __('auth_ui.totp_verify') }}</button>
        </form>
        <div class="company-auth-help"><a href="/login">{{ __('auth_ui.back_to_sign_in') }}</a></div>
        @else
        <form class="action-form" method="post" action="{{ $mode==='login'?'/login':($mode==='forgot'?'/forgot-password':'/reset-password') }}">@csrf
            @if($mode==='reset')<input type="hidden" name="token" value="{{ $token }}">@endif
            <label>{{ $mode==='login'?__('auth_ui.email_or_phone'):__('auth_ui.email') }}<input type="{{ $mode==='login'?'text':'email' }}" name="email" required maxlength="254" autocomplete="username" value="{{ old('email',$email??'') }}" placeholder="{{ $mode==='login'?__('auth_ui.email_or_phone_placeholder'):__('auth_ui.email_placeholder') }}"></label>
            @if($mode!=='forgot')<label>{{ __('auth_ui.password') }}<input type="password" name="password" required maxlength="200" autocomplete="{{ $mode==='reset'?'new-password':'current-password' }}" @if($mode==='reset') minlength="12" @endif></label>@endif
            @if($mode==='reset')<label>{{ __('auth_ui.confirm_password') }}<input type="password" name="password_confirmation" required minlength="12" maxlength="200" autocomplete="new-password"></label><p class="muted">{{ __('auth_ui.password_rules') }}</p>@endif
            <button class="button dark" type="submit">{{ $mode==='login'?__('auth_ui.sign_in'):($mode==='forgot'?__('auth_ui.send_reset'):__('auth_ui.reset_password')) }}</button>
        </form>
        <div class="company-auth-help"><a href="{{ $mode==='login'?'/forgot-password':'/login' }}">{{ $mode==='login'?__('auth_ui.forgot_password_link'):__('auth_ui.back_to_sign_in') }}</a></div>
        @if($ownerPortal && $mode === 'login')
        <div class="company-auth-help" data-tour="owner-login-portals">
            <a href="{{ \App\Support\PortalHost::url('manage', '/login') }}">{{ __('auth_ui.employee_sign_in') }}</a>
            · <a href="{{ \App\Support\PortalHost::url('dealer', '/login') }}">{{ __('auth_ui.dealer_sign_in') }}</a>
            · <a href="{{ \App\Support\PortalHost::url('subdealer', '/login') }}">{{ __('auth_ui.subdealer_sign_in') }}</a>
        </div>
        @endif
        @endif
    </section>
    <p class="company-auth-footer">{{ __('portal.brand_company_bar') }} · {{ $ownerPortal ? __('portal.owner_portal') : __('portal.company_portal') }}</p>
</main>
</body>
</html>
