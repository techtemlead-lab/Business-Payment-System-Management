@php
    $chrome = fn (string $key, ?string $custom = null): string => \App\Support\SiteChrome::text($key, $custom);
    $inStock = ($item['stock_status'] ?? 'out_of_stock') !== 'out_of_stock';
    $stockLabel = ! $inStock
        ? $chrome('out_of_stock', $siteCustomization['product_out_of_stock_label'] ?? null)
        : (($item['stock_status'] ?? '') === 'low_stock'
            ? __('site.low_stock')
            : $chrome('in_stock', $siteCustomization['product_in_stock_label'] ?? null));
@endphp
@extends('reference.layout')
@section('title',$item['name'].' · Madina Natural Health Care')
@section('bodyClass','ref-detail-page')
@section('content')
<main id="main" class="ref-container ref-inner-main"><nav class="ref-breadcrumb" aria-label="{{ __('site.breadcrumb') }}"><a href="/" aria-label="{{ __('site.home') }}"><i class="fa-solid fa-house" aria-hidden="true"></i></a><span>/</span><a href="/products">{{ __('site.products') }}</a><span>/</span><span>{{ $item['name'] }}</span></nav>
@php
    $shareUrl = $social['url'] ?? url()->current();
    $shareText = $social['description'] ?? ($item['name'].' — '.config('app.name'));
    $encodedUrl = rawurlencode($shareUrl);
    $encodedText = rawurlencode($shareText.' '.$shareUrl);
@endphp
<div class="ref-share-bar" data-share-panel>
    <span>{{ $chrome('product_share_label', $siteCustomization['product_share_label'] ?? null) }}</span>
    <button type="button" data-share-url aria-label="{{ __('site.share_product') }}"><i class="fa-solid fa-share-nodes" aria-hidden="true"></i></button>
    <button type="button" data-copy-url aria-label="{{ __('site.copy_product_link') }}"><i class="fa-solid fa-link" aria-hidden="true"></i></button>
    <a href="https://wa.me/?text={{ $encodedText }}" target="_blank" rel="noopener noreferrer" aria-label="WhatsApp"><i class="fa-brands fa-whatsapp"></i></a>
    <a href="https://www.facebook.com/sharer/sharer.php?u={{ $encodedUrl }}" target="_blank" rel="noopener noreferrer" aria-label="Facebook"><i class="fa-brands fa-facebook-f"></i></a>
    <a href="https://twitter.com/intent/tweet?url={{ $encodedUrl }}&text={{ rawurlencode($shareText) }}" target="_blank" rel="noopener noreferrer" aria-label="X"><i class="fa-brands fa-x-twitter"></i></a>
    <a href="https://www.linkedin.com/sharing/share-offsite/?url={{ $encodedUrl }}" target="_blank" rel="noopener noreferrer" aria-label="LinkedIn"><i class="fa-brands fa-linkedin-in"></i></a>
    <a href="https://t.me/share/url?url={{ $encodedUrl }}&text={{ rawurlencode($shareText) }}" target="_blank" rel="noopener noreferrer" aria-label="Telegram"><i class="fa-brands fa-telegram"></i></a>
</div>
@if(!empty($sharePartner))
<div class="ref-content-card" style="margin-bottom:16px;display:flex;gap:14px;align-items:center;flex-wrap:wrap;">
    @if(!empty($social['partner']['photo']))
        <img src="{{ $social['partner']['photo'] }}" alt="{{ $sharePartner->name }}" width="64" height="64" style="border-radius:50%;object-fit:cover;">
    @endif
    <div>
        <strong>{{ __('site.share_product_partner_headline', ['name' => $sharePartner->name]) }}</strong>
        <p style="margin:4px 0 0;">{{ __('site.share_product_partner_contact', ['name' => $sharePartner->name, 'phone' => $sharePartner->phone ?: '—']) }}</p>
    </div>
</div>
@endif
<section class="ref-detail-card"><div class="ref-detail-image">@if(!empty($item['image_path']))<img src="{{ $item['image_path'] }}" alt="{{ $item['name'] }}">@else<div class="ref-no-image" aria-hidden="true"><i class="fa-solid fa-leaf"></i><span>{{ __('site.photo_coming_soon') }}</span></div>@endif</div><div class="ref-detail-info"><h1>{{ $item['name'] }}</h1>
<div class="ref-metadata"><span>{{ __('site.mrp') }}: <b>{{ $item['mrp'] }} BDT</b></span><span>{{ __('site.availability') }}: <b class="{{ $inStock ? 'ref-green' : 'ref-orange' }}">{{ $stockLabel }}</b></span><span>{{ __('site.product_code') }}: <b>{{ $item['sku'] }}</b></span><span>{{ __('site.category') }}: <b>{{ $item['category'] }}</b></span></div>
<div class="ref-partner-information">
    <span class="ref-company-eyebrow">{{ __('site.product_network_eyebrow') }}</span>
    <h2>{{ __('site.product_available_title') }}</h2>
    <p>{{ __('site.product_available_body') }}</p>
    <div class="ref-company-intro-actions">@if($publicPageEnabled['dealers'] ?? true)<a href="/dealers" class="ref-primary-button"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> {{ __('site.find_a_dealer') }}</a>@elseif($publicPageEnabled['contact'] ?? true)<a href="/contact" class="ref-primary-button"><i class="fa-solid fa-headset" aria-hidden="true"></i> {{ __('site.contact_us') }}</a>@endif
    @if(auth()->check() && !auth()->user()->staff())
        <a href="{{ \App\Support\PortalHost::url(\App\Support\PortalHost::forUser(auth()->user()), '/catalog') }}" class="ref-secondary-button">{{ __('site.open_partner_dashboard') }}</a>
    @elseif(!auth()->check())
        <a href="{{ \App\Support\PortalHost::url('dealer', '/login') }}" class="ref-secondary-button">{{ __('site.dealer_subdealer_login') }}</a>
    @endif
    </div>
</div>
</div></section>
@if(!empty($item['video_url'])||!empty($item['video_embed_url']))<section class="ref-product-video"><h2>{{ $chrome('product_video_title', $siteCustomization['product_video_title'] ?? null) }}</h2>@if(!empty($item['video_embed_url']))<iframe src="{{ $item['video_embed_url'] }}" title="{{ $item['name'] }} {{ __('site.product_video_title') }}" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allow="encrypted-media; picture-in-picture; fullscreen" allowfullscreen></iframe>@else<video controls playsinline preload="metadata" @if(!empty($item['image_path'])) poster="{{ $item['image_path'] }}" @endif><source src="{{ $item['video_url'] }}">{{ __('site.product_video_unsupported') }}</video>@endif</section>@endif
<section class="ref-product-description"><div class="ref-product-tabs" role="tablist" aria-label="{{ __('site.product_information') }}"><button role="tab" id="description-tab" aria-controls="description-panel" aria-selected="true" data-info-tab="description-panel">{{ $chrome('product_tab_description', $siteCustomization['product_tab_description'] ?? null) }}</button><button role="tab" id="specification-tab" aria-controls="specification-panel" aria-selected="false" data-info-tab="specification-panel">{{ $chrome('product_tab_specification', $siteCustomization['product_tab_specification'] ?? null) }}</button></div><div id="description-panel" role="tabpanel" aria-labelledby="description-tab"><h2>{{ $chrome('product_tab_description', $siteCustomization['product_tab_description'] ?? null) }}</h2><h3>{{ $item['name'] }}</h3><p>{{ $item['description'] }}</p></div><div id="specification-panel" role="tabpanel" aria-labelledby="specification-tab" hidden><h2>{{ $chrome('product_tab_specification', $siteCustomization['product_tab_specification'] ?? null) }}</h2><table><tbody><tr><th>{{ __('site.product_code_short') }}</th><td>{{ $item['sku'] }}</td></tr><tr><th>{{ __('site.category') }}</th><td>{{ $item['category'] }}</td></tr><tr><th>{{ __('site.unit') }}</th><td>{{ $item['unit'] }}</td></tr><tr><th>{{ __('site.mrp') }}</th><td>{{ $item['mrp'] }} BDT</td></tr></tbody></table></div></section></main>
@endsection
