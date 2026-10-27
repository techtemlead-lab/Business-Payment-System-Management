@extends('reference.layout')
@php
    // Auth screens are not share targets — skip homepage OG so locale UI stays free of English CMS/meta leakage.
    $social = false;
    $title = match ($mode) {
        'otp' => __('auth_ui.otp_title'),
        'forgot' => __('auth_ui.forgot_password'),
        'reset' => __('auth_ui.reset_password'),
        default => (($portal ?? 'dealer') === 'subdealer'
            ? __('auth_ui.subdealer_sign_in')
            : \App\Support\SiteCustomization::localizedCopy($siteCustomization ?? [], 'auth_login_title', 'auth_ui.account_login')),
    };
    $subtitle = match ($mode) {
        'otp' => __('auth_ui.otp_subtitle'),
        'forgot' => __('auth_ui.forgot_help'),
        'reset' => __('auth_ui.reset_help'),
        default => \App\Support\SiteCustomization::localizedCopy($siteCustomization ?? [], 'auth_login_subtitle', 'auth_ui.welcome_back'),
    };
    $action = match ($mode) {
        'otp' => '/login/otp',
        'forgot' => '/forgot-password',
        'reset' => '/reset-password',
        default => '/login',
    };
@endphp
@section('title', $title.' · '.__('portal.brand_company_bar'))
@section('content')
<main id="main" class="ref-container ref-auth-main">
    <section class="ref-auth-card">
        <div class="ref-auth-brand" style="text-align:center;margin-bottom:20px;">
            <a href="{{ \App\Support\PortalHost::url('public', '/') }}" aria-label="{{ __('portal.brand_home_aria') }}">
                <img src="/madina-header-logo.png?v=8" alt="{{ __('portal.brand_alt') }}" style="height:85px;width:85px;margin:0 auto;object-fit:contain;">
            </a>
        </div>
        <h1>{{ $title }}</h1>
        <p class="ref-auth-subtitle">{{ $subtitle }}</p>
        @if(session('success'))
            <div class="ref-alert ref-success" role="status">{{ session('success') }}</div>
        @endif
        @if($errors->any())
            <div class="ref-alert ref-error" role="alert">{{ $errors->first() }}</div>
        @endif
        <form class="ref-form" action="{{ $action }}" method="post">
            @csrf
            @if($mode === 'reset')
                <input type="hidden" name="token" value="{{ $token }}">
            @endif
            @if($mode === 'otp')
                <label for="login-otp-code">{{ __('auth_ui.otp_code') }}</label>
                <input id="login-otp-code" type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code" placeholder="{{ __('auth_ui.otp_placeholder') }}" @if($errors->has('code')) aria-invalid="true" @endif>
                <p class="ref-form-hint">{{ __('auth_ui.otp_hint') }}</p>
                <button class="ref-auth-submit" type="submit">{{ __('auth_ui.otp_verify') }}</button>
            @else
                <label for="account-email">{{ $mode === 'login' ? __('auth_ui.email_phone_or_code') : __('auth_ui.email') }}</label>
                <input id="account-email" type="{{ $mode === 'login' ? 'text' : 'email' }}" name="email" required maxlength="254" autocomplete="username" value="{{ old('email', $email ?? '') }}" placeholder="{{ $mode === 'login' ? __('auth_ui.email_phone_or_code_placeholder') : __('auth_ui.email_placeholder') }}" @if($errors->has('email')) aria-invalid="true" @endif>
                @if($mode !== 'forgot')
                    <div class="ref-password-label">
                        <label for="account-password">{{ __('auth_ui.password') }}</label>
                        @if($mode === 'login')
                            <a href="/forgot-password">{{ __('auth_ui.forgot_password_link') }}</a>
                        @endif
                    </div>
                    <div class="ref-password-field">
                        <input id="account-password" type="password" name="password" required @if($mode === 'reset') minlength="12" autocomplete="new-password" @else autocomplete="current-password" @endif maxlength="200" placeholder="{{ __('auth_ui.password_placeholder') }}">
                        <button type="button" data-toggle-password="account-password" aria-label="{{ __('auth_ui.show_password') }}" aria-pressed="false"><i class="fa-regular fa-eye" aria-hidden="true"></i></button>
                    </div>
                @endif
                @if($mode === 'reset')
                    <label for="account-password-confirmation">{{ __('auth_ui.confirm_password') }}</label>
                    <input id="account-password-confirmation" type="password" name="password_confirmation" minlength="12" maxlength="200" required autocomplete="new-password">
                    <p class="ref-form-hint">{{ __('auth_ui.password_rules') }}</p>
                @endif
                <button class="ref-auth-submit" type="submit">{{ $mode === 'login' ? __('auth_ui.sign_in') : ($mode === 'forgot' ? __('auth_ui.send_reset') : __('auth_ui.reset_password')) }}</button>
            @endif
        </form>
        <div class="ref-auth-bottom">
            @if($mode === 'login')
                {{ \App\Support\SiteCustomization::localizedCopy($siteCustomization ?? [], 'auth_dealer_help_text', 'auth_ui.dealer_help') }}
                <a href="{{ \App\Support\PortalHost::url('public', '/contact') }}">{{ \App\Support\SiteCustomization::localizedCopy($siteCustomization ?? [], 'auth_dealer_contact_text', 'auth_ui.contact_company') }}</a>
            @elseif($mode === 'otp')
                <a href="/login">{{ __('auth_ui.back_to_sign_in') }}</a>
            @else
                <a href="/login">{{ __('auth_ui.back_to_sign_in') }}</a>
            @endif
        </div>
    </section>
</main>
@endsection
