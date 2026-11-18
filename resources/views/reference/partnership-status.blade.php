@extends('reference.layout')

@section('title', __('joining.status_title').' · '.\App\Support\SiteCustomization::brandName())

@section('content')
@php
    $application = $application ?? null;
    $payload = $application->payload ?? [];
    $minYear = (int) now()->year - 100;
    $maxYear = (int) now()->year - \App\Domain\Accounts\PartnerAge::MinimumYears;
    $birth = (string) old('date_of_birth', $payload['date_of_birth'] ?? '');
    [$birthYear, $birthMonth, $birthDay] = array_pad(explode('-', $birth), 3, '');
@endphp
<main id="main" class="ref-container ref-inner-main">
<section class="ref-content-card" style="max-width:720px;margin:32px auto;">
    <h1 class="ref-page-title">{{ __('joining.status_title') }}</h1>
    <p class="ref-partner-lead">{{ __('joining.status_hint') }}</p>

    @if($errors->any())
        <div class="ref-alert ref-error" role="alert">
            <ul class="ref-form-errors" style="margin:0;padding-left:20px;">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif
    @if(! empty($resubmitted))
        <div class="ref-alert ref-success" role="status">{{ __('joining.status_resubmitted') }}</div>
    @endif
    @if($lookupFailed ?? false)
        <div class="ref-alert ref-error" role="alert">{{ __('joining.status_not_found') }}</div>
    @endif

    @if($application)
        <div class="ref-alert {{ $application->status === 'approved' ? 'ref-success' : ($application->status === 'rejected' ? 'ref-error' : 'ref-notice') }}" role="status" style="font-size:1.1rem;">
            <p style="margin:0 0 6px;"><b>{{ __('joining.status_number') }}:</b> {{ $application->application_number }}</p>
            <p style="margin:0 0 6px;"><b>{{ __('joining.status_name') }}:</b> {{ $payload['name'] ?? '—' }}</p>
            <p style="margin:0;"><b>{{ __('joining.status_state') }}:</b> {{ __('joining.status_'.$application->status) }}</p>
            @if($application->status === 'approved')
                <p style="margin:10px 0 0;">{{ __('joining.status_approved_next') }}</p>
            @elseif($application->status === 'rejected')
                <p style="margin:10px 0 0;"><b>{{ __('joining.status_reason') }}:</b> {{ $application->reason ?: '—' }}</p>
            @elseif($application->status === 'correction')
                <p style="margin:10px 0 0;"><b>{{ __('joining.status_fix_needed') }}:</b> {{ $application->correction_note ?: $application->reason }}</p>
            @else
                <p style="margin:10px 0 0;">{{ __('joining.status_pending_next') }}</p>
            @endif
        </div>

        @if($application->status === 'correction')
        <form method="post" action="/partnership/status/resubmit" class="ref-partner-apply-form" novalidate>
            @csrf
            <input type="hidden" name="number" value="{{ $application->application_number }}">
            <input type="hidden" name="phone" value="{{ $application->phone_entered }}">
            <h2 class="ref-section-title">{{ __('joining.status_fix_title') }}</h2>
            <div class="ref-field-group">
                <label for="fix-name" class="ref-field-label"><span>{{ __('site.full_name') }}</span></label>
                <input id="fix-name" name="name" value="{{ old('name', $payload['name'] ?? '') }}" maxlength="150" class="ref-input-control">
            </div>
            <div class="ref-field-group">
                <label for="fix-nid" class="ref-field-label"><span>{{ __('joining.nid_label') }}</span></label>
                <input id="fix-nid" name="nid" inputmode="numeric" value="{{ old('nid', $payload['nid'] ?? '') }}" maxlength="17" class="ref-input-control">
                <small class="ref-field-hint">{{ __('joining.nid_hint') }}</small>
            </div>
            <div class="ref-field-group">
                <span class="ref-field-label">{{ __('site.partner_date_of_birth') }}</span>
                <div style="display:flex;gap:8px;">
                    <select name="dob_day" aria-label="{{ __('joining.dob_day') }}" class="ref-input-control">
                        <option value="">{{ __('joining.dob_day') }}</option>
                        @for($d = 1; $d <= 31; $d++)<option value="{{ $d }}" @selected((int) $birthDay === $d)>{{ $d }}</option>@endfor
                    </select>
                    <select name="dob_month" aria-label="{{ __('joining.dob_month') }}" class="ref-input-control">
                        <option value="">{{ __('joining.dob_month') }}</option>
                        @for($m = 1; $m <= 12; $m++)<option value="{{ $m }}" @selected((int) $birthMonth === $m)>{{ __('joining.month_'.$m) }}</option>@endfor
                    </select>
                    <select name="dob_year" aria-label="{{ __('joining.dob_year') }}" class="ref-input-control">
                        <option value="">{{ __('joining.dob_year') }}</option>
                        @for($y = $maxYear; $y >= $minYear; $y--)<option value="{{ $y }}" @selected((int) $birthYear === $y)>{{ $y }}</option>@endfor
                    </select>
                </div>
            </div>
            <div class="ref-field-group">
                <label for="fix-message" class="ref-field-label"><span>{{ __('joining.status_fix_message') }}</span></label>
                <textarea id="fix-message" name="message" rows="3" maxlength="2000" class="ref-textarea-control">{{ old('message') }}</textarea>
            </div>
            <button type="submit" class="ref-partner-submit-btn"><span>{{ __('joining.status_resend') }}</span></button>
        </form>
        @endif
    @endif

    <form method="post" action="/partnership/status" class="ref-partner-apply-form" novalidate style="margin-top:20px;">
        @csrf
        <div class="ref-field-group">
            <label for="status-number" class="ref-field-label"><span>{{ __('joining.status_number') }}</span></label>
            <input id="status-number" name="number" value="{{ old('number', $application->number_entered ?? '') }}" maxlength="30" autocomplete="off" placeholder="APP-1A2B3C4D" class="ref-input-control">
        </div>
        <div class="ref-field-group">
            <label for="status-phone" class="ref-field-label"><span>{{ __('site.mobile') }}</span></label>
            <input id="status-phone" name="phone" type="tel" value="{{ old('phone', $application->phone_entered ?? '') }}" maxlength="30" placeholder="01XXXXXXXXX" class="ref-input-control">
        </div>
        <button type="submit" class="ref-partner-submit-btn"><span>{{ __('joining.status_check') }}</span></button>
    </form>
</section>
</main>
@endsection
