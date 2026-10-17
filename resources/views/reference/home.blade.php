@php
    $reference = json_decode(file_get_contents(public_path('reference/home-products.json')), true);
    $referenceProducts = $referencePreview ? $reference['products'] : [];

    $slides = $siteCustomization['slides'] ?? [];
    if (empty($slides)) {
        $slides = [
            ['image' => '/branding/madina-hero-banner-1.jpg', 'link' => '/products', 'alt' => 'Madina Natural Health Care - Authentic Herbal & Wellness Products'],
            ['image' => '/branding/madina-hero-banner-2.jpg', 'link' => '/products', 'alt' => 'Madina Authentic Unani & Herbal Solutions'],
            ['image' => '/branding/madina-hero-banner-3.jpg', 'link' => '/about', 'alt' => 'Madina Holistic Wellness & Physiotherapy Center'],
        ];
    }

    $sideBanner1Slides = $siteCustomization['side_banner_1_slides'] ?? [];
    if (empty($sideBanner1Slides)) {
        $sideBanner1Slides = [
            ['image' => $siteCustomization['side_banner_1_image'] ?? '/branding/madina-side-banner-1.jpg', 'link' => $siteCustomization['side_banner_1_link'] ?? '/products', 'alt' => $siteCustomization['side_banner_1_alt'] ?? 'Madina Premium Natural Care - 100% Pure Botanical Ingredients'],
            ['image' => '/branding/madina-top-skincare.jpg', 'link' => '/products', 'alt' => 'Madina Pure Herbal Skincare & Hair Vitality'],
            ['image' => '/branding/madina-top-immunity.jpg', 'link' => '/products', 'alt' => 'Madina Daily Immunity & Vitality Boosters'],
        ];
    }

    $sideBanner2Slides = $siteCustomization['side_banner_2_slides'] ?? [];
    if (empty($sideBanner2Slides)) {
        $sideBanner2Slides = [
            ['image' => $siteCustomization['side_banner_2_image'] ?? '/branding/madina-side-banner-2.jpg', 'link' => $siteCustomization['side_banner_2_link'] ?? '/dealers', 'alt' => $siteCustomization['side_banner_2_alt'] ?? 'Madina Authorized Dealer Network - Nationwide Distribution'],
            ['image' => '/branding/madina-promotion-popup.jpg', 'link' => '/about', 'alt' => 'Madina Natural Health Care & Physiotherapy Center'],
            ['image' => '/branding/madina-bottom-pharmacy.jpg', 'link' => '/dealers', 'alt' => 'Madina Authorized Partner Pharmacy Outlets Across All 64 Districts'],
        ];
    }

    $shortcuts = $siteCustomization['shortcuts'] ?? [];
    if (empty($shortcuts)) {
        $shortcuts = [
            ['title'=>'Our Products','subtitle'=>'browse wellness items','icon'=>'fa-seedling','link'=>'/products'],
            ['title'=>'Raise a Complain','subtitle'=>'Share your experience','icon'=>'fa-comments','link'=>'/contact?topic=Complaint'],
            ['title'=>'Our Services','subtitle'=>'Natural Health Care and Physiotherapy Center','icon'=>'fa-hand-holding-heart','link'=>'/about'],
            ['title'=>'Contact Us','subtitle'=>'Support & Inquiries','icon'=>'fa-headset','link'=>'/contact'],
            ['title'=>'Our Projects','subtitle'=>'Entrepreneur','icon'=>'fa-diagram-project','link'=>'/dealers']
        ];
    }
@endphp

@extends('reference.layout')
@section('title','Madina Natural Health Care · Products & Dealer Network')
@section('content')
<main id="main" class="ref-container ref-main">
    <section class="ref-promotions" aria-label="Promotional banners">
        <div class="ref-carousel ref-carousel-instance" aria-roledescription="carousel" aria-label="Featured promotions">
            <div class="ref-slides">
                @foreach($slides as $idx => $slide)
                <a href="{{ $slide['link'] ?: '/products' }}" class="ref-slide {{ $loop->first ? 'is-active' : '' }}" aria-hidden="{{ $loop->first ? 'false' : 'true' }}">
                    <img src="{{ ($slide['image'] ?: ('/reference/slider-0' . ($idx + 1) . '.webp')) . '?v=20261015' }}" alt="{{ $slide['alt'] ?: ('Promotion ' . ($idx + 1)) }}" @if($loop->first) fetchpriority="high" @else loading="lazy" @endif>
                </a>
                @endforeach
            </div>
            <button class="ref-carousel-arrow prev" aria-label="Previous promotion"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></button>
            <button class="ref-carousel-arrow next" aria-label="Next promotion"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>
            <div class="ref-carousel-dots">
                @foreach($slides as $idx => $slide)
                <button class="{{ $loop->first ? 'is-active' : '' }}" data-slide="{{ $idx }}" aria-label="Show promotion {{ $idx + 1 }}" aria-current="{{ $loop->first ? 'true' : 'false' }}"></button>
                @endforeach
            </div>
        </div>
        <div class="ref-side-banners">
            <div class="ref-side-carousel ref-carousel-instance" aria-roledescription="carousel" aria-label="Top side promotion">
                <div class="ref-slides">
                    @foreach($sideBanner1Slides as $idx => $slide)
                    <a href="{{ $slide['link'] ?: '/products' }}" class="ref-slide {{ $loop->first ? 'is-active' : '' }}" aria-hidden="{{ $loop->first ? 'false' : 'true' }}">
                        <img src="{{ ($slide['image'] ?: '/branding/madina-side-banner-1.jpg') . '?v=20261015' }}" alt="{{ $slide['alt'] ?: 'Madina Premium Natural Care' }}" @if($loop->first) fetchpriority="high" @else loading="lazy" @endif>
                    </a>
                    @endforeach
                </div>
                <button class="ref-carousel-arrow prev" aria-label="Previous promotion"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></button>
                <button class="ref-carousel-arrow next" aria-label="Next promotion"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>
                <div class="ref-carousel-dots">
                    @foreach($sideBanner1Slides as $idx => $slide)
                    <button class="{{ $loop->first ? 'is-active' : '' }}" data-slide="{{ $idx }}" aria-label="Show promotion {{ $idx + 1 }}" aria-current="{{ $loop->first ? 'true' : 'false' }}"></button>
                    @endforeach
                </div>
            </div>
            <div class="ref-side-carousel ref-carousel-instance" aria-roledescription="carousel" aria-label="Bottom side promotion">
                <div class="ref-slides">
                    @foreach($sideBanner2Slides as $idx => $slide)
                    <a href="{{ $slide['link'] ?: '/dealers' }}" class="ref-slide {{ $loop->first ? 'is-active' : '' }}" aria-hidden="{{ $loop->first ? 'false' : 'true' }}">
                        <img src="{{ ($slide['image'] ?: '/branding/madina-side-banner-2.jpg') . '?v=20261015' }}" alt="{{ $slide['alt'] ?: 'Madina Authorized Dealer Network' }}" @if($loop->first) fetchpriority="high" @else loading="lazy" @endif>
                    </a>
                    @endforeach
                </div>
                <button class="ref-carousel-arrow prev" aria-label="Previous promotion"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></button>
                <button class="ref-carousel-arrow next" aria-label="Next promotion"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>
                <div class="ref-carousel-dots">
                    @foreach($sideBanner2Slides as $idx => $slide)
                    <button class="{{ $loop->first ? 'is-active' : '' }}" data-slide="{{ $idx }}" aria-label="Show promotion {{ $idx + 1 }}" aria-current="{{ $loop->first ? 'true' : 'false' }}"></button>
                    @endforeach
                </div>
            </div>
        </div>
    </section>
    <div class="ref-announcement"><i class="fa-solid fa-bullhorn" aria-hidden="true"></i><div><span>{{ $siteCustomization['announcement_text'] ?? 'Natural food supplements, unani medicine, personal care, agriculture and wellness products are available now.' }}</span></div></div>
    @php
        $shortcutsEnabled = filter_var($siteCustomization['shortcuts_section_enabled'] ?? '1', FILTER_VALIDATE_BOOLEAN);
        $shortcutsList = \App\Support\SiteCustomization::getShortcuts();
    @endphp
    @if($shortcutsEnabled && !empty($shortcutsList))
    <section class="ref-shortcuts" aria-label="Quick links">
        @foreach($shortcutsList as $idx => $s)
        @php
            $icon = trim($s['icon'] ?? 'fa-circle');
            $isImage = (bool)preg_match('/\.(png|jpe?g|svg|webp|gif)(\?.*)?$/i', $icon)
                || str_starts_with($icon, '/')
                || str_starts_with($icon, 'http://')
                || str_starts_with($icon, 'https://');
            if (!$isImage && !str_contains($icon, 'fa-solid') && !str_contains($icon, 'fa-brands') && !str_contains($icon, 'fa-regular')) {
                if (!str_starts_with($icon, 'fa-')) {
                    $icon = 'fa-' . $icon;
                }
                $icon = 'fa-solid ' . $icon;
            }
            $title = $s['title'] ?: 'Shortcut';
        @endphp
        <a href="{{ $s['link'] ?: '#' }}">
            @if($isImage)
                <img src="{{ $icon }}" alt="{{ $title }}" style="width: 32px; height: 32px; object-fit: contain;" />
            @else
                <i class="{{ $icon }}" aria-hidden="true"></i>
            @endif
            <div>
                <h3>{{ $title }}</h3>
                @if(!empty($s['subtitle']))<p>{{ $s['subtitle'] }}</p>@endif
            </div>
        </a>
        @endforeach
    </section>
    @endif
    @php
        $campaignEnabled = filter_var($siteCustomization['campaign_section_enabled'] ?? '1', FILTER_VALIDATE_BOOLEAN);
        $campaignCards = \App\Support\SiteCustomization::getCampaignCards();
    @endphp
    @if($campaignEnabled && !empty($campaignCards))
    <section class="ref-campaign-section" aria-label="Special Offers and Campaigns">
        <div class="ref-section-title">
            <span class="ref-campaign-badge"><i class="fa-solid fa-bolt" aria-hidden="true"></i> {{ $siteCustomization['campaign_badge'] ?? 'SPECIAL CAMPAIGN & OFFERS' }}</span>
            <h2>{{ $siteCustomization['campaign_title'] ?? 'Special Discounts & Promotional Offers' }}</h2>
            <p>{{ $siteCustomization['campaign_subtitle'] ?? 'Explore limited-time wellness packages, bulk entrepreneur offers and seasonal healthcare discounts.' }}</p>
        </div>
        <div class="ref-campaign-grid">
            @foreach($campaignCards as $card)
            @php
                $cardLink = !empty($card['link']) ? $card['link'] : '/products';
                $isHighlight = filter_var($card['highlight'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $cardTitle = $card['title'] ?? 'Special Offer';
                $feat1 = $card['feat1'] ?? '';
                $feat2 = $card['feat2'] ?? '';
            @endphp
            <div class="ref-campaign-card {{ $isHighlight ? 'highlight' : '' }}">
                @if(!empty($card['image']))
                <a href="{{ $cardLink }}" class="ref-campaign-thumb-link" aria-label="{{ $cardTitle }}">
                    <div class="ref-campaign-thumb">
                        <img src="{{ $card['image'] }}" alt="{{ $cardTitle }}" loading="lazy">
                    </div>
                </a>
                @endif
                @if(!empty($card['tag']))
                <span class="ref-discount-tag"><span class="ref-pulse-dot" aria-hidden="true"></span>{{ $card['tag'] }}</span>
                @endif
                <div class="ref-campaign-body">
                    <a href="{{ $cardLink }}" class="ref-campaign-title-link">
                        <h3>{{ $cardTitle }}</h3>
                    </a>
                    @if(!empty($card['desc']))
                    <p>{{ $card['desc'] }}</p>
                    @endif
                    @if(($card['type'] ?? 'bundle') === 'bundle' && !empty($card['bundle_items']))
                    <div class="ref-bundle-preview" style="margin-bottom: 12px; padding: 8px 12px; background: #f8fafc; border-radius: 8px; border: 1px dashed #cbd5e1;">
                        <div style="font-size: 11px; font-weight: 700; color: #0284c7; text-transform: uppercase; margin-bottom: 5px; display: flex; align-items: center; gap: 5px;">
                            <i class="fa-solid fa-boxes-stacked" aria-hidden="true"></i> Package Includes ({{ count($card['bundle_items']) }} Products):
                        </div>
                        <div style="display: flex; flex-wrap: wrap; gap: 4px;">
                            @foreach($card['bundle_items'] as $bItem)
                            <span style="font-size: 11px; background: #fff; padding: 2px 7px; border-radius: 4px; border: 1px solid #e2e8f0; color: #334155;">
                                {{ $bItem['name'] ?? 'Product' }} @if(!empty($bItem['mrp']))<small style="color: #64748b;">(৳{{ number_format((float)$bItem['mrp']) }})</small>@endif
                            </span>
                            @endforeach
                        </div>
                    </div>
                    @endif
                    @if(!empty($feat1) || !empty($feat2))
                    <ul class="ref-campaign-features">
                        @if(!empty($feat1))<li><i class="fa-solid fa-circle-check" aria-hidden="true"></i> {{ $feat1 }}</li>@endif
                        @if(!empty($feat2))<li><i class="fa-solid fa-circle-check" aria-hidden="true"></i> {{ $feat2 }}</li>@endif
                    </ul>
                    @endif
                    <div class="ref-campaign-pricing">
                        @if(!empty($card['price']))<strong>{{ $card['price'] }}</strong>@endif
                        @if(!empty($card['mrp']))<del>{{ $card['mrp'] }}</del>@endif
                        @if(!empty($card['save']))<span class="ref-save-pill">{{ $card['save'] }}</span>@endif
                    </div>
                    <a href="{{ $cardLink }}" class="ref-primary-button">{{ $card['btn'] ?? 'View Package' }} <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
                </div>
            </div>
            @endforeach
        </div>
    </section>
    @endif
    @php
        $categoriesEnabled = filter_var($siteCustomization['featured_categories_enabled'] ?? '1', FILTER_VALIDATE_BOOLEAN);
        $categoriesList = \App\Support\SiteCustomization::getFeaturedCategories();
    @endphp
    @if($categoriesEnabled && !empty($categoriesList))
    <section class="ref-featured-categories" aria-label="Featured Categories">
        <div class="ref-section-title">
            <h2>{{ $siteCustomization['featured_categories_title'] ?? 'Featured Categories' }}</h2>
            <p>{{ $siteCustomization['featured_categories_subtitle'] ?? 'Get Your Desired Product from Featured Category!' }}</p>
        </div>
        <div class="ref-category-grid is-collapsed" id="refCategoryGrid">
            @foreach($categoriesList as $cat)
                @php
                    $badgeClass = !empty($cat['badge']) ? strtolower(trim($cat['badge'])) : '';
                @endphp
                <a href="{{ $cat['link'] }}">
                    @if(!empty($cat['badge']))
                        <span class="ref-cat-pill-badge {{ $badgeClass }}">{{ $cat['badge'] }}</span>
                    @endif
                    <div class="ref-cat-icon-wrap">
                        @if(!empty($cat['is_image']))
                            <img src="{{ $cat['image'] }}" alt="{{ $cat['name'] }}" loading="lazy">
                        @else
                            <i class="fa-solid {{ $cat['icon'] }}" aria-hidden="true"></i>
                        @endif
                    </div>
                    <span class="ref-cat-name">{{ $cat['name'] }}</span>
                </a>
            @endforeach
        </div>
        @if(count($categoriesList) > 8)
        <div class="ref-cat-expand-wrap">
            <button type="button" class="ref-cat-toggle-btn" id="refCatToggleBtn" aria-expanded="false">
                <span>View All Categories ({{ count($categoriesList) }})</span>
                <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
            </button>
        </div>
        <script>
        (function() {
            var btn = document.getElementById('refCatToggleBtn');
            var grid = document.getElementById('refCategoryGrid');
            if (btn && grid) {
                btn.addEventListener('click', function() {
                    var collapsed = grid.classList.toggle('is-collapsed');
                    btn.setAttribute('aria-expanded', !collapsed);
                    if (collapsed) {
                        btn.innerHTML = '<span>View All Categories ({{ count($categoriesList) }})</span> <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>';
                    } else {
                        btn.innerHTML = '<span>Show Fewer Categories</span> <i class="fa-solid fa-chevron-up" aria-hidden="true"></i>';
                    }
                });
            }
        })();
        </script>
        @endif
    </section>
    @endif
    <section class="ref-dealer-network-banner" aria-label="Authorized Dealer Network">
        <div class="ref-dealer-banner-inner">
            <div class="ref-dealer-banner-info">
                <div class="ref-dealer-banner-icon" aria-hidden="true">
                    <i class="fa-solid fa-map-location-dot"></i>
                </div>
                <div>
                    <h3>{{ $siteCustomization['dealer_banner_title'] ?? 'Authorized Dealer Network' }}</h3>
                    <p>{{ $siteCustomization['dealer_banner_subtitle'] ?? 'Find your nearest authorized dealer or sub-dealer pharmacy with GPS distance calculation!' }}</p>
                </div>
            </div>
            <a href="/dealers" class="ref-dealer-banner-btn">
                <span>{{ $siteCustomization['dealer_banner_btn_text'] ?? 'Find Nearest Dealer' }}</span>
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
            </a>
        </div>
    </section>
    @php
        $featuredSectionEnabled = filter_var($siteCustomization['featured_products_enabled'] ?? '1', FILTER_VALIDATE_BOOLEAN);
        $tabsEnabled = filter_var($siteCustomization['featured_products_tabs_enabled'] ?? '1', FILTER_VALIDATE_BOOLEAN);
        $viewAllLink = !empty($siteCustomization['featured_products_view_all_link']) ? $siteCustomization['featured_products_view_all_link'] : '/products';
    @endphp
    @if($featuredSectionEnabled)
    <section id="featured-products" class="ref-featured-products" aria-label="Featured Products">
        <div class="ref-section-title ref-section-title-flex">
            <div>
                @if(!empty($siteCustomization['featured_products_badge']))
                    <span class="ref-featured-badge"><i class="fa-solid fa-star" aria-hidden="true"></i> {{ $siteCustomization['featured_products_badge'] }}</span>
                @endif
                <h2>{{ $siteCustomization['featured_products_title'] ?? 'Featured Products' }}</h2>
                <p>{{ $siteCustomization['featured_products_subtitle'] ?? 'Check & Get Your Desired Product!' }}</p>
            </div>
            <a href="{{ $viewAllLink }}" class="ref-view-all-link">{{ $siteCustomization['featured_products_view_all'] ?? 'View All Products' }} <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
        </div>

        @if($tabsEnabled && !empty($featuredCategoryTabs) && count($featuredCategoryTabs) > 1)
        <div class="ref-featured-tabs" role="tablist" aria-label="Product category filters">
            @foreach($featuredCategoryTabs as $tab)
            <button type="button" class="ref-tab-btn {{ $loop->first ? 'is-active' : '' }}" role="tab" aria-selected="{{ $loop->first ? 'true' : 'false' }}" data-category-filter="{{ $tab['slug'] }}">
                <span>{{ $tab['name'] }}</span>
                <span class="ref-tab-count">{{ $tab['count'] }}</span>
            </button>
            @endforeach
        </div>
        @endif

        <div class="ref-product-grid" id="refProductGrid">
            @forelse($featuredProducts as $item)
                @include('reference.product-card', compact('item'))
            @empty
                <div class="ref-empty" style="grid-column: 1 / -1;">
                    <h2>{{ $siteCustomization['products_empty_title'] ?? 'Our collection is being prepared.' }}</h2>
                    <a href="/contact" class="ref-primary-button">Contact Us</a>
                </div>
            @endforelse
        </div>

        <div class="ref-tab-empty" id="refTabEmpty" style="display: none;">
            <i class="fa-solid fa-box-open" style="font-size: 36px; color: #94a3b8; margin-bottom: 12px;"></i>
            <p style="color: #64748b; font-size: 14px; margin: 0;">No products found in this category.</p>
        </div>
    </section>
    @endif
    @php($homepageVideo = \App\Support\ProductVideo::homepage())
    @if($homepageVideo)
    <section class="ref-product-video ref-home-video" aria-labelledby="homepage-video-title">
        <h2 id="homepage-video-title">{{ $homepageVideo['title'] }}</h2>
        @if(!empty($homepageVideo['description']))<p>{{ $homepageVideo['description'] }}</p>@endif
        @if($homepageVideo['media']['video_embed_url'])
            <iframe src="{{ $homepageVideo['media']['video_embed_url'] }}" title="{{ $homepageVideo['product_name'] }} promotional video" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allow="encrypted-media; picture-in-picture; fullscreen" allowfullscreen></iframe>
        @else
            <video controls playsinline preload="metadata" @if($homepageVideo['media']['image_url']) poster="{{ $homepageVideo['media']['image_url'] }}" @endif><source src="{{ $homepageVideo['media']['video_url'] }}">Your browser does not support video playback.</video>
        @endif
    </section>
    @endif
    <section class="ref-home-about">
        <h1>{{ $siteCustomization['about_headline'] ?? 'Natural Food, Medicine & Wellness Product Shop in Bangladesh' }}</h1>
        <p>{{ $siteCustomization['about_lead'] ?? 'Madina Natural Health Care connects products, dealers and communities across Bangladesh. Explore the collection and contact the company for product information and partnership enquiries.' }}</p>
        <details>
            <summary>{{ $siteCustomization['about_summary_title'] ?? 'Read More' }} <i class="fa-solid fa-chevron-down" aria-hidden="true"></i></summary>
            <p>{{ $siteCustomization['about_summary_body'] ?? 'Our partner workspace brings product requests, deliveries, verified manual payments and company support together. Dealer accounts are created by authorized company staff.' }}</p>
        </details>
    </section>
</main>
@endsection
