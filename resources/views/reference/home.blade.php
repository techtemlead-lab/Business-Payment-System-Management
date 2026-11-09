@php
    // Banner pictures come from the website editor; its factory defaults fill any gap.
    $siteDefaults = \App\Support\SiteCustomization::defaults();
    $slides = ($siteCustomization['slides'] ?? []) ?: $siteDefaults['slides'];
    $sideBanner1Slides = ($siteCustomization['side_banner_1_slides'] ?? []) ?: $siteDefaults['side_banner_1_slides'];
    $sideBanner2Slides = ($siteCustomization['side_banner_2_slides'] ?? []) ?: $siteDefaults['side_banner_2_slides'];
@endphp

@extends('reference.layout')
@section('title', \App\Support\SiteCustomization::brandName().' · '.__('site.home_title_tagline'))
@section('content')
@php
    $chrome = fn (string $key, ?string $custom = null): string => \App\Support\SiteChrome::text($key, $custom);
    // Picture descriptions: the factory English text has a Bangla twin; extra slides the owner adds keep their own text.
    $slideAlt = fn (string $key, ?string $custom, string $fallback): string => \Illuminate\Support\Facades\Lang::has('site.'.$key)
        ? $chrome($key, $custom)
        : (filled($custom) ? (string) $custom : $fallback);
@endphp
<main id="main" class="ref-container ref-main">
    @include('reference.partials.md-message', ['placement' => 'home'])
    <section class="ref-promotions" aria-label="{{ __('site.promotional_banners') }}">
        <div class="ref-carousel ref-carousel-instance" aria-roledescription="carousel" aria-label="{{ __('site.featured_promotions') }}">
            <div class="ref-slides">
                @foreach($slides as $idx => $slide)
                @php $slideHref = \App\Support\SiteCustomization::publicHref($slide['link'] ?: '/products') ?? '#'; @endphp
                <a href="{{ $slideHref }}" class="ref-slide {{ $loop->first ? 'is-active' : '' }}" aria-hidden="{{ $loop->first ? 'false' : 'true' }}" @if($slideHref === '#') tabindex="-1" aria-disabled="true" @endif>
                    <img src="{{ ($slide['image'] ?: '/branding/madina-hero-banner-1.jpg') . '?v=20261015' }}" alt="{{ $slideAlt('hero_slide'.($idx + 1).'_alt', $slide['alt'] ?? null, 'Promotion ' . ($idx + 1)) }}" @if($loop->first) fetchpriority="high" @else loading="lazy" @endif>
                </a>
                @endforeach
            </div>
            <button class="ref-carousel-arrow prev" aria-label="{{ __('site.previous_promotion') }}"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></button>
            <button class="ref-carousel-arrow next" aria-label="{{ __('site.next_promotion') }}"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>
            <div class="ref-carousel-dots">
                @foreach($slides as $idx => $slide)
                <button class="{{ $loop->first ? 'is-active' : '' }}" data-slide="{{ $idx }}" aria-label="{{ __('site.show_promotion', ['number' => \App\Support\LocalDigits::text($idx + 1)]) }}" aria-current="{{ $loop->first ? 'true' : 'false' }}"></button>
                @endforeach
            </div>
        </div>
        <div class="ref-side-banners">
            <div class="ref-side-carousel ref-carousel-instance" aria-roledescription="carousel" aria-label="{{ __('site.top_side_promotion') }}">
                <div class="ref-slides">
                    @foreach($sideBanner1Slides as $idx => $slide)
                    @php $slideHref = \App\Support\SiteCustomization::publicHref($slide['link'] ?: '/products') ?? '#'; @endphp
                    <a href="{{ $slideHref }}" class="ref-slide {{ $loop->first ? 'is-active' : '' }}" aria-hidden="{{ $loop->first ? 'false' : 'true' }}" @if($slideHref === '#') tabindex="-1" aria-disabled="true" @endif>
                        <img src="{{ ($slide['image'] ?: '/branding/madina-side-banner-1.jpg') . '?v=20261015' }}" alt="{{ $slideAlt('side_banner_1_slide'.($idx + 1).'_alt', $slide['alt'] ?? null, 'Madina Premium Natural Care') }}" @if($loop->first) fetchpriority="high" @else loading="lazy" @endif>
                    </a>
                    @endforeach
                </div>
                <button class="ref-carousel-arrow prev" aria-label="{{ __('site.previous_promotion') }}"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></button>
                <button class="ref-carousel-arrow next" aria-label="{{ __('site.next_promotion') }}"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>
                <div class="ref-carousel-dots">
                    @foreach($sideBanner1Slides as $idx => $slide)
                    <button class="{{ $loop->first ? 'is-active' : '' }}" data-slide="{{ $idx }}" aria-label="{{ __('site.show_promotion', ['number' => \App\Support\LocalDigits::text($idx + 1)]) }}" aria-current="{{ $loop->first ? 'true' : 'false' }}"></button>
                    @endforeach
                </div>
            </div>
            <div class="ref-side-carousel ref-carousel-instance" aria-roledescription="carousel" aria-label="{{ __('site.bottom_side_promotion') }}">
                <div class="ref-slides">
                    @foreach($sideBanner2Slides as $idx => $slide)
                    @php $slideHref = \App\Support\SiteCustomization::publicHref($slide['link'] ?: '/dealers') ?? '#'; @endphp
                    <a href="{{ $slideHref }}" class="ref-slide {{ $loop->first ? 'is-active' : '' }}" aria-hidden="{{ $loop->first ? 'false' : 'true' }}" @if($slideHref === '#') tabindex="-1" aria-disabled="true" @endif>
                        <img src="{{ ($slide['image'] ?: '/branding/madina-side-banner-2.jpg') . '?v=20261015' }}" alt="{{ $slideAlt('side_banner_2_slide'.($idx + 1).'_alt', $slide['alt'] ?? null, 'Madina Authorized Dealer Network') }}" @if($loop->first) fetchpriority="high" @else loading="lazy" @endif>
                    </a>
                    @endforeach
                </div>
                <button class="ref-carousel-arrow prev" aria-label="{{ __('site.previous_promotion') }}"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></button>
                <button class="ref-carousel-arrow next" aria-label="{{ __('site.next_promotion') }}"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>
                <div class="ref-carousel-dots">
                    @foreach($sideBanner2Slides as $idx => $slide)
                    <button class="{{ $loop->first ? 'is-active' : '' }}" data-slide="{{ $idx }}" aria-label="{{ __('site.show_promotion', ['number' => \App\Support\LocalDigits::text($idx + 1)]) }}" aria-current="{{ $loop->first ? 'true' : 'false' }}"></button>
                    @endforeach
                </div>
            </div>
        </div>
    </section>
    <div class="ref-announcement"><i class="fa-solid fa-bullhorn" aria-hidden="true"></i><div><span>{{ $chrome('announcement_text', $siteCustomization['announcement_text'] ?? null) }}</span></div></div>
    @if($publicPageEnabled['dealers'] ?? true)
    <section class="ref-dealer-network-banner" aria-label="{{ $chrome('dealer_banner_title') }}">
        <div class="ref-dealer-banner-inner">
            <div class="ref-dealer-banner-info">
                <div class="ref-dealer-banner-icon" aria-hidden="true">
                    <svg class="ref-dealer-banner-glyph" viewBox="0 0 24 24" width="28" height="28" focusable="false">
                        <path fill="currentColor" d="M12 2.5c-3.7 0-6.7 2.9-6.7 6.6 0 4.4 5.2 10.5 6.3 11.7a.6.6 0 0 0 .9 0c1.1-1.2 6.2-7.3 6.2-11.7 0-3.7-3-6.6-6.7-6.6Zm0 9.1a2.5 2.5 0 1 1 0-5 2.5 2.5 0 0 1 0 5Z"/>
                    </svg>
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
        $viewAllLink = \App\Support\SiteCustomization::publicHref($siteCustomization['featured_products_view_all_link'] ?? '/products');
    @endphp
    @if($featuredSectionEnabled && !empty($featuredProducts))
    <section id="featured-products" class="ref-featured-products" aria-label="{{ $chrome('featured_products_title') }}">
        <div class="ref-section-title ref-section-title-flex">
            <div>
                @if(!empty($siteCustomization['featured_products_badge']))
                    <span class="ref-featured-badge"><i class="fa-solid fa-star" aria-hidden="true"></i> {{ $chrome('featured_products_badge', $siteCustomization['featured_products_badge'] ?? null) }}</span>
                @endif
                <h2>{{ $chrome('featured_products_title', $siteCustomization['featured_products_title'] ?? null) }}</h2>
                <p>{{ $chrome('featured_products_subtitle', $siteCustomization['public_products_subtitle'] ?? null) }}</p>
            </div>
            @if($viewAllLink)
            <a href="{{ $viewAllLink }}" class="ref-view-all-link">{{ $chrome('view_all_products', $siteCustomization['featured_products_view_all'] ?? null) }} <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
            @endif
        </div>

        @if($tabsEnabled && !empty($featuredCategoryTabs) && count($featuredCategoryTabs) > 1)
        <div class="ref-featured-tabs" role="tablist" aria-label="{{ __('site.product_category_filters') }}">
            @foreach($featuredCategoryTabs as $tab)
            <button type="button" class="ref-tab-btn {{ $loop->first ? 'is-active' : '' }}" role="tab" aria-selected="{{ $loop->first ? 'true' : 'false' }}" data-category-filter="{{ $tab['slug'] }}">
                <span>{{ $tab['slug'] === 'all' ? $chrome('all_products', $siteCustomization['catalog_all_products_label'] ?? null) : $tab['name'] }}</span>
                <span class="ref-tab-count">{{ $tab['count'] }}</span>
            </button>
            @endforeach
        </div>
        @endif

        <div class="ref-product-grid" id="refProductGrid">
            @foreach($featuredProducts as $item)
                @include('reference.product-card', compact('item'))
            @endforeach
        </div>

        <div class="ref-tab-empty" id="refTabEmpty" style="display: none;">
            <i class="fa-solid fa-box-open" style="font-size: 36px; color: #94a3b8; margin-bottom: 12px;"></i>
            <p class="ref-empty-note">{{ __('site.no_products_in_category') }}</p>
        </div>
    </section>
    @endif
    @if(!empty($homepageVideo))
    <section class="ref-product-video ref-home-video ref-deferred-paint" aria-labelledby="homepage-video-title">
        <h2 id="homepage-video-title">{{ $homepageVideo['title'] }}</h2>
        @if(!empty($homepageVideo['description']))
            <p>{{ $homepageVideo['description'] }}</p>
        @endif
        @if(!empty($homepageVideo['media']['video_embed_url']))
            <iframe src="{{ $homepageVideo['media']['video_embed_url'] }}" title="{{ $homepageVideo['product_name'] }} promotional video" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allow="encrypted-media; picture-in-picture; fullscreen" allowfullscreen></iframe>
        @else
            <video
                controls
                playsinline
                preload="metadata"
                @isset($homepageVideo['media']['image_url'])
                    poster="{{ $homepageVideo['media']['image_url'] }}"
                @endisset
            >
                <source src="{{ $homepageVideo['media']['video_url'] }}">
                {{ __('site.product_video_unsupported') }}
            </video>
        @endif
    </section>
    @endif

    {{-- Brand Video Gallery / Product Reels Showcase (Admin-controlled toggle) --}}
    @php
        $videoGallery = \App\Support\SiteCustomization::getVideoGallery();
    @endphp
    @if(!empty($videoGallery))
    <section class="ref-video-gallery-section ref-deferred-paint" aria-labelledby="video-gallery-title">
        <div class="ref-section-title">
            <h2 id="video-gallery-title">{{ $chrome('video_gallery_title', $siteCustomization['video_gallery_title'] ?? null) }}</h2>
            <p>{{ $chrome('video_gallery_subtitle', $siteCustomization['video_gallery_subtitle'] ?? null) }}</p>
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
        $testimonials = $testimonials ?? [];
        $testimonialCount = count($testimonials);
        $testimonialsScrollable = $testimonialCount > 3;
    @endphp
    @if(!empty($testimonials))
    <section class="ref-testimonials-section ref-deferred-paint" aria-labelledby="testimonials-title">
        <div class="ref-section-title">
            <h2 id="testimonials-title">{{ $chrome('testimonials_title', $siteCustomization['testimonials_title'] ?? null) }}</h2>
            <p>{{ $chrome('testimonials_subtitle', $siteCustomization['testimonials_subtitle'] ?? null) }}</p>
        </div>
        <div class="ref-testimonials-viewport {{ $testimonialsScrollable ? 'is-scrollable' : '' }}">
            <div class="ref-testimonials-grid {{ $testimonialsScrollable ? 'is-marquee' : '' }}" data-testimonial-count="{{ $testimonialCount }}">
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
                    <div class="ref-testimonial-stars" role="img" aria-label="{{ __('site.testimonial_stars_label', ['count' => \App\Support\LocalDigits::text($t['rating'])]) }}">
                        @for($i = 1; $i <= 5; $i++)
                            <span class="ref-star {{ $i <= $t['rating'] ? 'is-filled' : 'is-empty' }}" aria-hidden="true">★</span>
                        @endfor
                    </div>
                    <blockquote class="ref-testimonial-quote">
                        “{{ $t['text'] }}”
                    </blockquote>
                </article>
                @endforeach
            </div>
        </div>
        <div class="ref-testimonials-actions">
            <a href="/feedback" class="ref-secondary-button">{{ __('site.share_feedback_cta') }}</a>
        </div>
    </section>
    @elseif(\App\Support\SiteCustomization::testimonialsEnabled($siteCustomization))
    {{-- No approved review yet: invite the first one instead of showing made-up reviews. --}}
    <section class="ref-testimonials-invite ref-deferred-paint" aria-labelledby="testimonials-invite-title">
        <div class="ref-testimonials-invite-icon" aria-hidden="true"><i class="fa-solid fa-comments"></i></div>
        <div class="ref-testimonials-invite-text">
            <h2 id="testimonials-invite-title">{{ $chrome('testimonials_invite_title', $siteCustomization['testimonials_invite_title'] ?? null) }}</h2>
            <p>{{ $chrome('testimonials_invite_body', $siteCustomization['testimonials_invite_body'] ?? null) }}</p>
        </div>
        <a href="/feedback" class="ref-primary-button">{{ __('site.share_feedback_cta') }}</a>
    </section>
    @endif

</main>
@endsection
