@php
    $reference = json_decode(file_get_contents(public_path('reference/home-products.json')), true);
    $referenceProducts = $referencePreview ? $reference['products'] : [];
    $featuredCategories = [
        ['Medical Equipment','fa-briefcase-medical'],['Meat & Fish','fa-drumstick-bite'],['Food Supplement','fa-seedling'],['Personal Care','fa-spa'],
        ['Water Purifier','fa-droplet'],['Leather Items','fa-briefcase'],['Unani Medicine','fa-mortar-pestle'],['Consumer Items','fa-basket-shopping'],
        ['Medicine','fa-capsules'],['Electric Kettle','fa-mug-hot'],['Agriculture Product','fa-leaf'],['Cookeries Items','fa-kitchen-set'],
        ['Cosmetics','fa-wand-magic-sparkles'],['Room Heater','fa-temperature-high'],['Furniture','fa-couch'],['Service','fa-hand-holding-heart'],
        ['Electronic Devices','fa-plug'],['Fashion & Lifestyle','fa-vest'],
    ];

    $slides = $siteCustomization['slides'] ?? [];
    if (empty($slides)) {
        $slides = [
            ['image' => '/branding/madina-hero-banner-1.jpg', 'link' => '/products', 'alt' => 'Madina Natural Health Care - Authentic Herbal & Wellness Products'],
            ['image' => '/branding/madina-side-banner-1.jpg', 'link' => '/products', 'alt' => 'Madina Premium Natural Care'],
            ['image' => '/branding/madina-side-banner-2.jpg', 'link' => '/dealers', 'alt' => 'Madina Authorized Dealer Network'],
            ['image' => '/reference/slider-04.webp', 'link' => '/products', 'alt' => 'Promotion 4'],
            ['image' => '/reference/slider-05.webp', 'link' => '/products', 'alt' => 'Promotion 5'],
        ];
    }

    $shortcuts = $siteCustomization['shortcuts'] ?? [];
    if (empty($shortcuts)) {
        $shortcuts = [
            ['title'=>'Our Products','subtitle'=>'browse wellness items','icon'=>'fa-seedling','link'=>'/products'],
            ['title'=>'Raise a Complain','subtitle'=>'Share your experience','icon'=>'fa-comments','link'=>'/contact'],
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
        <div class="ref-carousel" aria-roledescription="carousel" aria-label="Featured promotions">
            <div class="ref-slides">
                @foreach($slides as $idx => $slide)
                <a href="{{ $slide['link'] ?: '/products' }}" class="ref-slide {{ $loop->first ? 'is-active' : '' }}" aria-hidden="{{ $loop->first ? 'false' : 'true' }}">
                    <img src="{{ $slide['image'] ?: ('/reference/slider-0' . ($idx + 1) . '.webp') }}" alt="{{ $slide['alt'] ?: ('Promotion ' . ($idx + 1)) }}" @if($loop->first) fetchpriority="high" @else loading="lazy" @endif>
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
            <a href="{{ $siteCustomization['side_banner_1_link'] ?? '/products' }}">
                <img src="{{ $siteCustomization['side_banner_1_image'] ?? '/branding/madina-side-banner-1.jpg' }}" alt="{{ $siteCustomization['side_banner_1_alt'] ?? 'Madina Premium Natural Care' }}">
            </a>
            <a href="{{ $siteCustomization['side_banner_2_link'] ?? '/dealers' }}">
                <img src="{{ $siteCustomization['side_banner_2_image'] ?? '/branding/madina-side-banner-2.jpg' }}" alt="{{ $siteCustomization['side_banner_2_alt'] ?? 'Madina Authorized Dealer Network' }}">
            </a>
        </div>
    </section>
    <div class="ref-announcement"><i class="fa-solid fa-bullhorn" aria-hidden="true"></i><div><span>{{ $siteCustomization['announcement_text'] ?? 'Natural food supplements, unani medicine, personal care, agriculture and wellness products are available now.' }}</span></div></div>
    <section class="ref-shortcuts" aria-label="Quick links">
        @foreach($shortcuts as $s)
        <a href="{{ $s['link'] ?: '#' }}"><i class="fa-solid {{ $s['icon'] ?: 'fa-circle' }}" aria-hidden="true"></i><div><h3>{{ $s['title'] ?: 'Feature' }}</h3><p>{{ $s['subtitle'] ?: '' }}</p></div></a>
        @endforeach
    </section>
    <section class="ref-campaign-section" aria-label="Special Offers and Campaigns">
        <div class="ref-section-title">
            <span class="ref-campaign-badge"><i class="fa-solid fa-bolt" aria-hidden="true"></i> {{ $siteCustomization['campaign_badge'] ?? 'SPECIAL CAMPAIGN & OFFERS' }}</span>
            <h2>{{ $siteCustomization['campaign_title'] ?? 'Special Discounts & Promotional Offers' }}</h2>
            <p>{{ $siteCustomization['campaign_subtitle'] ?? 'Explore limited-time wellness packages, bulk entrepreneur offers and seasonal healthcare discounts.' }}</p>
        </div>
        <div class="ref-campaign-grid">
            <div class="ref-campaign-card highlight">
                <span class="ref-discount-tag">{{ $siteCustomization['campaign_card1_tag'] ?? 'Featured Campaign' }}</span>
                <div class="ref-campaign-body">
                    <h3>{{ $siteCustomization['campaign_card1_title'] ?? 'Healthcare Entrepreneur & Partner Package' }}</h3>
                    <p>{{ $siteCustomization['campaign_card1_desc'] ?? 'Comprehensive natural health package including diagnostic machine and physiotherapy equipment support for regional centers.' }}</p>
                    <div class="ref-campaign-pricing">
                        <strong>{{ $siteCustomization['campaign_card1_price'] ?? '20,000 Tk' }}</strong>
                        <del>{{ $siteCustomization['campaign_card1_mrp'] ?? '30,000 Tk' }}</del>
                        <span class="ref-save-pill">{{ $siteCustomization['campaign_card1_save'] ?? 'Save 10,000 Tk' }}</span>
                    </div>
                    <a href="/products" class="ref-primary-button">{{ $siteCustomization['campaign_card1_btn'] ?? 'View Package' }} <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
                </div>
            </div>
            <div class="ref-campaign-card">
                <span class="ref-discount-tag">{{ $siteCustomization['campaign_card2_tag'] ?? 'Seasonal Offer' }}</span>
                <div class="ref-campaign-body">
                    <h3>{{ $siteCustomization['campaign_card2_title'] ?? 'Family Wellness & Immunity Kit' }}</h3>
                    <p>{{ $siteCustomization['campaign_card2_desc'] ?? 'Selected organic food supplements, unani tonics and herbal wellness essentials for full family vitality.' }}</p>
                    <div class="ref-campaign-pricing">
                        <strong>{{ $siteCustomization['campaign_card2_price'] ?? '6,100 Tk' }}</strong>
                        <del>{{ $siteCustomization['campaign_card2_mrp'] ?? '7,000 Tk' }}</del>
                        <span class="ref-save-pill">{{ $siteCustomization['campaign_card2_save'] ?? 'Save 900 Tk' }}</span>
                    </div>
                    <a href="/products" class="ref-primary-button">{{ $siteCustomization['campaign_card2_btn'] ?? 'Explore Kit' }} <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
                </div>
            </div>
        </div>
    </section>
    <section class="ref-featured-categories">
        <div class="ref-section-title">
            <h2>{{ $siteCustomization['featured_categories_title'] ?? 'Featured Categories' }}</h2>
            <p>{{ $siteCustomization['featured_categories_subtitle'] ?? 'Get Your Desired Product from Featured Category!' }}</p>
        </div>
        <div class="ref-category-grid">@foreach($featuredCategories as [$title,$icon])<a href="/products?category={{ urlencode($title) }}"><i class="fa-solid {{ $icon }}" aria-hidden="true"></i><span>{{ $title }}</span></a>@endforeach</div>
    </section>
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
    <section id="featured-products" class="ref-featured-products">
        <div class="ref-section-title ref-section-title-flex">
            <div>
                <h2>{{ $siteCustomization['featured_products_title'] ?? 'Featured Products' }}</h2>
                <p>{{ $siteCustomization['featured_products_subtitle'] ?? 'Check & Get Your Desired Product!' }}</p>
            </div>
            <a href="/products" class="ref-view-all-link">{{ $siteCustomization['featured_products_view_all'] ?? 'View All Products' }} <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
        </div>
        <div class="ref-product-grid">
            @if(!$referencePreview)
            @forelse($featuredItems as $item)@include('reference.product-card',compact('item'))@empty<div class="ref-empty"><h2>{{ $siteCustomization['products_empty_title'] ?? 'Our collection is being prepared.' }}</h2><a href="/contact" class="ref-primary-button">Contact Us</a></div>@endforelse
            @else
            @foreach($referenceProducts as $index=>$product)
            <article class="ref-product-card">
                <span class="ref-stock">{{ $product['stock_display'] }}</span><button class="ref-wishlist" data-favorite="{{ $product['id'] }}" aria-label="Save {{ $product['name'] }}" aria-pressed="false"><i class="fa-regular fa-heart" aria-hidden="true"></i></button>
                <a class="ref-product-photo" href="/products/preview/{{ $product['id'] }}"><img src="{{ $product['image_path'] }}" alt="{{ $product['name'] }}" loading="lazy"></a>
                <div class="ref-product-info"><h3><a href="/products/preview/{{ $product['id'] }}">{{ $product['name'] }}</a></h3><p>Product information and dealer availability. Contact the company for details.</p>
                    <div class="ref-price"><strong>{{ $product['price_display'] }}</strong><del>{{ $product['old_price_display'] }}</del></div>
                    <button class="ref-add-cart" data-cart="{{ $product['id'] }}" data-name="{{ $product['name'] }}" data-price="{{ $product['price_display'] }}" data-image="{{ $product['image_path'] }}"><i class="fa-solid fa-cart-plus" aria-hidden="true"></i> Add to Cart</button>
                    <a class="ref-buy-now" href="/login"><i class="fa-solid fa-cart-shopping" aria-hidden="true"></i> Buy Now</a>
                </div>
            </article>
            @endforeach
            @endif
        </div>
    </section>
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
