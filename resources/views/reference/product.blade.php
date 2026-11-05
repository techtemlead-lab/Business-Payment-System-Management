@php
    $chrome = fn (string $key, ?string $custom = null): string => \App\Support\SiteChrome::text($key, $custom);
    $paused = (bool) ($item['is_stock_paused'] ?? false);
    $inStock = ! $paused && ($item['stock_status'] ?? 'out_of_stock') !== 'out_of_stock';
    $stockLabel = $paused
        ? __('site.ordering_paused')
        : (! $inStock
            ? $chrome('out_of_stock', $siteCustomization['product_out_of_stock_label'] ?? null)
            : (($item['stock_status'] ?? '') === 'low_stock'
                ? __('site.low_stock')
                : $chrome('in_stock', $siteCustomization['product_in_stock_label'] ?? null)));
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
    <a href="https://www.facebook.com/dialog/send?link={{ $encodedUrl }}&app_id=0&redirect_uri={{ $encodedUrl }}" target="_blank" rel="noopener noreferrer" aria-label="{{ __('site.share_messenger') }}"><i class="fa-brands fa-facebook-messenger"></i></a>
    <a href="https://www.facebook.com/sharer/sharer.php?u={{ $encodedUrl }}" target="_blank" rel="noopener noreferrer" aria-label="Facebook"><i class="fa-brands fa-facebook-f"></i></a>
    <a href="https://twitter.com/intent/tweet?url={{ $encodedUrl }}&text={{ rawurlencode($shareText) }}" target="_blank" rel="noopener noreferrer" aria-label="X"><i class="fa-brands fa-x-twitter"></i></a>
    <a href="https://www.linkedin.com/sharing/share-offsite/?url={{ $encodedUrl }}" target="_blank" rel="noopener noreferrer" aria-label="LinkedIn"><i class="fa-brands fa-linkedin-in"></i></a>
    <a href="https://t.me/share/url?url={{ $encodedUrl }}&text={{ rawurlencode($shareText) }}" target="_blank" rel="noopener noreferrer" aria-label="Telegram"><i class="fa-brands fa-telegram"></i></a>
    <a href="mailto:?subject={{ rawurlencode($item['name']) }}&body={{ $encodedText }}" aria-label="{{ __('site.share_email') }}"><i class="fa-solid fa-envelope"></i></a>
    <a href="sms:?&body={{ $encodedText }}" aria-label="{{ __('site.share_sms') }}"><i class="fa-solid fa-comment-sms"></i></a>
</div>
@if(!empty($sharePartner))
<div class="ref-content-card" style="margin-bottom:16px;display:flex;gap:14px;align-items:center;flex-wrap:wrap;">
    @if(!empty($social['partner']['photo']))
        <img src="{{ $social['partner']['photo'] }}" alt="{{ $sharePartner->name }}" width="64" height="64" style="border-radius:50%;object-fit:cover;">
    @endif
    <div>
        <strong>{{ __('site.share_product_partner_headline', ['name' => $sharePartner->name]) }}</strong>
        @if(!empty($social['partner']['phone']))
            <p style="margin:4px 0 0;">{{ __('site.share_product_partner_contact', ['name' => $sharePartner->name, 'phone' => $social['partner']['phone']]) }}</p>
        @endif
    </div>
</div>
@endif
<section class="ref-detail-card">
    <div class="ref-detail-image">
        @if(!empty($item['image_path']))
            <img src="{{ $item['image_path'] }}" alt="{{ $item['name'] }}">
        @else
            <div class="ref-no-image" aria-hidden="true">
                <i class="fa-solid fa-leaf"></i>
                <span>{{ __('site.photo_coming_soon') }}</span>
            </div>
        @endif
    </div>
    <div class="ref-detail-info">
        <div class="ref-product-concern-tags">
            <span class="ref-concern-tag"><i class="fa-solid fa-leaf" aria-hidden="true"></i> {{ __('site.pd_tag_natural') }}</span>
            <span class="ref-concern-tag"><i class="fa-solid fa-certificate" aria-hidden="true"></i> {{ __('site.pd_tag_gmp') }}</span>
            <span class="ref-concern-tag"><i class="fa-solid fa-shield-heart" aria-hidden="true"></i> {{ __('site.pd_tag_safe') }}</span>
            @if(!empty($item['strength']))
                <span class="ref-concern-tag potency"><i class="fa-solid fa-flask" aria-hidden="true"></i> {{ __('site.pd_strength') }}: {{ $item['strength'] }}</span>
            @endif
        </div>

        <h1>{{ $item['name'] }}</h1>

        <div class="ref-metadata">
            <span>{{ __('site.mrp') }}: <b>৳ {{ \App\Support\LocalDigits::text(number_format((float) $item['mrp'], 2)) }}</b></span>
            <span>{{ __('site.availability') }}: <b class="{{ $inStock ? 'ref-green' : 'ref-orange' }}">{{ $stockLabel }}</b></span>
            <span>{{ __('site.product_code') }}: <b>{{ $item['sku'] }}</b></span>
            <span>{{ __('site.category') }}: <b>{{ $item['category'] }}</b></span>
            @if(!empty($item['unit']))
                <span>{{ __('site.unit') }}: <b>{{ $item['unit'] }}</b></span>
            @endif
        </div>

        <div class="ref-partner-information">
            <span class="ref-company-eyebrow">{{ __('site.product_network_eyebrow') }}</span>
            <h2>{{ __('site.product_available_title') }}</h2>
            <p>{{ __('site.product_available_body') }}</p>
            <div class="ref-company-intro-actions">
                @if($publicPageEnabled['dealers'] ?? true)
                    <a href="/dealers" class="ref-primary-button"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> {{ __('site.find_a_dealer') }}</a>
                @elseif($publicPageEnabled['contact'] ?? true)
                    <a href="/contact" class="ref-primary-button"><i class="fa-solid fa-headset" aria-hidden="true"></i> {{ __('site.contact_us') }}</a>
                @endif
                @if(auth()->check() && !auth()->user()->staff())
                    <a href="{{ \App\Support\PortalHost::url(\App\Support\PortalHost::forUser(auth()->user()), '/catalog') }}" class="ref-secondary-button">{{ __('site.open_partner_dashboard') }}</a>
                @elseif(!auth()->check())
                    <a href="{{ \App\Support\PortalHost::url('dealer', '/login') }}" class="ref-secondary-button">{{ __('site.dealer_subdealer_login') }}</a>
                @endif
            </div>
        </div>
    </div>
</section>

@php
    $hasVideo = !empty($item['video_url']) || !empty($item['video_embed_url']);
@endphp

<section class="ref-product-description">
    <div class="ref-product-tabs" role="tablist" aria-label="{{ __('site.product_information') }}">
        <button role="tab" id="description-tab" aria-controls="description-panel" aria-selected="true" data-info-tab="description-panel">
            <i class="fa-solid fa-align-left" aria-hidden="true"></i> {{ $chrome('product_tab_description', $siteCustomization['product_tab_description'] ?? null) }}
        </button>
        <button role="tab" id="benefits-tab" aria-controls="benefits-panel" aria-selected="false" data-info-tab="benefits-panel">
            <i class="fa-solid fa-star" aria-hidden="true"></i> {{ __('site.product_tab_benefits') }}
        </button>
        <button role="tab" id="usage-tab" aria-controls="usage-panel" aria-selected="false" data-info-tab="usage-panel">
            <i class="fa-solid fa-flask" aria-hidden="true"></i> {{ __('site.product_tab_usage') }}
        </button>
        <button role="tab" id="specification-tab" aria-controls="specification-panel" aria-selected="false" data-info-tab="specification-panel">
            <i class="fa-solid fa-table-list" aria-hidden="true"></i> {{ $chrome('product_tab_specification', $siteCustomization['product_tab_specification'] ?? null) }}
        </button>
        @if($hasVideo)
        <button role="tab" id="video-tab" aria-controls="video-panel" aria-selected="false" data-info-tab="video-panel">
            <i class="fa-solid fa-circle-play" aria-hidden="true"></i> {{ __('site.product_tab_video') }}
        </button>
        @endif
    </div>

    {{-- Description Panel --}}
    <div id="description-panel" role="tabpanel" aria-labelledby="description-tab">
        <h2>{{ $chrome('product_tab_description', $siteCustomization['product_tab_description'] ?? null) }}</h2>
        <h3>{{ $item['name'] }}</h3>
        <p style="font-size: 15px; line-height: 1.7; color: #334155; margin-bottom: 20px;">
            {{ $item['description'] ?: __('site.pd_default_description') }}
        </p>
        <div class="ref-product-highlights-grid">
            <div class="ref-highlight-box">
                <i class="fa-solid fa-leaf" aria-hidden="true"></i>
                <div>
                    <strong>{{ __('site.pd_feature_herbs_title') }}</strong>
                    <p>{{ __('site.pd_feature_herbs_body') }}</p>
                </div>
            </div>
            <div class="ref-highlight-box">
                <i class="fa-solid fa-check-double" aria-hidden="true"></i>
                <div>
                    <strong>{{ __('site.pd_feature_made_title') }}</strong>
                    <p>{{ __('site.pd_feature_made_body') }}</p>
                </div>
            </div>
        </div>
    </div>

    {{-- Key Benefits & Features Panel --}}
    <div id="benefits-panel" role="tabpanel" aria-labelledby="benefits-tab" hidden>
        <h2>{{ __('site.product_tab_benefits') }}</h2>
        <ul class="ref-benefits-list">
            <li>
                <span class="ref-benefit-icon"><i class="fa-solid fa-circle-check"></i></span>
                <div>
                    <strong>{{ __('site.pd_feature_natural_title') }}</strong>
                    <p>{{ __('site.pd_feature_natural_body') }}</p>
                </div>
            </li>
            <li>
                <span class="ref-benefit-icon"><i class="fa-solid fa-circle-check"></i></span>
                <div>
                    <strong>{{ __('site.pd_feature_care_title') }}</strong>
                    <p>{{ __('site.pd_feature_care_body') }}</p>
                </div>
            </li>
            <li>
                <span class="ref-benefit-icon"><i class="fa-solid fa-circle-check"></i></span>
                <div>
                    <strong>{{ __('site.pd_feature_advisers_title') }}</strong>
                    <p>{{ __('site.pd_feature_advisers_body') }}</p>
                </div>
            </li>
        </ul>
    </div>

    {{-- Usage, Directions & Ingredients Panel --}}
    <div id="usage-panel" role="tabpanel" aria-labelledby="usage-tab" hidden>
        <h2>{{ __('site.product_tab_usage') }}</h2>
        <div class="ref-usage-content">
            <div class="ref-usage-section">
                <h3><i class="fa-solid fa-book-medical" aria-hidden="true"></i> {{ __('site.pd_directions_title') }}</h3>
                <p>{{ __('site.pd_directions_body') }}</p>
            </div>
            <div class="ref-usage-section">
                <h3><i class="fa-solid fa-temperature-arrow-down" aria-hidden="true"></i> {{ __('site.pd_storage_title') }}</h3>
                <p>{{ __('site.pd_storage_body') }}</p>
            </div>
        </div>
    </div>

    {{-- Specifications Panel --}}
    <div id="specification-panel" role="tabpanel" aria-labelledby="specification-tab" hidden>
        <h2>{{ $chrome('product_tab_specification', $siteCustomization['product_tab_specification'] ?? null) }}</h2>
        <table>
            <tbody>
                <tr>
                    <th>{{ __('site.product_code_short') }}</th>
                    <td>{{ $item['sku'] }}</td>
                </tr>
                <tr>
                    <th>{{ __('site.category') }}</th>
                    <td>{{ $item['category'] }}</td>
                </tr>
                @if(!empty($item['unit']))
                <tr>
                    <th>{{ __('site.unit') }}</th>
                    <td>{{ $item['unit'] }}</td>
                </tr>
                @endif
                @if(!empty($item['strength']))
                <tr>
                    <th>{{ __('site.pd_col_strength') }}</th>
                    <td>{{ $item['strength'] }}</td>
                </tr>
                @endif
                <tr>
                    <th>{{ __('site.mrp') }}</th>
                    <td>৳ {{ \App\Support\LocalDigits::text(number_format((float) $item['mrp'], 2)) }}</td>
                </tr>
                <tr>
                    <th>{{ __('site.pd_col_distributor') }}</th>
                    <td>Madina Natural Health Care</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Watch Video Panel --}}
    @if($hasVideo)
    <div id="video-panel" role="tabpanel" aria-labelledby="video-tab" hidden>
        <h2>{{ __('site.product_tab_video') }}</h2>
        <div class="ref-product-video-wrapper">
            @if(!empty($item['video_embed_url']))
                <iframe src="{{ $item['video_embed_url'] }}" title="{{ $item['name'] }} {{ __('site.product_video_title') }}" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allow="encrypted-media; picture-in-picture; fullscreen" allowfullscreen></iframe>
            @else
                <video controls playsinline preload="metadata" @if(!empty($item['image_path'])) poster="{{ $item['image_path'] }}" @endif>
                    <source src="{{ $item['video_url'] }}">
                    {{ __('site.product_video_unsupported') }}
                </video>
            @endif
        </div>
    </div>
    @endif
</section></main>
@endsection
