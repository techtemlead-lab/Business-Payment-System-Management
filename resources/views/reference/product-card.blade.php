@php
    $catName = $item['category'] ?? 'General';
    $catSlug = $item['category_slug'] ?? \Illuminate\Support\Str::slug($catName);
    $hasCustomImage = !empty($item['image_path']);
    $imageSrc = $hasCustomImage ? $item['image_path'] : '';
    $stockStatus = $item['stock_status'] ?? 'out_of_stock';
    $inStock = $stockStatus !== 'out_of_stock';
    $stockLabel = ! $inStock
        ? \App\Support\SiteChrome::text('out_of_stock', $siteCustomization['product_out_of_stock_label'] ?? null)
        : ($stockStatus === 'low_stock'
            ? __('site.low_stock')
            : \App\Support\SiteChrome::text('in_stock', $siteCustomization['product_in_stock_label'] ?? null));
    $mrp = (float) str_replace(',', '', preg_replace('/[^\d.]/', '', (string) ($item['mrp'] ?? '0')));
    $displayMrp = number_format($mrp, $mrp === floor($mrp) ? 0 : 2);
    $sku = $item['sku'] ?? 'MNHC-PROD';
    $unit = $item['unit'] ?? 'pack';
    $isFeatured = !empty($item['featured']);
@endphp
<article class="ref-product-card luxury-product-card"
    data-category="{{ $catSlug }}"
    data-id="{{ $item['id'] }}"
    data-name="{{ $item['name'] }}"
    data-mrp="৳ {{ $displayMrp }}"
    data-image="{{ $imageSrc ?: '/madina-header-logo.png?v=8' }}"
    data-category-name="{{ $catName }}"
    data-stock-status="{{ $stockStatus }}"
    data-desc="{{ $item['description'] ?? '' }}"
    data-url="{{ $item['url'] }}">

    {{-- Botanical Watercolor Corner Flourishes --}}
    <div class="luxury-card-botanical-bg" aria-hidden="true">
        <svg class="flourish-tl" viewBox="0 0 140 150" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M-5 10C25 35 45 75 40 120C38 135 30 145 20 155" stroke="#7a9775" stroke-width="1.2" stroke-linecap="round" opacity="0.45" />
            <path d="M25 45C42 35 52 48 45 60C38 72 22 60 25 45Z" fill="#8ca886" opacity="0.45" />
            <path d="M38 85C55 75 65 90 58 100C50 110 35 98 38 85Z" fill="#7d9b78" opacity="0.4" />
            <circle cx="32" cy="40" r="3.5" fill="#fdfcf7" stroke="#b2c6af" stroke-width="0.8" />
        </svg>
        <svg class="flourish-br" viewBox="0 0 200 220" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M195 215C165 175 145 125 155 65C160 35 175 15 195 0" stroke="#7a9775" stroke-width="1.4" stroke-linecap="round" opacity="0.55" />
            <path d="M148 95C128 80 115 95 125 110C135 125 152 110 148 95Z" fill="#8ca886" opacity="0.45" />
            <path d="M168 55C148 42 138 56 148 70C158 82 172 68 168 55Z" fill="#7d9b78" opacity="0.5" />
            <path d="M178 135C155 128 145 148 160 160C175 170 190 150 178 135Z" fill="#9db898" opacity="0.4" />
            <circle cx="132" cy="85" r="4" fill="#fdfcf7" stroke="#b2c6af" stroke-width="1" />
            <circle cx="162" cy="125" r="3.5" fill="#fdfcf7" stroke="#b2c6af" stroke-width="1" />
            <circle cx="142" cy="45" r="4" fill="#fdfcf7" stroke="#b2c6af" stroke-width="1" />
        </svg>
    </div>

    {{-- Card Header: Category Badge & Featured Ribbon --}}
    <div class="luxury-card-header">
        <div class="luxury-category-pill">
            <span>{{ $catName }}</span>
            <span style="font-size: 13px;" aria-hidden="true">🌿</span>
        </div>
        @if($isFeatured)
            <div class="luxury-featured-ribbon">
                <span>★</span>
                <span>{{ __('site.featured') ?? 'Featured' }}</span>
            </div>
        @endif
    </div>

    {{-- Hero Showcase Stage: Arched Halo & 3D Marble Pedestal --}}
    <div class="luxury-hero-stage">
        <div class="luxury-arched-halo" aria-hidden="true"></div>
        <div class="luxury-marble-pedestal" aria-hidden="true">
            <div class="pedestal-shadow"></div>
            <div class="pedestal-base"></div>
            <div class="pedestal-top"></div>
        </div>

        <div class="luxury-product-showcase">
            @if($hasCustomImage)
                <a href="{{ $item['url'] }}" aria-label="{{ $item['name'] }}">
                    <img class="luxury-product-img" src="{{ $imageSrc }}" alt="{{ $item['name'] }}" loading="lazy">
                </a>
            @else
                <a href="{{ $item['url'] }}" class="luxury-art-bottle" aria-label="{{ $item['name'] }}">
                    <div class="bottle-cap"></div>
                    <div class="bottle-gold-neck"></div>
                    <svg class="bottle-pendant" viewBox="0 0 20 30" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M2 2C8 12 16 12 18 2" stroke="#94a3b8" stroke-width="1.2" stroke-linecap="round" />
                        <path d="M10 10V16" stroke="#94a3b8" stroke-width="1" />
                        <path d="M10 16C6 18 6 24 10 28C14 24 14 18 10 16Z" fill="#cbd5e1" stroke="#64748b" stroke-width="0.8" />
                        <path d="M10 18V26" stroke="#64748b" stroke-width="0.6" />
                    </svg>
                    <div class="bottle-label">
                        <span class="brand">madina</span>
                        <span class="cat">{{ $catName }}</span>
                        <span class="sub">{{ __('site.brand_tagline') ?? 'NATURAL CARE' }}</span>
                    </div>
                </a>
            @endif
        </div>
    </div>

    {{-- Content & Typography Section --}}
    <div class="luxury-card-body">
        <div class="luxury-sku-row">
            <span>{{ $sku }} · {{ $unit }}</span>
        </div>

        <h3 class="luxury-product-title">
            <a href="{{ $item['url'] }}">{{ $item['name'] }}</a>
        </h3>

        <p class="luxury-desc" title="{{ $item['description'] ?? '' }}">
            {{ $item['description'] ?? 'Preview catalog item for client demonstration.' }}
        </p>

        {{-- Pricing Block --}}
        <div class="luxury-price-block">
            <div class="luxury-price-val">
                <span class="currency">৳</span>
                <span>{{ $displayMrp }}</span>
            </div>
            <div class="luxury-price-label">{{ __('site.mrp') }} ({{ __('site.retail_price') ?? 'Retail price' }})</div>
        </div>

        {{-- Action Row --}}
        <div class="luxury-action-row">
            <div class="luxury-action-left">
                <a class="luxury-btn-details" href="{{ $item['url'] }}">
                    {{ __('site.view_product_details') }}
                </a>
                <button type="button" class="luxury-btn-pause ref-quick-view-btn" data-quick-view="{{ $item['id'] }}" aria-label="{{ __('site.product_preview') }} {{ $item['name'] }}">
                    <i class="fa-solid fa-eye" aria-hidden="true"></i> {{ __('site.product_preview') }}
                </button>
            </div>

            <div class="luxury-action-right">
                <span class="luxury-status-caption">
                    <span class="ref-stock-dot" style="display:inline-block;width:6px;height:6px;border-radius:50%;background:{{ $inStock ? '#16a34a' : '#dc2626' }};margin-right:4px;"></span>
                    {{ $stockLabel }}
                </span>
            </div>
        </div>
    </div>
</article>
