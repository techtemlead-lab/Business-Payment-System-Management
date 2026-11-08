@php
    use App\Domain\Content\PublicTrustContent;
    $trust = fn (string $key): string => PublicTrustContent::text($key);
    $pageTitle = $trust('grievance_title');
    $contactName = PublicTrustContent::field('grievance_contact_name');
    $contactRows = array_filter([
        'grievance_contact_role' => PublicTrustContent::field('grievance_contact_role'),
        'grievance_contact_phone' => PublicTrustContent::field('grievance_contact_phone'),
        'grievance_contact_email' => PublicTrustContent::field('grievance_contact_email'),
        'grievance_contact_hours' => PublicTrustContent::field('grievance_contact_hours'),
    ], fn (string $value): bool => $value !== '');
    $selectedTopic = old('topic', request('topic') === 'product_problem' ? 'product_problem' : 'complaint');
@endphp
@extends('reference.layout')
@section('title', $pageTitle.' · '.\App\Support\SiteCustomization::brandName())
@push('head')
<meta name="robots" content="noindex">
@endpush
@section('content')
<main id="main" class="ref-container ref-inner-main">
    <nav class="ref-breadcrumb" aria-label="{{ __('site.breadcrumb') }}"><a href="/" aria-label="{{ __('site.home') }}"><i class="fa-solid fa-house" aria-hidden="true"></i></a><span>/</span><span>{{ $pageTitle }}</span></nav>

    @if(session('grievance_tracking'))
    <div class="ref-alert ref-success" role="status">
        <b>{{ session('success') }}</b><br>
        {{ __('public_trust.grievance_tracking_label') }} <b>{{ session('grievance_tracking') }}</b><br>
        {{ __('public_trust.grievance_tracking_keep') }}
    </div>
    @endif

    <article class="ref-content-card ref-about-hero">
        <h1>{{ $pageTitle }}</h1>
        <div class="ref-content-prose">{{ $trust('grievance_lead') }}</div>
    </article>

    @if($contactName !== '')
    <section class="ref-content-card" style="margin-top:22px;">
        <h2>{{ __('public_trust.grievance_contact_heading') }}</h2>
        <p>{{ $trust('grievance_contact_note') }}</p>
        <dl style="display:grid;grid-template-columns:max-content 1fr;gap:8px 20px;margin:0;font-size:14px;">
            <dt>{{ __('public_trust.grievance_contact_name') }}</dt><dd style="margin:0;font-weight:600;">{{ $contactName }}</dd>
            @foreach($contactRows as $contactKey => $contactValue)
                <dt>{{ __('public_trust.'.$contactKey) }}</dt><dd style="margin:0;">{{ $contactValue }}</dd>
            @endforeach
        </dl>
    </section>
    @endif

    <section class="ref-content-card" style="margin-top:22px;">
        <h2>{{ __('public_trust.grievance_form_heading') }}</h2>
        @if($errors->any())<div class="ref-alert ref-error" role="alert">{{ $errors->first() }}</div>@endif
        <form class="ref-form" action="/grievance" method="post">
            @csrf
            <label for="grievance-name">{{ __('public_trust.grievance_form_name') }}</label>
            <input id="grievance-name" name="name" value="{{ old('name') }}" maxlength="150" required autocomplete="name">
            <label for="grievance-phone">{{ __('public_trust.grievance_form_phone') }}</label>
            <input id="grievance-phone" type="tel" name="phone" value="{{ old('phone') }}" maxlength="30" required autocomplete="tel">
            <label for="grievance-topic">{{ __('public_trust.grievance_form_topic') }}</label>
            <select id="grievance-topic" name="topic">
                @foreach(\App\Domain\Content\GrievanceInbox::Topics as $grievanceTopic)
                <option value="{{ $grievanceTopic }}" @selected($selectedTopic === $grievanceTopic)>{{ __('public_trust.topic_'.$grievanceTopic) }}</option>
                @endforeach
            </select>
            <p class="ref-form-hint">{{ __('public_trust.grievance_side_effect_note') }}</p>
            <label for="grievance-product">{{ __('public_trust.grievance_form_product') }}</label>
            <input id="grievance-product" name="product_name" value="{{ old('product_name') }}" maxlength="150" autocomplete="off">
            <label for="grievance-batch">{{ __('public_trust.grievance_form_batch') }}</label>
            <input id="grievance-batch" name="batch_number" value="{{ old('batch_number') }}" maxlength="60" autocomplete="off">
            <label for="grievance-message">{{ __('public_trust.grievance_form_message') }}</label>
            <textarea id="grievance-message" name="message" rows="5" minlength="10" maxlength="2000" required>{{ old('message') }}</textarea>
            <input name="website" type="text" class="ref-honeypot" tabindex="-1" autocomplete="off" aria-hidden="true">
            <button class="ref-primary-button" type="submit">{{ __('public_trust.grievance_form_submit') }} <i class="fa-solid fa-paper-plane" aria-hidden="true"></i></button>
        </form>
    </section>

    <section class="ref-content-card" style="margin-top:22px;" id="track">
        <h2>{{ __('public_trust.grievance_track_heading') }}</h2>
        @if($trackingNumber !== '')
            @if($trackedStatus !== null)
                <div class="ref-alert ref-success" role="status">{{ __('public_trust.grievance_track_status') }} <b>{{ __('public_trust.grievance_status_'.$trackedStatus) }}</b></div>
            @else
                <div class="ref-alert ref-error" role="status">{{ __('public_trust.grievance_track_not_found') }}</div>
            @endif
        @endif
        <form class="ref-form" action="/grievance#track" method="get">
            <label for="grievance-track">{{ __('public_trust.grievance_track_label') }}</label>
            <input id="grievance-track" name="track" value="{{ $trackingNumber }}" maxlength="24" required autocomplete="off" autocapitalize="characters">
            <button class="ref-primary-button" type="submit">{{ __('public_trust.grievance_track_submit') }}</button>
        </form>
    </section>
</main>
@endsection
