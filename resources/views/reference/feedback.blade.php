@php
    $chrome = fn (string $key, ?string $custom = null): string => \App\Support\SiteChrome::text($key, $custom);
    $selectedRating = max(1, min(5, (int) old('rating', 5)));
@endphp
@extends('reference.layout')
@section('title', __('site.feedback_page_title').' · Madina Natural Health Care')
@section('content')
<main id="main" class="ref-container ref-inner-main">
    <nav class="ref-breadcrumb" aria-label="{{ __('site.breadcrumb') }}">
        <a href="/" aria-label="{{ __('site.home') }}"><i class="fa-solid fa-house" aria-hidden="true"></i></a>
        <span>/</span>
        <span>{{ __('site.feedback_page_title') }}</span>
    </nav>

    @if(session('success'))
        <div class="ref-alert ref-notice" role="status"><b>{{ session('success') }}</b></div>
    @endif

    <article class="ref-content-card">
        <h1>{{ __('site.feedback_page_title') }}</h1>
        <p class="ref-content-prose">{{ __('site.feedback_page_lead') }}</p>

        <form method="post" action="/feedback" class="ref-feedback-form">
            @csrf
            <input type="text" name="website" value="" tabindex="-1" autocomplete="off" class="ref-honeypot" aria-hidden="true">

            <label class="ref-feedback-field">
                <span class="ref-feedback-label">{{ __('site.feedback_name') }}</span>
                <input type="text" name="name" value="{{ old('name', auth()->user()->name ?? '') }}" required maxlength="150" class="ref-feedback-input">
                @error('name')<small class="ref-feedback-error">{{ $message }}</small>@enderror
            </label>

            <div class="ref-feedback-meta-row">
                <label class="ref-feedback-field">
                    <span class="ref-feedback-label">{{ __('site.feedback_role') }}</span>
                    <input type="text" name="role" value="{{ old('role') }}" maxlength="120" placeholder="{{ __('site.feedback_role_ph') }}" class="ref-feedback-input">
                </label>
                <label class="ref-feedback-field">
                    <span class="ref-feedback-label">{{ __('site.feedback_location') }}</span>
                    <input type="text" name="location" value="{{ old('location') }}" maxlength="120" placeholder="Dhaka" class="ref-feedback-input">
                </label>
            </div>

            <fieldset class="ref-star-rating" data-selected="{{ $selectedRating }}">
                <legend class="ref-feedback-label">{{ __('site.feedback_rating') }}</legend>
                <div class="ref-star-rating-choices">
                    @for($i = 5; $i >= 1; $i--)
                        <input
                            type="radio"
                            class="ref-star-rating-input"
                            name="rating"
                            id="feedback-rating-{{ $i }}"
                            value="{{ $i }}"
                            @checked($selectedRating === $i)
                            required
                        >
                        <label class="ref-star-rating-option" for="feedback-rating-{{ $i }}" title="{{ __('site.feedback_rating_stars', ['count' => $i]) }}">
                            <span class="ref-sr-only">{{ __('site.feedback_rating_stars', ['count' => $i]) }}</span>
                            <span class="ref-star-rating-glyph" aria-hidden="true">★</span>
                        </label>
                    @endfor
                </div>
                <p class="ref-star-rating-caption" data-rating-caption>{{ __('site.feedback_rating_stars', ['count' => $selectedRating]) }}</p>
            </fieldset>
            @error('rating')<small class="ref-feedback-error">{{ $message }}</small>@enderror

            <label class="ref-feedback-field">
                <span class="ref-feedback-label">{{ __('site.feedback_message') }}</span>
                <textarea name="message" rows="5" required minlength="20" maxlength="2000" class="ref-feedback-input ref-feedback-textarea">{{ old('message') }}</textarea>
                @error('message')<small class="ref-feedback-error">{{ $message }}</small>@enderror
            </label>

            <p class="ref-feedback-note">{{ __('site.feedback_moderation_note') }}</p>
            <button type="submit" class="ref-primary-button ref-feedback-submit">{{ __('site.feedback_submit') }}</button>
        </form>
    </article>
</main>
<script>
(function () {
    var root = document.querySelector('.ref-star-rating');
    if (!root) return;
    var caption = root.querySelector('[data-rating-caption]');
    var labels = {
        @for($i = 1; $i <= 5; $i++)
            {{ $i }}: @json(__('site.feedback_rating_stars', ['count' => $i]))@if($i < 5),@endif
        @endfor
    };
    root.querySelectorAll('.ref-star-rating-input').forEach(function (input) {
        input.addEventListener('change', function () {
            var value = Number(input.value) || 5;
            root.setAttribute('data-selected', String(value));
            if (caption) caption.textContent = labels[value] || String(value);
        });
    });
})();
</script>
@endsection
