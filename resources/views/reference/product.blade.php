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
@section('title',$item['name'].' · '.\App\Support\SiteCustomization::brandName())
@section('bodyClass','ref-detail-page')
@section('content')
<main id="main" class="ref-container ref-inner-main"><nav class="ref-breadcrumb" aria-label="{{ __('site.breadcrumb') }}"><a href="/" aria-label="{{ __('site.home') }}"><i class="fa-solid fa-house" aria-hidden="true"></i></a><span>/</span><a href="/products">{{ __('site.products') }}</a><span>/</span><span>{{ $item['name'] }}</span></nav>
@php
    $shareUrl = $social['url'] ?? url()->current();
    $shareText = $social['description'] ?? ($item['name'].' — '.config('app.name'));
    $encodedUrl = rawurlencode($shareUrl);
    $encodedText = rawurlencode($shareText.' '.$shareUrl);
@endphp
@if(!empty($sharePartner))
<div class="ref-content-card ref-share-partner">
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
@if(!empty($sharePartner) && filled(request()->query('ref')))
    @include('reference.partials.partner-enquiry-form', ['partner' => $sharePartner, 'productId' => $item['id'], 'code' => (string) request()->query('ref')])
@endif
<section class="ref-detail-card">
    <div class="ref-detail-image">
        @if(!empty($item['image_path']))
            <img src="{{ $item['image_path'] }}" alt="{{ $item['name'] }}">
        @else
            @php
                $stockImage = \App\Support\StockImage::forCategory($item['category'] ?? null, $item['name']);
            @endphp
            <div class="stock-image-frame">
                <img src="{{ $stockImage['url'] }}" alt="{{ $stockImage['alt'] }}" width="{{ $stockImage['width'] }}" height="{{ $stockImage['height'] }}" loading="lazy" decoding="async">
                <span class="stock-image-caption">{{ $stockImage['caption'] }}</span>
            </div>
        @endif
    </div>
    <div class="ref-detail-info">
        @if($concernTags !== [] || !empty($item['strength']))
        <div class="ref-product-concern-tags">
            @foreach($concernTags as $concernTag)
                <a class="ref-concern-tag" href="/products?concern={{ $concernTag['id'] }}">{{ $concernTag['name'] }}</a>
            @endforeach
            @if(!empty($item['strength']))
                <span class="ref-concern-tag potency"><i class="fa-solid fa-flask" aria-hidden="true"></i> {{ __('site.pd_strength') }}: {{ $item['strength'] }}</span>
            @endif
        </div>
        @endif

        <h1>{{ $item['name'] }}</h1>

        <div class="ref-detail-price">
            <span class="ref-detail-mrp"><small>{{ __('site.mrp') }}</small> <b>৳ {{ \App\Support\LocalDigits::text(number_format((float) $item['mrp'], 2)) }}</b></span>
            <span class="ref-detail-stock {{ $inStock ? 'is-in' : 'is-out' }}"><span class="ref-sr-only">{{ __('site.availability') }}:</span> {{ $stockLabel }}</span>
        </div>

        @if(! empty($sizes))
            @php
                $keepPartnerLink = filled(request()->query('ref')) ? '?ref='.rawurlencode((string) request()->query('ref')) : '';
            @endphp
            <div class="ref-detail-sizes">
                <span class="ref-detail-sizes-title">{{ __('size_groups.choose_size') }}</span>
                <div class="ref-card-sizes">
                    @foreach($sizes as $size)
                        <a class="ref-size-button{{ $size['stock_status'] === 'out_of_stock' ? ' is-out' : '' }}" href="{{ $size['url'].$keepPartnerLink }}" @if($size['selected']) aria-current="page" @endif>{{ $size['labels'][app()->getLocale()] ?? $size['labels']['en'] }}</a>
                    @endforeach
                </div>
            </div>
        @endif

        <dl class="ref-facts ref-detail-facts">
            <dt>{{ __('site.product_code') }}</dt><dd>{{ $item['sku'] }}</dd>
            <dt>{{ __('site.category') }}</dt><dd>{{ $item['category'] }}</dd>
            @if(!empty($item['unit']))
                <dt>{{ __('site.unit') }}</dt><dd>{{ $item['unit'] }}</dd>
            @endif
        </dl>

        <p class="ref-product-medical-note"><i class="fa-solid fa-user-doctor" aria-hidden="true"></i> <span>{{ $chrome('product_medical_note', $siteCustomization['product_medical_note'] ?? null) }}</span></p>

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

        <div class="ref-share-bar" data-share-panel>
            <span>{{ $chrome('product_share_label', $siteCustomization['product_share_label'] ?? null) }}</span>
            <button type="button" data-share-url aria-label="{{ __('site.share_product') }}"><i class="fa-solid fa-share-nodes" aria-hidden="true"></i></button>
            <button type="button" data-copy-url aria-label="{{ __('site.copy_product_link') }}"><i class="fa-solid fa-link" aria-hidden="true"></i></button>
            <a href="https://wa.me/?text={{ $encodedText }}" target="_blank" rel="noopener noreferrer" aria-label="WhatsApp"><i class="fa-brands fa-whatsapp"></i></a>
            <a href="https://www.facebook.com/dialog/send?link={{ $encodedUrl }}&app_id=0&redirect_uri={{ $encodedUrl }}" target="_blank" rel="noopener noreferrer" aria-label="{{ __('site.share_messenger') }}"><i class="fa-brands fa-facebook-messenger"></i></a>
            <a href="https://www.facebook.com/sharer/sharer.php?u={{ $encodedUrl }}" target="_blank" rel="noopener noreferrer" aria-label="Facebook"><i class="fa-brands fa-facebook-f"></i></a>
            <a href="https://twitter.com/intent/tweet?url={{ $encodedUrl }}&text={{ rawurlencode($shareText) }}" target="_blank" rel="noopener noreferrer" aria-label="X">{{-- Font Awesome 6.4.0 has no X glyph, so the logo is drawn inline --}}<svg class="ref-x-icon" viewBox="0 0 24 24" width="1em" height="1em" fill="currentColor" aria-hidden="true" focusable="false"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg></a>
            <a href="https://www.linkedin.com/sharing/share-offsite/?url={{ $encodedUrl }}" target="_blank" rel="noopener noreferrer" aria-label="LinkedIn"><i class="fa-brands fa-linkedin-in"></i></a>
            <a href="https://t.me/share/url?url={{ $encodedUrl }}&text={{ rawurlencode($shareText) }}" target="_blank" rel="noopener noreferrer" aria-label="Telegram"><i class="fa-brands fa-telegram"></i></a>
            <a href="mailto:?subject={{ rawurlencode($item['name']) }}&body={{ $encodedText }}" aria-label="{{ __('site.share_email') }}"><i class="fa-solid fa-envelope"></i></a>
            <a href="sms:?&body={{ $encodedText }}" aria-label="{{ __('site.share_sms') }}"><i class="fa-solid fa-comment-sms"></i></a>
        </div>
    </div>
</section>

@php
    $hasVideo = !empty($item['video_url']) || !empty($item['video_embed_url']);
    $hasDescription = filled($item['description'] ?? null);
    $qualityPoints = $facts['quality_points'];
    $usageSections = array_filter([
        ['fa-book-medical', __('site.pd_directions_title'), $facts['dose']],
        ['fa-seedling', __('product_facts.ingredients'), $facts['ingredients']],
        ['fa-triangle-exclamation', __('product_facts.warnings'), $facts['warnings']],
        ['fa-temperature-arrow-down', __('site.pd_storage_title'), $facts['storage']],
    ], fn (array $section): bool => $section[2] !== null);
    $factRows = array_filter([
        __('product_facts.manufacturer') => $facts['manufacturer'],
        __('product_facts.product_system') => $facts['product_system'],
        __('product_facts.registration_number') => $facts['registration_number'],
        __('product_facts.pack_size') => $facts['pack_size'],
    ], fn (?string $value): bool => $value !== null);
    $firstTab = $hasDescription ? 'description' : ($qualityPoints !== [] ? 'quality' : ($usageSections !== [] ? 'usage' : 'specification'));
@endphp

<section class="ref-product-description">
    <div class="ref-product-tabs" role="tablist" aria-label="{{ __('site.product_information') }}">
        @if($hasDescription)
        <button role="tab" id="description-tab" aria-controls="description-panel" aria-selected="true" data-info-tab="description-panel">
            <i class="fa-solid fa-align-left" aria-hidden="true"></i> {{ $chrome('product_tab_description', $siteCustomization['product_tab_description'] ?? null) }}
        </button>
        @endif
        @if($qualityPoints !== [])
        <button role="tab" id="quality-tab" aria-controls="quality-panel" aria-selected="{{ $firstTab === 'quality' ? 'true' : 'false' }}" data-info-tab="quality-panel">
            <i class="fa-solid fa-circle-check" aria-hidden="true"></i> {{ __('product_facts.quality_points') }}
        </button>
        @endif
        @if($usageSections !== [])
        <button role="tab" id="usage-tab" aria-controls="usage-panel" aria-selected="{{ $firstTab === 'usage' ? 'true' : 'false' }}" data-info-tab="usage-panel">
            <i class="fa-solid fa-flask" aria-hidden="true"></i> {{ __('site.product_tab_usage') }}
        </button>
        @endif
        <button role="tab" id="specification-tab" aria-controls="specification-panel" aria-selected="{{ $firstTab === 'specification' ? 'true' : 'false' }}" data-info-tab="specification-panel">
            <i class="fa-solid fa-table-list" aria-hidden="true"></i> {{ $chrome('product_tab_specification', $siteCustomization['product_tab_specification'] ?? null) }}
        </button>
        @if($hasVideo)
        <button role="tab" id="video-tab" aria-controls="video-panel" aria-selected="false" data-info-tab="video-panel">
            <i class="fa-solid fa-circle-play" aria-hidden="true"></i> {{ __('site.product_tab_video') }}
        </button>
        @endif
    </div>

    {{-- Description Panel --}}
    @if($hasDescription)
    <div id="description-panel" role="tabpanel" aria-labelledby="description-tab">
        <h2>{{ $chrome('product_tab_description', $siteCustomization['product_tab_description'] ?? null) }}</h2>
        <h3>{{ $item['name'] }}</h3>
        {{-- Kept on one line: this paragraph keeps the description's own line breaks (pre-wrap), so template indentation would show. --}}
        <p class="ref-product-description-text">{{ $item['description'] }}</p>
    </div>
    @endif

    {{-- Quality points the owner entered --}}
    @if($qualityPoints !== [])
    <div id="quality-panel" role="tabpanel" aria-labelledby="quality-tab" @if($firstTab !== 'quality') hidden @endif>
        <h2>{{ __('product_facts.quality_points') }}</h2>
        <ul class="ref-benefits-list">
            @foreach($qualityPoints as $qualityPoint)
            <li>
                <span class="ref-benefit-icon"><i class="fa-solid fa-circle-check" aria-hidden="true"></i></span>
                <div><p>{{ $qualityPoint }}</p></div>
            </li>
            @endforeach
        </ul>
    </div>
    @endif

    {{-- Usage, Ingredients, Warnings and Storage the owner entered --}}
    @if($usageSections !== [])
    <div id="usage-panel" role="tabpanel" aria-labelledby="usage-tab" @if($firstTab !== 'usage') hidden @endif>
        <h2>{{ __('site.product_tab_usage') }}</h2>
        <div class="ref-usage-content">
            @foreach($usageSections as [$icon, $title, $text])
            <div class="ref-usage-section">
                <h3><i class="fa-solid {{ $icon }}" aria-hidden="true"></i> {{ $title }}</h3>
                {{-- Kept on one line: this paragraph keeps the owner's own line breaks, so template indentation would show. --}}
                <p class="ref-product-description-text">{{ $text }}</p>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Specifications Panel --}}
    <div id="specification-panel" role="tabpanel" aria-labelledby="specification-tab" @if($firstTab !== 'specification') hidden @endif>
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
                @foreach($factRows as $label => $value)
                <tr>
                    <th>{{ $label }}</th>
                    <td>{{ $value }}</td>
                </tr>
                @endforeach
                <tr>
                    <th>{{ __('site.mrp') }}</th>
                    <td>৳ {{ \App\Support\LocalDigits::text(number_format((float) $item['mrp'], 2)) }}</td>
                </tr>
                <tr>
                    <th>{{ __('site.pd_col_distributor') }}</th>
                    <td>{{ \App\Support\SiteChrome::text('contact_company_title', $siteCustomization['contact_company_title'] ?? null) }}</td>
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
</section>
@include('reference.partials.product-reviews')
</main>
@endsection
