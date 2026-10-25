@php
    $chrome = fn (string $key, ?string $custom = null): string => \App\Support\SiteChrome::text($key, $custom);
@endphp
<a class="ref-skip" href="#main">{{ __('site.skip_to_content') }}</a>
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
        <!-- Mobile Hamburger Menu Button -->
        <button type="button" class="ref-mobile-menu-btn" id="refMobileMenuToggle" aria-label="Open mobile navigation menu">
            <i class="fa-solid fa-bars" aria-hidden="true"></i>
        </button>

        <!-- Logo -->
        <a href="/" class="ref-logo ref-madina-logo" aria-label="Madina Natural Health Care home">
            <img src="/madina-header-logo.png?v=6" alt="Madina Natural Health Care" width="500" height="500">
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
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
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
            <form method="post" action="/locale" class="ref-locale-switch" aria-label="{{ __('site.language') }}">@csrf
                <button type="submit" name="locale" value="en" class="{{ app()->getLocale() === 'en' ? 'is-active' : '' }}" aria-pressed="{{ app()->getLocale() === 'en' ? 'true' : 'false' }}">EN</button>
                <button type="submit" name="locale" value="bn" class="{{ app()->getLocale() === 'bn' ? 'is-active' : '' }}" aria-pressed="{{ app()->getLocale() === 'bn' ? 'true' : 'false' }}">বাংলা</button>
            </form>
            @if($publicPageEnabled['products'] ?? true)
            <a href="/products" class="ref-shop"><i class="fa-solid fa-leaf" aria-hidden="true"></i><b>{{ $chrome('products', $siteCustomization['header_catalog_title'] ?? null) }}</b></a>
            @endif
        </div>
    </div>
</header>

<!-- Mobile Fullscreen Search Overlay -->
<div id="refMobileSearchOverlay" class="ref-mobile-search-overlay" aria-hidden="true">
    <div class="ref-mobile-search-inner">
        <div class="ref-mobile-search-top">
            <span class="ref-mobile-search-title">{{ __('site.search_label') }}</span>
            <button type="button" class="ref-mobile-search-close" id="refMobileSearchClose" aria-label="Close search">
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
        </div>
        <form method="get" action="/products" class="ref-mobile-search-form" autocomplete="off">
            <div class="ref-mobile-search-input-wrap">
                <input type="text" name="q" placeholder="{{ $chrome('search_placeholder', $siteCustomization['search_placeholder'] ?? null) }}" autocomplete="off" class="ref-mobile-search-input" id="refMobileSearchInput">
                <button type="submit" class="ref-mobile-search-submit" aria-label="{{ __('site.search_label') }}">
                    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Mobile Off-Canvas Drawer & Overlay -->
<div id="refMobileMenuOverlay" class="ref-mobile-menu-overlay" aria-hidden="true"></div>
<aside id="refMobileDrawer" class="ref-mobile-drawer" aria-hidden="true" aria-label="Mobile navigation drawer">
    <div class="ref-mobile-drawer-header">
        <a href="/" class="ref-mobile-drawer-logo">
            <img src="/madina-header-logo-white.png?v=6" alt="Madina Natural Health Care" class="ref-mobile-drawer-logo-img" onerror="this.src='/madina-header-logo.png?v=6'">
        </a>
        <button type="button" class="ref-mobile-drawer-close" id="refMobileDrawerClose" aria-label="Close menu">
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
