@php
    $chrome = fn (string $key, ?string $custom = null): string => \App\Support\SiteChrome::text($key, $custom);
@endphp
<a class="ref-skip" href="#main">{{ __('site.skip_to_content') }}</a>
<div class="ref-sticky-chrome">
<div class="ref-company-bar">
    <div class="ref-company-bar-inner">
        <span class="ref-bar-flourish left" aria-hidden="true">
            <span class="ref-bar-line"></span>
            <i class="fa-solid fa-seedling"></i>
        </span>
        <span class="ref-bar-text">{{ $chrome('company_bar', $siteCustomization['top_bar_text'] ?? null) }}</span>
        <span class="ref-bar-flourish right" aria-hidden="true">
            <i class="fa-solid fa-seedling"></i>
            <span class="ref-bar-line"></span>
        </span>
    </div>
</div>
<header class="ref-header">
    <div class="ref-container ref-header-inner">
        <!-- Mobile Hamburger Menu Button (CSS bars so the control stays visible if icon fonts fail) -->
        <button type="button" class="ref-mobile-menu-btn" id="refMobileMenuToggle" aria-label="{{ __('site.open_mobile_nav') }}">
            <span class="ref-burger" aria-hidden="true"><span></span><span></span><span></span></span>
        </button>

        <!-- Logo: light mark for desktop; transparent-on-dark mark for mobile navy header -->
        <a href="/" class="ref-logo ref-madina-logo" aria-label="{{ __('site.brand_home') }}">
            <img class="ref-logo-img-light" src="/madina-header-logo.png?v=9" alt="{{ __('site.company_bar') }}" width="160" height="40" style="height:auto;max-height:40px;width:auto;" decoding="async">
            <img class="ref-logo-img-dark" src="/madina-header-logo-white.png?v=9" alt="{{ __('site.company_bar') }}" width="160" height="40" style="height:auto;max-height:40px;width:auto;" decoding="async">
        </a>

        @if($publicPageEnabled['products'] ?? true)
        <!-- Desktop Search Form -->
        <form method="get" action="/products" class="ref-search">
            <label class="ref-sr-only" for="site-search">{{ __('site.search_label') }}</label>
            <input id="site-search" name="q" placeholder="{{ $chrome('search_placeholder', $siteCustomization['search_placeholder'] ?? null) }}" autocomplete="off">
            <button aria-label="{{ __('site.search_label') }}"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i></button>
        </form>

        <!-- Mobile Search Button -->
        <button type="button" class="ref-mobile-search-btn" id="refMobileSearchToggle" aria-label="{{ __('site.search_label') }}">
            <span class="ref-search-glyph" aria-hidden="true"></span>
        </button>
        @endif

        <!-- Desktop Header Links -->
        <div class="ref-header-links">
            @if($publicPageEnabled['about'] ?? true)
            <a href="/about"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span><b>{{ $chrome('about', $siteCustomization['header_about_title'] ?? null) }}</b><small>{{ $chrome('about_sub', $siteCustomization['header_about_subtitle'] ?? null) }}</small></span></a>
            @endif
            @if($publicPageEnabled['contact'] ?? true)
            <a href="/contact"><i class="fa-solid fa-headset" aria-hidden="true"></i><span><b>{{ $chrome('contact', $siteCustomization['header_contact_title'] ?? null) }}</b><small>{{ $chrome('contact_sub', $siteCustomization['header_contact_subtitle'] ?? null) }}</small></span></a>
            @endif
            @if($publicPageEnabled['dealers'] ?? true)
            <a href="/dealers"><i class="fa-solid fa-layer-group" aria-hidden="true"></i><span><b>{{ $chrome('network', $siteCustomization['header_network_title'] ?? null) }}</b><small>{{ $chrome('network_sub') }}</small></span></a>
            @endif
            @auth
            <a href="{{ \App\Support\PortalHost::homeUrl(auth()->user()) }}"><i class="fa-solid fa-user-check" aria-hidden="true"></i><span><b>{{ auth()->user()->name }}</b><small>{{ auth()->user()->owner() ? __('site.owner_portal') : (auth()->user()->staff() ? __('site.company_portal') : (auth()->user()->dealer_code ?? __('site.my_account'))) }}</small></span></a>
            <form method="POST" action="/logout" class="ref-logout-form"><input type="hidden" name="_token" value="{{ csrf_token() }}"><button type="submit" class="ref-header-logout-btn" title="{{ __('site.log_out') }}" aria-label="{{ __('site.log_out') }}"><i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i><span><b>{{ __('site.log_out') }}</b><small>{{ __('site.exit_session') }}</small></span></button></form>
            @else
            <a href="/login"><i class="fa-solid fa-user" aria-hidden="true"></i><span><b>{{ $chrome('account', $siteCustomization['header_account_title'] ?? null) }}</b><small>{{ $chrome('account_sub', $siteCustomization['header_account_subtitle'] ?? null) }}</small></span></a>
            @endauth
            <button type="button" class="ref-theme-toggle" data-theme-toggle data-label-day="{{ __('site.day_mode') }}" data-label-night="{{ __('site.night_mode') }}" aria-label="{{ __('site.night_mode') }}" title="{{ __('site.night_mode') }}">
                <span class="theme-icon-day" aria-hidden="true">
                    <svg class="ref-theme-glyph" viewBox="0 0 24 24" width="18" height="18" focusable="false"><path fill="currentColor" d="M12 4.5a1 1 0 0 1 1 1V7a1 1 0 1 1-2 0V5.5a1 1 0 0 1 1-1Zm0 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Zm7.5-2.5a1 1 0 1 1 0-2H18a1 1 0 1 1 0 2h1.5ZM6 12a1 1 0 0 1-1 1H3.5a1 1 0 1 1 0-2H5a1 1 0 0 1 1 1Zm9.95 5.45a1 1 0 0 1 0 1.41l-1.06 1.06a1 1 0 1 1-1.41-1.41l1.06-1.06a1 1 0 0 1 1.41 0ZM8.52 6.08a1 1 0 0 1 0 1.41L7.46 8.55A1 1 0 0 1 6.05 7.14l1.06-1.06a1 1 0 0 1 1.41 0Zm8.49 0a1 1 0 0 1 1.41 0l1.06 1.06A1 1 0 1 1 18.07 8.55l-1.06-1.06a1 1 0 0 1 0-1.41ZM8.52 17.92a1 1 0 0 1-1.41 0L6.05 16.86A1 1 0 1 1 7.46 15.45l1.06 1.06a1 1 0 0 1 0 1.41ZM12 17a1 1 0 0 1 1 1v1.5a1 1 0 1 1-2 0V18a1 1 0 0 1 1-1Z"/></svg>
                </span>
                <span class="theme-icon-night" aria-hidden="true">
                    <svg class="ref-theme-glyph" viewBox="0 0 24 24" width="18" height="18" focusable="false"><path fill="currentColor" d="M17.5 14.3A7.5 7.5 0 0 1 9.7 6.5a.75.75 0 0 0-.95-.95A8.5 8.5 0 1 0 18.45 15.25a.75.75 0 0 0-.95-.95Z"/></svg>
                </span>
                <span class="ref-theme-toggle-label">{{ __('site.theme') }}</span>
            </button>
            <form method="post" action="/locale" class="ref-locale-switch" aria-label="{{ __('site.language') }}">@csrf
                <input type="hidden" name="return_url" value="{{ request()->fullUrl() }}">
                <button type="submit" name="locale" value="en" class="{{ app()->getLocale() === 'en' ? 'is-active' : '' }}" aria-pressed="{{ app()->getLocale() === 'en' ? 'true' : 'false' }}">EN</button>
                <button type="submit" name="locale" value="bn" class="{{ app()->getLocale() === 'bn' ? 'is-active' : '' }}" aria-pressed="{{ app()->getLocale() === 'bn' ? 'true' : 'false' }}">বাংলা</button>
            </form>
            @if($publicPageEnabled['products'] ?? true)
            <a href="/products" class="ref-shop"><i class="fa-solid fa-leaf" aria-hidden="true"></i><b>{{ $chrome('products', $siteCustomization['header_catalog_title'] ?? null) }}</b></a>
            @endif
        </div>
    </div>
</header>
</div>

<!-- Mobile Fullscreen Search Overlay -->
@php
    $mobileSearchCategories = collect(\App\Support\SiteCustomization::getFeaturedCategories())
        ->take(6)
        ->values();
@endphp
<div id="refMobileSearchOverlay" class="ref-mobile-search-overlay" aria-hidden="true">
    <div class="ref-mobile-search-inner">
        <div class="ref-mobile-search-top">
            <span class="ref-mobile-search-title">{{ __('site.search_label') }}</span>
            <button type="button" class="ref-mobile-search-close" id="refMobileSearchClose" aria-label="{{ __('site.close_search') }}">
                <span class="ref-mobile-search-close-glyph" aria-hidden="true"></span>
            </button>
        </div>
        <form method="get" action="/products" class="ref-mobile-search-form" autocomplete="off" id="refMobileSearchForm">
            <div class="ref-mobile-search-input-wrap">
                <span class="ref-mobile-search-leading" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="18" height="18" focusable="false"><path fill="currentColor" d="M10.5 3a7.5 7.5 0 0 1 5.9 12.1l3.8 3.7-1.4 1.4-3.7-3.8A7.5 7.5 0 1 1 10.5 3Zm0 2a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11Z"/></svg>
                </span>
                <input type="search" name="q" value="{{ request('q') }}" placeholder="{{ $chrome('search_placeholder', $siteCustomization['search_placeholder'] ?? null) }}" autocomplete="off" enterkeyhint="search" class="ref-mobile-search-input" id="refMobileSearchInput" aria-controls="refMobileSearchResults" aria-autocomplete="list">
                <button type="submit" class="ref-mobile-search-submit" aria-label="{{ __('site.search_submit') }}">
                    <span>{{ __('site.search_go') }}</span>
                </button>
            </div>
        </form>

        <div class="ref-mobile-search-body">
            <div class="ref-mobile-search-hint" id="refMobileSearchHint">{{ __('site.search_mobile_hint') }}</div>

            @if($mobileSearchCategories->isNotEmpty())
            <div class="ref-mobile-search-shortcuts" id="refMobileSearchShortcuts">
                <p class="ref-mobile-search-section-label">{{ __('site.search_popular_categories') }}</p>
                <div class="ref-mobile-search-chips">
                    @foreach($mobileSearchCategories as $cat)
                        <a class="ref-mobile-search-chip" href="{{ $cat['link'] }}">{{ $cat['name'] }}</a>
                    @endforeach
                </div>
            </div>
            @endif

            <div class="ref-mobile-search-results" id="refMobileSearchResults" hidden>
                <p class="ref-mobile-search-section-label" id="refMobileSearchResultsLabel">{{ __('site.search_results') }}</p>
                <ul class="ref-mobile-search-list" id="refMobileSearchList" role="listbox" aria-labelledby="refMobileSearchResultsLabel"></ul>
                <a class="ref-mobile-search-view-all" id="refMobileSearchViewAll" href="/products">{{ __('site.search_view_all') }}</a>
            </div>

            <div class="ref-mobile-search-empty" id="refMobileSearchEmpty" hidden>
                <p>{{ __('site.search_no_matches') }}</p>
                <a href="/products" class="ref-mobile-search-browse">{{ __('site.search_browse_all') }}</a>
            </div>
        </div>
    </div>
</div>

<!-- Mobile Off-Canvas Drawer & Overlay -->
<div id="refMobileMenuOverlay" class="ref-mobile-menu-overlay" aria-hidden="true"></div>
<aside id="refMobileDrawer" class="ref-mobile-drawer" aria-hidden="true" aria-label="{{ __('site.mobile_nav_drawer') }}">
    <div class="ref-mobile-drawer-header">
        <a href="/" class="ref-mobile-drawer-logo">
            <img src="/madina-header-logo-white.png?v=9" alt="{{ __('site.company_bar') }}" class="ref-mobile-drawer-logo-img" width="160" height="40" decoding="async" onerror="this.src='/madina-header-logo.png?v=9'">
        </a>
        <button type="button" class="ref-mobile-drawer-close" id="refMobileDrawerClose" aria-label="{{ __('site.close_menu') }}">
            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
        </button>
    </div>
    <div class="ref-mobile-drawer-body">
        <div class="ref-mobile-drawer-group">
            <div class="ref-mobile-drawer-group-title">{{ __('site.navigation') }}</div>
            <ul class="ref-mobile-drawer-nav-list">
                <li><a href="/"><i class="fa-solid fa-house"></i> {{ __('site.home') }}</a></li>
                @if($publicPageEnabled['products'] ?? true)<li><a href="/products"><i class="fa-solid fa-leaf"></i> {{ $chrome('products', $siteCustomization['header_catalog_title'] ?? null) }}</a></li>@endif
                @if($publicPageEnabled['dealers'] ?? true)<li><a href="/dealers"><i class="fa-solid fa-layer-group"></i> {{ $chrome('network', $siteCustomization['header_network_title'] ?? null) }}</a></li>@endif
                @if($publicPageEnabled['about'] ?? true)<li><a href="/about"><i class="fa-solid fa-circle-info"></i> {{ $chrome('about', $siteCustomization['header_about_title'] ?? null) }}</a></li>@endif
                @if($publicPageEnabled['services'] ?? true)<li><a href="/services"><i class="fa-solid fa-hand-holding-heart"></i> {{ __('site.services') }}</a></li>@endif
                @if($publicPageEnabled['partnership'] ?? true)<li><a href="/partnership"><i class="fa-solid fa-handshake"></i> {{ __('site.become_a_partner') }}</a></li>@endif
                @if($publicPageEnabled['contact'] ?? true)<li><a href="/contact"><i class="fa-solid fa-headset"></i> {{ $chrome('contact', $siteCustomization['header_contact_title'] ?? null) }}</a></li>@endif
                @if($publicPageEnabled['faq'] ?? true)<li><a href="/faq"><i class="fa-solid fa-circle-question"></i> {{ __('site.faq') }}</a></li>@endif
            </ul>
        </div>
        <div class="ref-mobile-drawer-group">
            <div class="ref-mobile-drawer-group-title">{{ __('site.language') }}</div>
            <form method="post" action="/locale" class="ref-locale-switch ref-drawer-locale-switch" aria-label="{{ __('site.language') }}">@csrf
                <input type="hidden" name="return_url" value="{{ request()->fullUrl() }}">
                <button type="submit" name="locale" value="en" class="{{ app()->getLocale() === 'en' ? 'is-active' : '' }}" aria-pressed="{{ app()->getLocale() === 'en' ? 'true' : 'false' }}">English (EN)</button>
                <button type="submit" name="locale" value="bn" class="{{ app()->getLocale() === 'bn' ? 'is-active' : '' }}" aria-pressed="{{ app()->getLocale() === 'bn' ? 'true' : 'false' }}">বাংলা (BN)</button>
            </form>
        </div>
        <div class="ref-mobile-drawer-group">
            <div class="ref-mobile-drawer-group-title">{{ __('site.theme') }}</div>
            <button type="button" class="ref-drawer-btn ref-drawer-btn-secondary ref-theme-toggle" data-theme-toggle data-label-day="{{ __('site.day_mode') }}" data-label-night="{{ __('site.night_mode') }}" aria-label="{{ __('site.night_mode') }}" title="{{ __('site.night_mode') }}" style="width:100%;justify-content:flex-start;">
                <span class="theme-icon-day" aria-hidden="true">
                    <svg class="ref-theme-glyph" viewBox="0 0 24 24" width="18" height="18" focusable="false"><path fill="currentColor" d="M12 4.5a1 1 0 0 1 1 1V7a1 1 0 1 1-2 0V5.5a1 1 0 0 1 1-1Zm0 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Zm7.5-2.5a1 1 0 1 1 0-2H18a1 1 0 1 1 0 2h1.5ZM6 12a1 1 0 0 1-1 1H3.5a1 1 0 1 1 0-2H5a1 1 0 0 1 1 1Zm9.95 5.45a1 1 0 0 1 0 1.41l-1.06 1.06a1 1 0 1 1-1.41-1.41l1.06-1.06a1 1 0 0 1 1.41 0ZM8.52 6.08a1 1 0 0 1 0 1.41L7.46 8.55A1 1 0 0 1 6.05 7.14l1.06-1.06a1 1 0 0 1 1.41 0Zm8.49 0a1 1 0 0 1 1.41 0l1.06 1.06A1 1 0 1 1 18.07 8.55l-1.06-1.06a1 1 0 0 1 0-1.41ZM8.52 17.92a1 1 0 0 1-1.41 0L6.05 16.86A1 1 0 1 1 7.46 15.45l1.06 1.06a1 1 0 0 1 0 1.41ZM12 17a1 1 0 0 1 1 1v1.5a1 1 0 1 1-2 0V18a1 1 0 0 1 1-1Z"/></svg>
                </span>
                <span class="theme-icon-night" aria-hidden="true">
                    <svg class="ref-theme-glyph" viewBox="0 0 24 24" width="18" height="18" focusable="false"><path fill="currentColor" d="M17.5 14.3A7.5 7.5 0 0 1 9.7 6.5a.75.75 0 0 0-.95-.95A8.5 8.5 0 1 0 18.45 15.25a.75.75 0 0 0-.95-.95Z"/></svg>
                </span>
                <span>{{ __('site.day_night_mode') }}</span>
            </button>
        </div>
        <div class="ref-mobile-drawer-group ref-mobile-drawer-auth-group">
            @auth
            <a href="{{ \App\Support\PortalHost::homeUrl(auth()->user()) }}" class="ref-drawer-btn ref-drawer-btn-primary">
                <i class="fa-solid fa-user-check"></i> {{ auth()->user()->owner() ? __('site.owner_portal') : (auth()->user()->staff() ? __('site.company_portal') : __('site.partner_workspace')) }}
            </a>
            <form method="POST" action="/logout" class="ref-drawer-logout-form">
                <input type="hidden" name="_token" value="{{ csrf_token() }}">
                <button type="submit" class="ref-drawer-btn ref-drawer-btn-secondary">
                    <i class="fa-solid fa-arrow-right-from-bracket"></i> {{ __('site.log_out') }}
                </button>
            </form>
            @else
            <a href="/login" class="ref-drawer-btn ref-drawer-btn-primary">
                <i class="fa-solid fa-user"></i> {{ $chrome('account_sub', $siteCustomization['header_account_subtitle'] ?? null) }}
            </a>
            @if(($publicPageEnabled['partnership'] ?? true) || ($publicPageEnabled['contact'] ?? true))
            <a href="{{ ($publicPageEnabled['partnership'] ?? true) ? '/partnership' : '/contact#vendor' }}" class="ref-drawer-btn ref-drawer-btn-secondary">
                <i class="fa-solid fa-handshake"></i> {{ __('site.become_a_partner') }}
            </a>
            @endif
            @endauth
        </div>
    </div>
</aside>
