@extends('reference.layout')
@section('title',$item['name'].' · Madina Natural Health Care')
@section('bodyClass','ref-detail-page')
@section('content')
<main id="main" class="ref-container ref-inner-main"><nav class="ref-breadcrumb" aria-label="Breadcrumb"><a href="/" aria-label="Home"><i class="fa-solid fa-house" aria-hidden="true"></i></a><span>/</span><a href="/products">Products</a><span>/</span><span>{{ $item['name'] }}</span></nav>
<div class="ref-share-bar"><span>{{ $siteCustomization['product_share_label'] ?? 'Share:' }}</span><button data-share-url aria-label="Share product"><i class="fa-solid fa-share-nodes" aria-hidden="true"></i></button><button data-copy-url aria-label="Copy product link"><i class="fa-solid fa-link" aria-hidden="true"></i></button></div>
<section class="ref-detail-card"><div class="ref-detail-image">@if(!empty($item['image_path']))<img src="{{ $item['image_path'] }}" alt="{{ $item['name'] }}">@else<div class="ref-no-image">No Image</div>@endif</div><div class="ref-detail-info"><h1>{{ $item['name'] }}</h1>
<div class="ref-metadata"><span>MRP: <b>{{ $item['mrp'] }} BDT</b></span><span>Availability: <b class="{{ $item['stock']>0?'ref-green':'ref-orange' }}">{{ ($item['stock_status'] ?? '') === 'out_of_stock' || $item['stock'] <= 0 ? ($siteCustomization['product_out_of_stock_label'] ?? 'Awaiting Stock') : (($item['stock_status'] ?? '') === 'low_stock' ? 'Low Stock' : ($siteCustomization['product_in_stock_label'] ?? 'In Stock')) }}</b></span><span>Product Code: <b>{{ $item['sku'] }}</b></span><span>Category: <b>{{ $item['category'] }}</b></span></div>
<div class="ref-partner-information">
    <span class="ref-company-eyebrow">Our Distribution Network</span>
    <h2>Available through our dealers</h2>
    <p>Contact an authorized dealer for product availability and purchase enquiries. Registered dealers and sub-dealers place orders through their dashboards.</p>
    <div class="ref-company-intro-actions"><a href="/dealers" class="ref-primary-button"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> Find a Dealer</a>
    @if(auth()->check() && !auth()->user()->staff())
        <a href="/workspace/catalog" class="ref-secondary-button">Open Partner Dashboard</a>
    @elseif(!auth()->check())
        <a href="/login" class="ref-secondary-button">Dealer / Sub-dealer Login</a>
    @endif
    </div>
</div>
</div></section>
@if(!empty($item['video_url'])||!empty($item['video_embed_url']))<section class="ref-product-video"><h2>{{ $siteCustomization['product_video_title'] ?? 'Product Video' }}</h2>@if(!empty($item['video_embed_url']))<iframe src="{{ $item['video_embed_url'] }}" title="{{ $item['name'] }} product video" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allow="encrypted-media; picture-in-picture; fullscreen" allowfullscreen></iframe>@else<video controls playsinline preload="metadata" @if(!empty($item['image_path'])) poster="{{ $item['image_path'] }}" @endif><source src="{{ $item['video_url'] }}">Your browser does not support video playback.</video>@endif</section>@endif
<section class="ref-product-description"><div class="ref-product-tabs" role="tablist" aria-label="Product information"><button role="tab" id="description-tab" aria-controls="description-panel" aria-selected="true" data-info-tab="description-panel">{{ $siteCustomization['product_tab_description'] ?? 'Description' }}</button><button role="tab" id="specification-tab" aria-controls="specification-panel" aria-selected="false" data-info-tab="specification-panel">{{ $siteCustomization['product_tab_specification'] ?? 'Specification' }}</button></div><div id="description-panel" role="tabpanel" aria-labelledby="description-tab"><h2>{{ $siteCustomization['product_tab_description'] ?? 'Description' }}</h2><h3>{{ $item['name'] }}</h3><p>{{ $item['description'] }}</p></div><div id="specification-panel" role="tabpanel" aria-labelledby="specification-tab" hidden><h2>{{ $siteCustomization['product_tab_specification'] ?? 'Specification' }}</h2><table><tbody><tr><th>Product code</th><td>{{ $item['sku'] }}</td></tr><tr><th>Category</th><td>{{ $item['category'] }}</td></tr><tr><th>Unit</th><td>{{ $item['unit'] }}</td></tr><tr><th>MRP</th><td>{{ $item['mrp'] }} BDT</td></tr></tbody></table></div></section></main>
@endsection
