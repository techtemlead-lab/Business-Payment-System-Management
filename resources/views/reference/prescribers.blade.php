@php
    $chrome = fn (string $key, ?string $custom = null): string => \App\Support\SiteChrome::text($key, $custom);
    $pageTitle = $chrome('prescribers_title', $siteCustomization['prescribers_title'] ?? null);
    $emergencyNote = $chrome('prescribers_emergency_note', $siteCustomization['prescribers_emergency_note'] ?? null);
    $consentLine = $chrome('prescribers_consent_line', $siteCustomization['prescribers_consent_line'] ?? null);
    $openListing = (int) old('listing_id');
@endphp
@extends('reference.layout')
@section('title', $pageTitle.' · '.\App\Support\SiteCustomization::brandName())
@section('content')
<main id="main" class="ref-container ref-inner-main">
    <nav class="ref-breadcrumb" aria-label="{{ __('site.breadcrumb') }}">
        <a href="/" aria-label="{{ __('site.home') }}"><i class="fa-solid fa-house" aria-hidden="true"></i></a>
        <span>/</span>
        <span>{{ $pageTitle }}</span>
    </nav>

    @if(session('success'))
        <div class="ref-alert ref-success" role="status"><b>{{ session('success') }}</b></div>
    @endif
    @include('reference.partials.page-plate', [
        'title' => $pageTitle,
        'lead' => $chrome('prescribers_lead', $siteCustomization['prescribers_lead'] ?? null),
        'icon' => 'fa-user-doctor',
    ])

    <div class="ref-alert ref-error" role="note"><i class="fa-solid fa-truck-medical" aria-hidden="true"></i> <b>{{ $emergencyNote }}</b></div>

    @if($levels !== [])
    <section class="ref-directory-heading ref-directory-tools">
        <form method="get" class="ref-directory-filter">
            @foreach($levels as $level)
            <label>{{ __('prescriber_public.place_'.$level['level']) }}
                <select name="{{ $level['level'] }}" data-place-level>
                    <option value="">{{ __('prescriber_public.place_all') }}</option>
                    @foreach($level['options'] as $option)
                    <option value="{{ $option['id'] }}" @selected($level['selected'] === $option['id'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </label>
            @endforeach
            <button class="ref-primary-button">{{ __('prescriber_public.filter') }}</button>
            <a href="/prescribers">{{ __('prescriber_public.clear') }}</a>
        </form>
    </section>
    @endif

    <div class="ref-directory-grid">
        @forelse($rows as $row)
        <article class="ref-content-card" id="prescriber-{{ $row['listing_id'] }}">
            <div class="ref-dealer-icon"><i class="fa-solid fa-user-doctor" aria-hidden="true"></i></div>
            <h2>{{ $row['name'] }}</h2>
            <p class="ref-dealer-point"><i class="fa-solid fa-id-card" aria-hidden="true"></i> <b>{{ __('prescription.type_'.$row['registration_type']) }}</b> · {{ __('prescriber_public.reg_no') }} {{ $row['registration_number'] }}</p>
            @if($row['area'])
            <p><i class="fa-solid fa-location-dot" aria-hidden="true"></i> {{ $row['area'] }}</p>
            @endif
            @if($row['chamber_hours'])
            <p><i class="fa-regular fa-clock" aria-hidden="true"></i> {{ __('prescriber_public.hours') }}: {{ $row['chamber_hours'] }}</p>
            @endif
            <details @if($openListing === $row['listing_id']) open @endif>
                <summary class="ref-secondary-button">{{ __('prescriber_public.request_button') }}</summary>
                <form method="post" action="/prescribers/{{ $row['listing_id'] }}/consult" class="ref-feedback-form">
                    @csrf
                    <input type="hidden" name="listing_id" value="{{ $row['listing_id'] }}">
                    <input type="text" name="website" value="" tabindex="-1" autocomplete="off" class="ref-honeypot" aria-hidden="true">
                    @if($openListing === $row['listing_id'] && $errors->any())
                    <div class="ref-alert ref-error" role="alert">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
                    @endif
                    <label class="ref-feedback-field">
                        <span class="ref-feedback-label">{{ __('prescriber_public.form_name') }}</span>
                        <input type="text" name="name" value="{{ $openListing === $row['listing_id'] ? old('name') : '' }}" required maxlength="120" autocomplete="name" class="ref-feedback-input">
                    </label>
                    <label class="ref-feedback-field">
                        <span class="ref-feedback-label">{{ __('prescriber_public.form_phone') }}</span>
                        <input type="tel" name="phone" value="{{ $openListing === $row['listing_id'] ? old('phone') : '' }}" required maxlength="20" inputmode="tel" autocomplete="tel" class="ref-feedback-input">
                    </label>
                    <label class="ref-feedback-field">
                        <span class="ref-feedback-label">{{ __('prescriber_public.form_problem') }}</span>
                        <textarea name="problem" rows="3" required minlength="5" maxlength="2000" class="ref-feedback-input ref-feedback-textarea">{{ $openListing === $row['listing_id'] ? old('problem') : '' }}</textarea>
                    </label>
                    <label class="ref-feedback-field">
                        <span class="ref-feedback-label">{{ __('prescriber_public.form_preferred_time') }}</span>
                        <input type="text" name="preferred_time" value="{{ $openListing === $row['listing_id'] ? old('preferred_time') : '' }}" maxlength="120" class="ref-feedback-input">
                    </label>
                    <label class="ref-feedback-note">
                        <input type="checkbox" name="consent" value="1" required>
                        {{ $consentLine }}
                    </label>
                    <p class="ref-feedback-note">{{ __('prescriber_public.form_private') }}</p>
                    <p class="ref-feedback-note"><b>{{ $emergencyNote }}</b></p>
                    <button type="submit" class="ref-primary-button ref-feedback-submit">{{ __('prescriber_public.form_submit') }}</button>
                </form>
            </details>
        </article>
        @empty
        <div class="ref-empty">
            <i class="fa-solid fa-user-doctor" aria-hidden="true"></i>
            <h2>{{ __('prescriber_public.empty_title') }}</h2>
            <p>{{ __('prescriber_public.empty_body') }}</p>
        </div>
        @endforelse
    </div>

    @if($rows->hasPages())
    <nav class="ref-pagination" aria-label="{{ __('prescriber_public.pagination_label') }}">
        @if($rows->previousPageUrl())<a href="{{ $rows->previousPageUrl() }}">{{ __('prescriber_public.pagination_previous') }}</a>@endif
        <span>{{ __('prescriber_public.pagination_page_of', ['page' => \App\Support\LocalDigits::text($rows->currentPage()), 'pages' => \App\Support\LocalDigits::text($rows->lastPage())]) }}</span>
        @if($rows->nextPageUrl())<a href="{{ $rows->nextPageUrl() }}">{{ __('prescriber_public.pagination_next') }}</a>@endif
    </nav>
    @endif
</main>
<script @if($cspNonce = request()?->attributes?->get('csp_nonce')) nonce="{{ $cspNonce }}" @endif>
document.querySelectorAll('[data-place-level]').forEach(function (select) {
    select.addEventListener('change', function () { select.form.submit(); });
});
</script>
@endsection
