<a class="ref-skip" href="#main">Skip to content</a>
<div class="ref-company-bar">{{ $siteCustomization['top_bar_text'] ?? 'MADINA NATURAL HEALTH CARE' }}</div>
<header class="ref-header">
    <div class="ref-container ref-header-inner">
        <a href="/" class="ref-logo ref-madina-logo" aria-label="Madina Natural Health Care home"><img src="/madina-header-logo.png?v=5" alt="Madina Natural Health Care" width="353" height="196"></a>
        <form method="get" action="/products" class="ref-search"><label class="ref-sr-only" for="site-search">Search products</label><input id="site-search" name="q" placeholder="{{ $siteCustomization['search_placeholder'] ?? 'Search' }}" autocomplete="off"><button aria-label="Search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i></button></form>
        <div class="ref-header-links">
            <a href="/about"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span><b>{{ $siteCustomization['header_about_title'] ?? 'About Us' }}</b><small>{{ $siteCustomization['header_about_subtitle'] ?? 'Our Story' }}</small></span></a>
            <a href="/contact"><i class="fa-solid fa-headset" aria-hidden="true"></i><span><b>{{ $siteCustomization['header_contact_title'] ?? 'Contact' }}</b><small>{{ $siteCustomization['header_contact_subtitle'] ?? 'Get Support' }}</small></span></a>
            <a href="/#featured-products"><i class="fa-solid fa-gift" aria-hidden="true"></i><span><b>{{ $siteCustomization['header_offers_title'] ?? 'Offers' }}</b><small>{{ $siteCustomization['header_offers_subtitle'] ?? 'Latest Offers' }}</small></span></a>
            <a href="/dealers"><i class="fa-solid fa-layer-group" aria-hidden="true"></i><span><b>{{ $siteCustomization['header_projects_title'] ?? 'Projects' }}</b><small>{{ $siteCustomization['header_projects_subtitle'] ?? 'Dealer Network' }}</small></span></a>
            <a href="/login"><i class="fa-solid fa-user" aria-hidden="true"></i><span><b>{{ $siteCustomization['header_account_title'] ?? 'Account' }}</b><small>{{ $siteCustomization['header_account_subtitle'] ?? 'Partner Login' }}</small></span></a>
            <a href="/products" class="ref-shop"><i class="fa-solid fa-store" aria-hidden="true"></i><b>{{ $siteCustomization['header_shop_title'] ?? 'Shop' }}</b></a>
        </div>
    </div>
</header>
@php
    $headerCats = is_array($siteCustomization['nav_categories'] ?? null)
        ? $siteCustomization['nav_categories']
        : array_filter(array_map('trim', preg_split('/[\r\n,]+/', $siteCustomization['nav_categories'] ?? '')));
    if (empty($headerCats)) {
        $headerCats = ['Medical Equipment','Leather Items','Consumer Items','Medicine','Agriculture Product','Cosmetics','Furniture','Service'];
    }
@endphp
<nav class="ref-category-nav" aria-label="Product categories"><div class="ref-container">
    @foreach($headerCats as $category)
    <div class="ref-nav-item"><a href="/products?category={{ urlencode($category) }}">{{ $category }}</a><div class="ref-category-dropdown"><a href="/products?category={{ urlencode($category) }}">All {{ $category }}</a><a href="/products">Browse all products</a></div></div>
    @endforeach
</div></nav>


