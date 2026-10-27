@extends('reference.layout')

@section('title', $social['title'] ?? __('site.share_refer_title', ['name' => $partner->name, 'company' => config('app.name')]))

@section('content')
@php
    $contactUrl = '/contact?referred_by='.rawurlencode($code).'&topic=partnership_enquiry#enquiry';
    $partnershipUrl = '/partnership?sponsor_code='.rawurlencode($code).'#partner-apply';
    $photo = $social['partner']['photo'] ?? null;
@endphp
<section class="ref-content-card" style="max-width:720px;margin:32px auto;">
    <div style="display:flex;gap:18px;align-items:center;flex-wrap:wrap;">
        @if($photo)
            <img src="{{ $photo }}" alt="{{ $partner->name }}" width="96" height="96" style="border-radius:50%;object-fit:cover;border:3px solid #d1fae5;">
        @else
            <div style="width:96px;height:96px;border-radius:50%;background:#ecfdf5;color:#047857;display:flex;align-items:center;justify-content:center;font-size:36px;font-weight:700;">{{ mb_substr($partner->name, 0, 1) }}</div>
        @endif
        <div>
            <p class="muted" style="margin:0 0 4px;">{{ __('site.share_refer_eyebrow') }}</p>
            <h1 class="ref-page-title" style="margin:0;">{{ $partner->name }}</h1>
            <p style="margin:8px 0 0;"><b>{{ __('site.share_refer_code_label') }}:</b> {{ $code }}</p>
            @if($partner->phone)
                <p style="margin:4px 0 0;"><b>{{ __('site.share_refer_phone_label') }}:</b> {{ $partner->phone }}</p>
            @endif
        </div>
    </div>
    <p style="margin-top:18px;">{{ $social['description'] ?? '' }}</p>
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:18px;">
        <a class="ref-primary-button" href="{{ $contactUrl }}">{{ __('site.share_refer_contact_cta') }}</a>
        @if($publicPageEnabled['partnership'] ?? true)
            <a class="ref-primary-button" style="background:#0f766e;" href="{{ $partnershipUrl }}">{{ __('site.share_refer_apply_cta') }}</a>
        @endif
    </div>
</section>
<meta http-equiv="refresh" content="8;url={{ $contactUrl }}">
@endsection
