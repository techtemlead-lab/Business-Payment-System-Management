<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer"><meta name="robots" content="noindex,nofollow">
    <title>{{ __('auth_ui.reset_password') }} · {{ __('portal.brand_company_bar') }}</title>
    @vite('resources/css/app.css')
</head>
<body>
<div class="auth-page">
    <section class="auth-story">
        <a href="/" class="brand" aria-label="{{ __('portal.brand_home_aria') }}">madina<small>{{ __('portal.brand_company_bar') }}</small></a>
        <div>
            <div class="eyebrow">{{ __('auth_ui.partner_workspace_eyebrow') }}</div>
            <h1>{{ __('auth_ui.fresh_start_heading') }}<br>{{ __('auth_ui.fresh_start_subheading') }}</h1>
            <p>{{ __('auth_ui.account_staff_maintained') }}</p>
        </div>
        <span>{{ __('auth_ui.brand_tagline') }}</span>
    </section>
    <main class="auth-main">
        <a href="/" class="text-link">{{ __('auth_ui.back_to_website') }}</a>
        <div class="auth-form">
            <h1>{{ __('auth_ui.set_password_heading') }}</h1>
            <p>{{ __('auth_ui.password_rules') }}</p>
            @if($errors->any())<div class="error" role="alert">{{ $errors->first() }}</div>@endif
            <form class="action-form" action="/reset-password" method="post">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <label>{{ __('auth_ui.email') }}<input required type="email" name="email" autocomplete="username" value="{{ old('email', $email) }}"></label>
                <label>{{ __('auth_ui.password') }}<input required type="password" name="password" minlength="12" autocomplete="new-password"></label>
                <label>{{ __('auth_ui.confirm_password') }}<input required type="password" name="password_confirmation" minlength="12" autocomplete="new-password"></label>
                <button class="button dark">{{ __('auth_ui.set_password_submit') }}</button>
            </form>
        </div>
    </main>
</div>
</body>
</html>
