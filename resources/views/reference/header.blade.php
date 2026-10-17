@php
    $headerCats = \App\Support\SiteCustomization::getNavCategories();
@endphp
<a class="ref-skip" href="#main">Skip to content</a>
<div class="ref-company-bar">
    <div class="ref-company-bar-inner">
        <span class="ref-bar-flourish left" aria-hidden="true">
            <span class="ref-bar-line"></span>
            <i class="fa-solid fa-seedling"></i>
        </span>
        <span class="ref-bar-text">{{ $siteCustomization['top_bar_text'] ?? 'MADINA NATURAL HEALTH CARE' }}</span>
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

        <!-- Desktop Search Form -->
        <form method="get" action="/products" class="ref-search">
            <label class="ref-sr-only" for="site-search">Search products</label>
            <input id="site-search" name="q" placeholder="{{ $siteCustomization['search_placeholder'] ?? 'Search' }}" autocomplete="off">
            <button aria-label="Search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i></button>
        </form>

        <!-- Mobile Search Button -->
        <button type="button" class="ref-mobile-search-btn" id="refMobileSearchToggle" aria-label="Open search">
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
        </button>

        <!-- Desktop Header Links -->
        <div class="ref-header-links">
            <a href="/about"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span><b>{{ $siteCustomization['header_about_title'] ?? 'About Us' }}</b><small>{{ $siteCustomization['header_about_subtitle'] ?? 'Our Story' }}</small></span></a>
            <a href="/contact"><i class="fa-solid fa-headset" aria-hidden="true"></i><span><b>{{ $siteCustomization['header_contact_title'] ?? 'Contact' }}</b><small>{{ $siteCustomization['header_contact_subtitle'] ?? 'Get Support' }}</small></span></a>
            <a href="/dealers"><i class="fa-solid fa-layer-group" aria-hidden="true"></i><span><b>{{ $siteCustomization['header_network_title'] ?? 'Dealer Network' }}</b><small>{{ 'Find a Local Partner' }}</small></span></a>
            @auth
            <a href="{{ auth()->user()->staff() ? '/company' : '/workspace' }}"><i class="fa-solid fa-user-check" aria-hidden="true"></i><span><b>{{ auth()->user()->name }}</b><small>{{ auth()->user()->staff() ? 'Company Portal' : (auth()->user()->dealer_code ?? 'My Account') }}</small></span></a>
            <form method="POST" action="/logout" class="ref-logout-form"><input type="hidden" name="_token" value="{{ csrf_token() }}"><button type="submit" class="ref-header-logout-btn" title="Log out" aria-label="Log out"><i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i><span><b>Log out</b><small>Exit Session</small></span></button></form>
            @else
            <a href="/login"><i class="fa-solid fa-user" aria-hidden="true"></i><span><b>{{ $siteCustomization['header_account_title'] ?? 'Account' }}</b><small>{{ $siteCustomization['header_account_subtitle'] ?? 'Partner Login' }}</small></span></a>
            @endauth
            <a href="/products" class="ref-shop"><i class="fa-solid fa-leaf" aria-hidden="true"></i><b>{{ $siteCustomization['header_catalog_title'] ?? 'Products' }}</b></a>
        </div>
    </div>
</header>

<!-- Mobile Fullscreen Search Overlay -->
<div id="refMobileSearchOverlay" class="ref-mobile-search-overlay" aria-hidden="true">
    <div class="ref-mobile-search-inner">
        <div class="ref-mobile-search-top">
            <span class="ref-mobile-search-title">Search Products</span>
            <button type="button" class="ref-mobile-search-close" id="refMobileSearchClose" aria-label="Close search">
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
        </div>
        <form method="get" action="/products" class="ref-mobile-search-form" autocomplete="off">
            <div class="ref-mobile-search-input-wrap">
                <input type="text" name="q" placeholder="{{ $siteCustomization['search_placeholder'] ?? 'What are you looking for?' }}" autocomplete="off" class="ref-mobile-search-input" id="refMobileSearchInput">
                <button type="submit" class="ref-mobile-search-submit" aria-label="Search">
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
            <div class="ref-mobile-drawer-group-title">Navigation</div>
            <ul class="ref-mobile-drawer-nav-list">
                <li><a href="/"><i class="fa-solid fa-house"></i> Home</a></li>
                <li><a href="/products"><i class="fa-solid fa-leaf"></i> Our Products</a></li>
                <li><a href="/dealers"><i class="fa-solid fa-layer-group"></i> Dealer Network</a></li>
                <li><a href="/about"><i class="fa-solid fa-circle-info"></i> About Us</a></li>
                <li><a href="/contact"><i class="fa-solid fa-headset"></i> Contact Us</a></li>
            </ul>
        </div>
        <div class="ref-mobile-drawer-group">
            <div class="ref-mobile-drawer-group-title">Product Categories</div>
            <ul class="ref-mobile-drawer-cat-list">
                @foreach($headerCats as $category)
                <li>
                    <a href="/products?category={{ urlencode($category) }}">
                        <span>{{ $category }}</span>
                        <i class="fa-solid fa-chevron-right"></i>
                    </a>
                </li>
                @endforeach
            </ul>
        </div>
        <div class="ref-mobile-drawer-group ref-mobile-drawer-auth-group">
            @auth
            <a href="{{ auth()->user()->staff() ? '/company' : '/workspace' }}" class="ref-drawer-btn ref-drawer-btn-primary">
                <i class="fa-solid fa-user-check"></i> {{ auth()->user()->staff() ? 'Company Portal' : 'Partner Workspace' }}
            </a>
            <form method="POST" action="/logout" class="ref-drawer-logout-form">
                <input type="hidden" name="_token" value="{{ csrf_token() }}">
                <button type="submit" class="ref-drawer-btn ref-drawer-btn-secondary">
                    <i class="fa-solid fa-arrow-right-from-bracket"></i> Log Out
                </button>
            </form>
            @else
            <a href="/login" class="ref-drawer-btn ref-drawer-btn-primary">
                <i class="fa-solid fa-user"></i> Partner Login
            </a>
            <a href="/contact#vendor" class="ref-drawer-btn ref-drawer-btn-secondary">
                <i class="fa-solid fa-handshake"></i> Become a Partner
            </a>
            @endauth
        </div>
    </div>
</aside>

<nav class="ref-category-nav" aria-label="Product categories"><div class="ref-container">
    @foreach($headerCats as $category)
    <div class="ref-nav-item"><a href="/products?category={{ urlencode($category) }}">{{ $category }}</a><div class="ref-category-dropdown"><a href="/products?category={{ urlencode($category) }}">All {{ $category }}</a><a href="/products">Browse all products</a></div></div>
    @endforeach
</div></nav>
