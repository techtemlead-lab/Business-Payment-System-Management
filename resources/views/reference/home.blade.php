@php
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
            ['image' => $siteCustomization['side_banner_1_image'] ?? '/branding/madina-side-banner-1.jpg', 'link' => $siteCustomization['side_banner_1_link'] ?? '/products', 'alt' => \App\Support\SiteChrome::text('side_banner_1_alt', $siteCustomization['side_banner_1_alt'] ?? null)],
            ['image' => '/branding/madina-top-skincare.jpg', 'link' => '/products', 'alt' => 'Madina Pure Herbal Skincare & Hair Vitality'],
            ['image' => '/branding/madina-top-immunity.jpg', 'link' => '/products', 'alt' => 'Madina Daily Immunity & Vitality Boosters'],
        ];
    }

    $sideBanner2Slides = $siteCustomization['side_banner_2_slides'] ?? [];
    if (empty($sideBanner2Slides)) {
        $sideBanner2Slides = [
            ['image' => $siteCustomization['side_banner_2_image'] ?? '/branding/madina-side-banner-2.jpg', 'link' => $siteCustomization['side_banner_2_link'] ?? '/dealers', 'alt' => \App\Support\SiteChrome::text('side_banner_2_alt', $siteCustomization['side_banner_2_alt'] ?? null)],
            ['image' => '/branding/madina-promotion-popup.jpg', 'link' => '/about', 'alt' => 'Madina Natural Health Care & Physiotherapy Center'],
            ['image' => '/branding/madina-bottom-pharmacy.jpg', 'link' => '/dealers', 'alt' => 'Madina Authorized Partner Pharmacy Outlets Across All 64 Districts'],
        ];
    }

    $shortcuts = $siteCustomization['shortcuts'] ?? [];
    if (empty($shortcuts)) {
        $shortcuts = [
            ['title'=>'Our Products','subtitle'=>'browse wellness items','icon'=>'fa-seedling','link'=>'/products'],
            ['title'=>'Share Feedback','subtitle'=>'Share your experience','icon'=>'fa-comments','link'=>'/contact?topic=complaint'],
            ['title'=>'Our Services','subtitle'=>'Natural Health Care and Physiotherapy Center','icon'=>'fa-hand-holding-heart','link'=>'/about'],
            ['title'=>'Contact Us','subtitle'=>'Support & Inquiries','icon'=>'fa-headset','link'=>'/contact'],
            ['title'=>'Our Network','subtitle'=>'Dealer Directory','icon'=>'fa-diagram-project','link'=>'/dealers']
        ];
    }
@endphp

@extends('reference.layout')
@section('title', __('site.home_document_title'))
@section('content')
@php
    $chrome = fn (string $key, ?string $custom = null): string => \App\Support\SiteChrome::text($key, $custom);
@endphp
<main id="main" class="ref-container ref-main">
    <section class="ref-company-intro" aria-labelledby="company-intro-title">
        <div>
            <span class="ref-company-eyebrow">{{ $chrome('company_intro_eyebrow') }}</span>
            <h1 id="company-intro-title">{{ $chrome('company_intro_title', $siteCustomization['company_intro_title'] ?? null) }}</h1>
            <p>{{ $chrome('company_intro_body', $siteCustomization['company_intro_body'] ?? null) }}</p>
            <div class="ref-company-intro-actions">
                @if($publicPageEnabled['about'] ?? true)<a href="/about" class="ref-primary-button">{{ $chrome('about_our_company') }} <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>@endif
                @if($publicPageEnabled['dealers'] ?? true)<a href="/dealers" class="ref-secondary-button"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> {{ $chrome('find_a_dealer') }}</a>@elseif($publicPageEnabled['partnership'] ?? true)<a href="/partnership" class="ref-secondary-button"><i class="fa-solid fa-handshake" aria-hidden="true"></i> {{ $chrome('become_a_partner') }}</a>@endif
            </div>
        </div>
        <div class="ref-company-paths">
            @if($publicPageEnabled['products'] ?? true)
            <a href="/products"><i class="fa-solid fa-leaf" aria-hidden="true"></i><span><strong>{{ $chrome('product_range_title') }}</strong><small>{{ $chrome('product_range_sub') }}</small></span><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i></a>
            @elseif($publicPageEnabled['services'] ?? true)
            <a href="/services"><i class="fa-solid fa-hand-holding-heart" aria-hidden="true"></i><span><strong>{{ $chrome('our_services_title') }}</strong><small>{{ $chrome('our_services_sub') }}</small></span><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i></a>
            @endif
            @if($publicPageEnabled['partnership'] ?? true)
            <a href="/partnership"><i class="fa-solid fa-handshake" aria-hidden="true"></i><span><strong>{{ $chrome('partner_with_us_title') }}</strong><small>{{ $chrome('partner_with_us_sub') }}</small></span><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i></a>
            @elseif($publicPageEnabled['contact'] ?? true)
            <a href="/contact#vendor"><i class="fa-solid fa-handshake" aria-hidden="true"></i><span><strong>{{ $chrome('partner_with_us_title') }}</strong><small>{{ $chrome('partner_with_us_sub') }}</small></span><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i></a>
            @endif
        </div>
    </section>
    <section class="ref-promotions" aria-label="{{ __('site.promotional_banners') }}">
        <div class="ref-carousel ref-carousel-instance" aria-roledescription="carousel" aria-label="{{ __('site.featured_promotions') }}">
            <div class="ref-slides">
                @foreach($slides as $idx => $slide)
                @php $slideHref = \App\Support\SiteCustomization::publicHref($slide['link'] ?: '/products') ?? '#'; @endphp
                <a href="{{ $slideHref }}" class="ref-slide {{ $loop->first ? 'is-active' : '' }}" aria-hidden="{{ $loop->first ? 'false' : 'true' }}" @if($slideHref === '#') tabindex="-1" aria-disabled="true" @endif>
                    <img src="{{ ($slide['image'] ?: '/branding/madina-hero-banner-1.jpg') . '?v=20261015' }}" alt="{{ $slide['alt'] ?: ('Promotion ' . ($idx + 1)) }}" @if($loop->first) fetchpriority="high" @else loading="lazy" @endif>
                </a>
                @endforeach
            </div>
            <button class="ref-carousel-arrow prev" aria-label="{{ __('site.previous_promotion') }}"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></button>
            <button class="ref-carousel-arrow next" aria-label="{{ __('site.next_promotion') }}"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>
            <div class="ref-carousel-dots">
                @foreach($slides as $idx => $slide)
                <button class="{{ $loop->first ? 'is-active' : '' }}" data-slide="{{ $idx }}" aria-label="{{ __('site.show_promotion', ['number' => $idx + 1]) }}" aria-current="{{ $loop->first ? 'true' : 'false' }}"></button>
                @endforeach
            </div>
        </div>
        <div class="ref-side-banners">
            <div class="ref-side-carousel ref-carousel-instance" aria-roledescription="carousel" aria-label="{{ __('site.top_side_promotion') }}">
                <div class="ref-slides">
                    @foreach($sideBanner1Slides as $idx => $slide)
                    @php $slideHref = \App\Support\SiteCustomization::publicHref($slide['link'] ?: '/products') ?? '#'; @endphp
                    <a href="{{ $slideHref }}" class="ref-slide {{ $loop->first ? 'is-active' : '' }}" aria-hidden="{{ $loop->first ? 'false' : 'true' }}" @if($slideHref === '#') tabindex="-1" aria-disabled="true" @endif>
                        <img src="{{ ($slide['image'] ?: '/branding/madina-side-banner-1.jpg') . '?v=20261015' }}" alt="{{ $slide['alt'] ?: 'Madina Premium Natural Care' }}" @if($loop->first) fetchpriority="high" @else loading="lazy" @endif>
                    </a>
                    @endforeach
                </div>
                <button class="ref-carousel-arrow prev" aria-label="{{ __('site.previous_promotion') }}"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></button>
                <button class="ref-carousel-arrow next" aria-label="{{ __('site.next_promotion') }}"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>
                <div class="ref-carousel-dots">
                    @foreach($sideBanner1Slides as $idx => $slide)
                    <button class="{{ $loop->first ? 'is-active' : '' }}" data-slide="{{ $idx }}" aria-label="{{ __('site.show_promotion', ['number' => $idx + 1]) }}" aria-current="{{ $loop->first ? 'true' : 'false' }}"></button>
                    @endforeach
                </div>
            </div>
            <div class="ref-side-carousel ref-carousel-instance" aria-roledescription="carousel" aria-label="{{ __('site.bottom_side_promotion') }}">
                <div class="ref-slides">
                    @foreach($sideBanner2Slides as $idx => $slide)
                    @php $slideHref = \App\Support\SiteCustomization::publicHref($slide['link'] ?: '/dealers') ?? '#'; @endphp
                    <a href="{{ $slideHref }}" class="ref-slide {{ $loop->first ? 'is-active' : '' }}" aria-hidden="{{ $loop->first ? 'false' : 'true' }}" @if($slideHref === '#') tabindex="-1" aria-disabled="true" @endif>
                        <img src="{{ ($slide['image'] ?: '/branding/madina-side-banner-2.jpg') . '?v=20261015' }}" alt="{{ $slide['alt'] ?: 'Madina Authorized Dealer Network' }}" @if($loop->first) fetchpriority="high" @else loading="lazy" @endif>
                    </a>
                    @endforeach
                </div>
                <button class="ref-carousel-arrow prev" aria-label="{{ __('site.previous_promotion') }}"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></button>
                <button class="ref-carousel-arrow next" aria-label="{{ __('site.next_promotion') }}"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>
                <div class="ref-carousel-dots">
                    @foreach($sideBanner2Slides as $idx => $slide)
                    <button class="{{ $loop->first ? 'is-active' : '' }}" data-slide="{{ $idx }}" aria-label="{{ __('site.show_promotion', ['number' => $idx + 1]) }}" aria-current="{{ $loop->first ? 'true' : 'false' }}"></button>
                    @endforeach
                </div>
            </div>
        </div>
    </section>
    <div class="ref-announcement"><i class="fa-solid fa-bullhorn" aria-hidden="true"></i><div><span>{{ $chrome('announcement_text', $siteCustomization['announcement_text'] ?? null) }}</span></div></div>
    @php
        $shortcutsEnabled = filter_var($siteCustomization['shortcuts_section_enabled'] ?? '1', FILTER_VALIDATE_BOOLEAN);
        $shortcutsList = \App\Support\SiteCustomization::getShortcuts();
    @endphp
    @if($shortcutsEnabled && !empty($shortcutsList))
    <section class="ref-shortcuts" aria-label="{{ __('site.quick_links') }}">
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
            $n = $idx + 1;
            $title = $chrome('shortcut'.$n.'_title', filled($s['title'] ?? null) ? $s['title'] : null);
            $subtitle = filled($s['subtitle'] ?? null) ? $chrome('shortcut'.$n.'_subtitle', $s['subtitle']) : '';
        @endphp
        <a href="{{ $s['link'] ?: '#' }}">
            @if($isImage)
                <img src="{{ $icon }}" alt="{{ $title }}" style="width: 32px; height: 32px; object-fit: contain;" />
            @else
                <i class="{{ $icon }}" aria-hidden="true"></i>
            @endif
            <div>
                <h3>{{ $title }}</h3>
                @if($subtitle !== '')<p>{{ $subtitle }}</p>@endif
            </div>
        </a>
        @endforeach
    </section>
    @endif

    {{-- Core Brand Promises & Trust Pillars Strip (Admin-controlled toggle) --}}
    @php
        $trustPillars = \App\Support\SiteCustomization::getTrustPillars();
    @endphp
    @if(!empty($trustPillars))
    <section class="ref-trust-pillars" aria-label="{{ $siteCustomization['trust_pillars_title'] ?? __('site.trust_pillars_title') }}">
        <div class="ref-trust-pillars-inner">
            @foreach($trustPillars as $pillar)
            <div class="ref-trust-pillar-card">
                <div class="ref-trust-pillar-icon" aria-hidden="true">
                    <i class="fa-solid {{ $pillar['icon'] ?? 'fa-leaf' }}"></i>
                </div>
                <div class="ref-trust-pillar-content">
                    <h3 class="ref-trust-pillar-title">{{ $pillar['title'] }}</h3>
                    <p class="ref-trust-pillar-subtitle">{{ $pillar['subtitle'] }}</p>
                </div>
            </div>
            @endforeach
        </div>
    </section>
    @endif
    @php
        $categoriesEnabled = ($publicPageEnabled['products'] ?? true) && filter_var($siteCustomization['featured_categories_enabled'] ?? '1', FILTER_VALIDATE_BOOLEAN);
        $categoriesList = \App\Support\SiteCustomization::getFeaturedCategories();
    @endphp
    @if($categoriesEnabled && !empty($categoriesList))
    <section class="ref-featured-categories" aria-label="{{ $chrome('featured_categories_title') }}">
        <div class="ref-section-title">
            <h2>{{ $chrome('featured_categories_title', $siteCustomization['featured_categories_title'] ?? null) }}</h2>
            <p>{{ $chrome('featured_categories_subtitle', $siteCustomization['featured_categories_subtitle'] ?? null) }}</p>
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
                <span>{{ __('site.view_all_categories', ['count' => count($categoriesList)]) }}</span>
                <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
            </button>
        </div>
        <script>
        (function() {
            var btn = document.getElementById('refCatToggleBtn');
            var grid = document.getElementById('refCategoryGrid');
            var moreLabel = @json(__('site.view_all_categories', ['count' => count($categoriesList)]));
            var fewerLabel = @json(__('site.show_fewer_categories'));
            if (btn && grid) {
                btn.addEventListener('click', function() {
                    var collapsed = grid.classList.toggle('is-collapsed');
                    btn.setAttribute('aria-expanded', !collapsed);
                    if (collapsed) {
                        btn.innerHTML = '<span>' + moreLabel + '</span> <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>';
                    } else {
                        btn.innerHTML = '<span>' + fewerLabel + '</span> <i class="fa-solid fa-chevron-up" aria-hidden="true"></i>';
                    }
                });
            }
        })();
        </script>
        @endif
    </section>
    @endif
    @if($publicPageEnabled['dealers'] ?? true)
    <section class="ref-dealer-network-banner" aria-label="{{ $chrome('dealer_banner_title') }}">
        <div class="ref-dealer-banner-inner">
            <div class="ref-dealer-banner-info">
                <div class="ref-dealer-banner-icon" aria-hidden="true">
                    <i class="fa-solid fa-map-location-dot"></i>
                </div>
                <div>
                    <h3>{{ $chrome('dealer_banner_title', $siteCustomization['dealer_banner_title'] ?? null) }}</h3>
                    <p>{{ $chrome('dealer_banner_subtitle', $siteCustomization['dealer_banner_subtitle'] ?? null) }}</p>
                </div>
            </div>
            <a href="/dealers" class="ref-dealer-banner-btn">
                <span>{{ $chrome('find_nearest_dealer', $siteCustomization['dealer_banner_btn_text'] ?? null) }}</span>
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
            </a>
        </div>
    </section>
    @endif
    @php
        $featuredSectionEnabled = ($publicPageEnabled['products'] ?? true) && filter_var($siteCustomization['featured_products_enabled'] ?? '1', FILTER_VALIDATE_BOOLEAN);
        $tabsEnabled = filter_var($siteCustomization['featured_products_tabs_enabled'] ?? '1', FILTER_VALIDATE_BOOLEAN);
        $viewAllLink = \App\Support\SiteCustomization::publicHref(!empty($siteCustomization['featured_products_view_all_link']) ? $siteCustomization['featured_products_view_all_link'] : '/products') ?? '/';
    @endphp
    @if($featuredSectionEnabled)
    <section id="featured-products" class="ref-featured-products" aria-label="{{ $chrome('featured_products_title') }}">
        <div class="ref-section-title ref-section-title-flex">
            <div>
                @if(!empty($siteCustomization['featured_products_badge']))
                    <span class="ref-featured-badge"><i class="fa-solid fa-star" aria-hidden="true"></i> {{ $chrome('featured_products_badge', $siteCustomization['featured_products_badge'] ?? null) }}</span>
                @endif
                <h2>{{ $chrome('featured_products_title', $siteCustomization['featured_products_title'] ?? null) }}</h2>
                <p>{{ $chrome('featured_products_subtitle', $siteCustomization['public_products_subtitle'] ?? null) }}</p>
            </div>
            <a href="{{ $viewAllLink }}" class="ref-view-all-link">{{ $chrome('view_all_products', $siteCustomization['featured_products_view_all'] ?? null) }} <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
        </div>

        @if($tabsEnabled && !empty($featuredCategoryTabs) && count($featuredCategoryTabs) > 1)
        <div class="ref-featured-tabs" role="tablist" aria-label="{{ __('site.product_category_filters') }}">
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
                    <h2>{{ $chrome('products_empty_title', $siteCustomization['products_empty_title'] ?? null) }}</h2>
                    @if($publicPageEnabled['contact'] ?? true)<a href="/contact" class="ref-primary-button">{{ $chrome('contact_us') }}</a>@endif
                </div>
            @endforelse
        </div>

        <div class="ref-tab-empty" id="refTabEmpty" style="display: none;">
            <i class="fa-solid fa-box-open" style="font-size: 36px; color: #94a3b8; margin-bottom: 12px;"></i>
            <p style="color: #64748b; font-size: 14px; margin: 0;">{{ __('site.no_products_in_category') }}</p>
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

    {{-- Brand Video Gallery / Product Reels Showcase (Admin-controlled toggle) --}}
    @php
        $videoGallery = \App\Support\SiteCustomization::getVideoGallery();
    @endphp
    @if(!empty($videoGallery))
    <section class="ref-video-gallery-section" aria-labelledby="video-gallery-title">
        <div class="ref-section-title">
            <h2 id="video-gallery-title">{{ $siteCustomization['video_gallery_title'] ?? __('site.video_gallery_title') }}</h2>
            <p>{{ $siteCustomization['video_gallery_subtitle'] ?? __('site.video_gallery_subtitle') }}</p>
        </div>
        <div class="ref-video-gallery-grid">
            @foreach($videoGallery as $idx => $v)
            <article class="ref-video-card">
                <div class="ref-video-frame">
                    @if(!empty($v['video_embed_url']))
                        <iframe src="{{ $v['video_embed_url'] }}" title="{{ $v['title'] }}" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allow="encrypted-media; picture-in-picture; fullscreen" allowfullscreen></iframe>
                    @elseif(!empty($v['video_url']))
                        <video controls playsinline preload="metadata" @if(!empty($v['thumbnail'])) poster="{{ $v['thumbnail'] }}" @endif>
                            <source src="{{ $v['video_url'] }}">
                            {{ __('site.product_video_unsupported') }}
                        </video>
                    @elseif(!empty($v['thumbnail']))
                        <div class="ref-video-poster" style="background-image: url('{{ $v['thumbnail'] }}');">
                            <span class="ref-video-play-badge"><i class="fa-solid fa-play"></i></span>
                        </div>
                    @else
                        <div class="ref-video-poster placeholder">
                            <i class="fa-solid fa-film"></i>
                        </div>
                    @endif
                </div>
                <div class="ref-video-card-body">
                    <h3>{{ $v['title'] }}</h3>
                    @if(!empty($v['subtitle']))
                        <p>{{ $v['subtitle'] }}</p>
                    @endif
                </div>
            </article>
            @endforeach
        </div>
    </section>
    @endif

    {{-- Customer & Partner Testimonials Section (Admin-controlled toggle) --}}
    @php
        $testimonials = \App\Support\SiteCustomization::getTestimonials();
    @endphp
    @if(!empty($testimonials))
    <section class="ref-testimonials-section" aria-labelledby="testimonials-title">
        <div class="ref-section-title">
            <h2 id="testimonials-title">{{ $siteCustomization['testimonials_title'] ?? __('site.testimonials_title') }}</h2>
            <p>{{ $siteCustomization['testimonials_subtitle'] ?? __('site.testimonials_subtitle') }}</p>
        </div>
        <div class="ref-testimonials-grid">
            @foreach($testimonials as $t)
            <article class="ref-testimonial-card">
                <div class="ref-testimonial-header">
                    <div class="ref-testimonial-avatar">
                        @if(!empty($t['avatar']))
                            <img src="{{ $t['avatar'] }}" alt="{{ $t['name'] }}" loading="lazy">
                        @else
                            <div class="ref-avatar-initial">{{ mb_substr($t['name'], 0, 1) }}</div>
                        @endif
                    </div>
                    <div>
                        <h3 class="ref-testimonial-author">{{ $t['name'] }}</h3>
                        <span class="ref-testimonial-meta">{{ $t['role'] }} · {{ $t['location'] }}</span>
                    </div>
                </div>
                <div class="ref-testimonial-stars" aria-label="{{ $t['rating'] }} out of 5 stars">
                    @for($i = 1; $i <= 5; $i++)
                        <i class="fa-solid fa-star {{ $i <= $t['rating'] ? 'is-filled' : 'is-empty' }}" aria-hidden="true"></i>
                    @endfor
                </div>
                <blockquote class="ref-testimonial-quote">
                    “{{ $t['text'] }}”
                </blockquote>
            </article>
            @endforeach
        </div>
    </section>
    @endif

</main>
@endsection
