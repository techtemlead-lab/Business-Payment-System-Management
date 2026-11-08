@php
    $trust = fn (string $key): string => \App\Domain\Content\PublicTrustContent::text($key);
    $pageTitle = $trust('verify_title');
@endphp
@extends('reference.layout')
@section('title', $pageTitle.' · '.\App\Support\SiteCustomization::brandName())
@push('head')
<meta name="robots" content="noindex">
@endpush
@section('content')
<main id="main" class="ref-container ref-inner-main">
    <nav class="ref-breadcrumb" aria-label="{{ __('site.breadcrumb') }}"><a href="/" aria-label="{{ __('site.home') }}"><i class="fa-solid fa-house" aria-hidden="true"></i></a><span>/</span><span>{{ $pageTitle }}</span></nav>
    <article class="ref-content-card ref-about-hero">
        <h1>{{ $pageTitle }}</h1>
        <div class="ref-content-prose">{{ $trust('verify_lead') }}</div>
        <div class="ref-alert ref-error" role="note" style="white-space:pre-line;"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> {{ $trust('verify_warning') }}</div>
        <form class="ref-form" action="/verify-dealer" method="get">
            <label for="verify-code">{{ __('public_trust.verify_code_label') }}</label>
            <input id="verify-code" name="code" value="{{ $code }}" maxlength="40" required autocomplete="off" autocapitalize="characters">
            <button class="ref-primary-button" type="submit">{{ __('public_trust.verify_submit') }} <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i></button>
        </form>
    </article>

    @if($searched)
    <section class="ref-content-card" style="margin-top:22px;" aria-live="polite">
        <h2>{{ __('public_trust.verify_result_heading') }}</h2>
        @if($result === null)
            <div class="ref-alert ref-error" role="status"><b>{{ __('public_trust.verify_not_valid') }}</b><br>{{ __('public_trust.verify_not_found') }}</div>
        @else
            @if($result['valid'])
                <div class="ref-alert ref-success" role="status"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> <b>{{ __('public_trust.verify_valid') }}</b></div>
            @else
                <div class="ref-alert ref-error" role="status"><b>{{ __('public_trust.verify_not_valid') }}</b><br>{{ __('public_trust.verify_inactive_note') }}</div>
            @endif
            <dl style="display:grid;grid-template-columns:max-content 1fr;gap:8px 20px;margin:0;">
                <dt>{{ __('public_trust.verify_name') }}</dt><dd style="margin:0;font-weight:600;">{{ $result['name'] }}</dd>
                <dt>{{ __('public_trust.verify_type') }}</dt><dd style="margin:0;">{{ __('public_trust.verify_type_'.$result['type']) }}</dd>
                <dt>{{ __('public_trust.verify_area') }}</dt>
                <dd style="margin:0;">
                    @if($result['place_code'] !== null)
                        {{ $result['place_name'] !== null ? $result['place_name'].' ('.$result['place_code'].')' : $result['place_code'] }}
                    @else
                        {{ __('public_trust.verify_area_not_set') }}
                    @endif
                </dd>
                <dt>{{ __('public_trust.verify_status') }}</dt><dd style="margin:0;">{{ __('public_trust.verify_status_'.$result['status']) }}</dd>
            </dl>
        @endif
    </section>
    @endif

    @if(\App\Domain\Content\PublicTrustContent::isVisible('grievance'))
    <div class="ref-information-links" style="margin-top:22px;"><a href="/grievance" class="ref-secondary-button">{{ __('public_trust.footer_grievance') }}</a></div>
    @endif
</main>
@endsection
