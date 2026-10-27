@extends('reference.layout')
@section('title', __('site.partner_login_title').' · Madina Natural Health Care')
@section('bodyClass', 'ref-partner-login-page')
@section('content')
<main id="main" class="ref-container ref-auth-main">
    <section class="ref-auth-card ref-partner-login-card">
        <div class="ref-auth-brand" style="text-align:center;margin-bottom:20px;">
            <a href="/" aria-label="{{ __('site.back_home') }}">
                <img src="/madina-header-logo.png?v=8" alt="Madina Natural Health Care" style="height:85px;width:85px;margin:0 auto;object-fit:contain;">
            </a>
        </div>
        <h1>{{ __('site.partner_login_title') }}</h1>
        <p class="ref-auth-subtitle">{{ __('site.partner_login_subtitle') }}</p>
        <div class="ref-partner-login-options" role="list">
            <a class="ref-partner-login-option" role="listitem" href="{{ \App\Support\PortalHost::url('dealer', '/login') }}">
                <span class="ref-partner-login-icon" aria-hidden="true"><i class="fa-solid fa-store"></i></span>
                <span class="ref-partner-login-copy">
                    <b>{{ __('site.dealer_login') }}</b>
                    <small>{{ __('site.dealer_login_help') }}</small>
                </span>
                <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
            </a>
            <a class="ref-partner-login-option" role="listitem" href="{{ \App\Support\PortalHost::url('subdealer', '/login') }}">
                <span class="ref-partner-login-icon" aria-hidden="true"><i class="fa-solid fa-shop"></i></span>
                <span class="ref-partner-login-copy">
                    <b>{{ __('site.subdealer_login') }}</b>
                    <small>{{ __('site.subdealer_login_help') }}</small>
                </span>
                <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
            </a>
            <a class="ref-partner-login-option" role="listitem" href="{{ \App\Support\PortalHost::url('manage', '/login') }}">
                <span class="ref-partner-login-icon" aria-hidden="true"><i class="fa-solid fa-briefcase"></i></span>
                <span class="ref-partner-login-copy">
                    <b>{{ __('site.staff_login') }}</b>
                    <small>{{ __('site.staff_login_help') }}</small>
                </span>
                <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
            </a>
        </div>
        <div class="ref-auth-bottom"><a href="/">{{ __('site.back_home') }}</a></div>
    </section>
</main>
@endsection
