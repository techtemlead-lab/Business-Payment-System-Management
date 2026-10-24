@extends('reference.layout')
@php
    $title = match ($mode) {
        'otp' => __('auth_ui.otp_title'),
        'forgot' => __('auth_ui.forgot_password'),
        'reset' => __('auth_ui.reset_password'),
        default => (($portal ?? 'dealer') === 'subdealer' ? __('auth_ui.subdealer_sign_in') : ($siteCustomization['auth_login_title'] ?? __('auth_ui.account_login'))),
    };
    $subtitle = match ($mode) {
        'otp' => __('auth_ui.otp_subtitle'),
        'forgot' => 'Enter your account email to request a reset link.',
        'reset' => 'Choose a new password for your account.',
        default => ($siteCustomization['auth_login_subtitle'] ?? __('auth_ui.welcome_back')),
    };
    $action = match ($mode) {
        'otp' => '/login/otp',
        'forgot' => '/forgot-password',
        'reset' => '/reset-password',
        default => '/login',
    };
@endphp
@section('title', $title.' · Madina Natural Health Care')
@section('content')
<main id="main" class="ref-container ref-auth-main">
    <section class="ref-auth-card">
        <div class="ref-auth-brand" style="text-align:center;margin-bottom:20px;">
            <a href="{{ \App\Support\PortalHost::url('public', '/') }}" aria-label="Home">
                <img src="/madina-header-logo.png?v=6" alt="Madina Natural Health Care" style="height:85px;width:85px;margin:0 auto;object-fit:contain;">
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
                <label for="account-email">{{ $mode === 'login' ? 'E-Mail / Phone Number' : 'E-Mail' }}</label>
                <input id="account-email" type="{{ $mode === 'login' ? 'text' : 'email' }}" name="email" required maxlength="254" autocomplete="username" value="{{ old('email', $email ?? '') }}" placeholder="{{ $mode === 'login' ? 'Enter your e-mail or phone number' : 'Enter your e-mail' }}" @if($errors->has('email')) aria-invalid="true" @endif>
                @if($mode !== 'forgot')
                    <div class="ref-password-label">
                        <label for="account-password">Password</label>
                        @if($mode === 'login')
                            <a href="/forgot-password">Forgot password?</a>
                        @endif
                    </div>
                    <div class="ref-password-field">
                        <input id="account-password" type="password" name="password" required @if($mode === 'reset') minlength="12" autocomplete="new-password" @else autocomplete="current-password" @endif maxlength="200" placeholder="Enter your password">
                        <button type="button" data-toggle-password="account-password" aria-label="Show password" aria-pressed="false"><i class="fa-regular fa-eye" aria-hidden="true"></i></button>
                    </div>
                @endif
                @if($mode === 'reset')
                    <label for="account-password-confirmation">Confirm Password</label>
                    <input id="account-password-confirmation" type="password" name="password_confirmation" minlength="12" maxlength="200" required autocomplete="new-password">
                    <p class="ref-form-hint">Use at least 12 characters with uppercase and lowercase letters and a number.</p>
                @endif
                <button class="ref-auth-submit" type="submit">{{ $mode === 'login' ? 'Login' : ($mode === 'forgot' ? 'Send Reset Link' : 'Reset Password') }}</button>
            @endif
        </form>
        <div class="ref-auth-bottom">
            @if($mode === 'login')
                {{ $siteCustomization['auth_dealer_help_text'] ?? 'Need a dealer account?' }}
                <a href="{{ \App\Support\PortalHost::url('public', '/contact') }}">{{ $siteCustomization['auth_dealer_contact_text'] ?? 'Contact the company' }}</a>
                <span class="ref-auth-sep" aria-hidden="true"> · </span>
                <a href="{{ \App\Support\PortalHost::url(($portal ?? 'dealer') === 'subdealer' ? 'dealer' : 'subdealer', '/login') }}">{{ ($portal ?? 'dealer') === 'subdealer' ? 'Dealer Sign In' : 'Sub-dealer Sign In' }}</a>
            @elseif($mode === 'otp')
                <a href="/login">{{ __('auth_ui.back_to_sign_in') }}</a>
            @else
                <a href="/login">Back to Login</a>
            @endif
        </div>
    </section>
</main>
@endsection
