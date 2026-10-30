@php
    $chrome = fn (string $key, ?string $custom = null): string => \App\Support\SiteChrome::text($key, $custom);
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

        <form method="post" action="/feedback" class="ref-feedback-form" style="margin-top:22px;display:grid;gap:14px;max-width:640px">
            @csrf
            <input type="text" name="website" value="" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;opacity:0" aria-hidden="true">
            <label>
                <span style="display:block;font-size:12px;font-weight:700;margin-bottom:4px">{{ __('site.feedback_name') }}</span>
                <input type="text" name="name" value="{{ old('name', auth()->user()->name ?? '') }}" required maxlength="150" style="width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:8px">
                @error('name')<small style="color:#be123c">{{ $message }}</small>@enderror
            </label>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px">
                <label>
                    <span style="display:block;font-size:12px;font-weight:700;margin-bottom:4px">{{ __('site.feedback_role') }}</span>
                    <input type="text" name="role" value="{{ old('role') }}" maxlength="120" placeholder="{{ __('site.feedback_role_ph') }}" style="width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:8px">
                </label>
                <label>
                    <span style="display:block;font-size:12px;font-weight:700;margin-bottom:4px">{{ __('site.feedback_location') }}</span>
                    <input type="text" name="location" value="{{ old('location') }}" maxlength="120" placeholder="Dhaka" style="width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:8px">
                </label>
                <label>
                    <span style="display:block;font-size:12px;font-weight:700;margin-bottom:4px">{{ __('site.feedback_rating') }}</span>
                    <select name="rating" required style="width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:8px">
                        @for($i = 5; $i >= 1; $i--)
                            <option value="{{ $i }}" @selected((int) old('rating', 5) === $i)>{{ $i }} ★</option>
                        @endfor
                    </select>
                </label>
            </div>
            <label>
                <span style="display:block;font-size:12px;font-weight:700;margin-bottom:4px">{{ __('site.feedback_message') }}</span>
                <textarea name="message" rows="5" required minlength="20" maxlength="2000" style="width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:8px">{{ old('message') }}</textarea>
                @error('message')<small style="color:#be123c">{{ $message }}</small>@enderror
            </label>
            <p style="margin:0;font-size:12px;color:#64748b">{{ __('site.feedback_moderation_note') }}</p>
            <button type="submit" class="ref-primary-button" style="justify-self:start">{{ __('site.feedback_submit') }}</button>
        </form>
    </article>
</main>
@endsection
