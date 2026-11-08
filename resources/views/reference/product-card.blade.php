@php
    $catName = $item['category'] ?? 'General';
    $catSlug = $item['category_slug'] ?? \Illuminate\Support\Str::slug($catName);
    $hasCustomImage = !empty($item['image_path']);
    $stockImage = $hasCustomImage ? null : \App\Support\StockImage::forCategory($catName, $item['name'] ?? null);
    $imageSrc = $hasCustomImage ? $item['image_path'] : $stockImage['url'];
    $stockStatus = $item['stock_status'] ?? 'out_of_stock';
    $inStock = $stockStatus !== 'out_of_stock';
    $stockLabel = ! $inStock
        ? \App\Support\SiteChrome::text('out_of_stock', $siteCustomization['product_out_of_stock_label'] ?? null)
        : ($stockStatus === 'low_stock'
            ? __('site.low_stock')
            : \App\Support\SiteChrome::text('in_stock', $siteCustomization['product_in_stock_label'] ?? null));
    $mrp = (float) str_replace(',', '', preg_replace('/[^\d.]/', '', (string) ($item['mrp'] ?? '0')));
    $displayMrp = \App\Support\LocalDigits::text(number_format($mrp, $mrp === floor($mrp) ? 0 : 2));
    $isFeatured = !empty($item['featured']);
@endphp
<article class="ref-product-card"
    data-category="{{ $catSlug }}"
    data-id="{{ $item['id'] }}"
    data-name="{{ $item['name'] }}"
    data-mrp="৳ {{ $displayMrp }}"
    data-image="{{ $imageSrc }}"
    data-category-name="{{ $catName }}"
    data-stock-status="{{ $stockStatus }}"
    data-desc="{{ $item['description'] ?? '' }}"
    data-url="{{ $item['url'] }}">

    {{-- The picture stands alone on a bright panel: no shadow, overlay or badge on top of it. --}}
    <a class="ref-card-picture{{ $hasCustomImage ? '' : ' is-stock' }}" href="{{ $item['url'] }}" aria-label="{{ $item['name'] }}">
        @if($hasCustomImage)
            <img src="{{ $imageSrc }}" alt="{{ $item['name'] }}" loading="lazy" decoding="async">
        @else
            <img src="{{ $stockImage['url'] }}" alt="{{ $stockImage['alt'] }}" width="{{ $stockImage['width'] }}" height="{{ $stockImage['height'] }}" loading="lazy" decoding="async">
        @endif
    </a>
    @unless($hasCustomImage)
        <p class="ref-card-note">{{ $stockImage['caption'] }}</p>
    @endunless

    <div class="ref-card-body">
        <div class="ref-card-meta">
            <span class="ref-card-category">{{ $catName }}</span>
            @if($isFeatured)
                <span class="ref-card-flag"><i class="fa-solid fa-star" aria-hidden="true"></i> {{ __('site.featured') }}</span>
            @endif
        </div>

        <h3 class="ref-card-title"><a href="{{ $item['url'] }}">{{ $item['name'] }}</a></h3>

        <div class="ref-card-price-row">
            <div class="ref-card-price">
                <strong>৳ {{ $displayMrp }}</strong>
                <small>{{ __('site.mrp') }} ({{ __('site.retail_price') }})</small>
            </div>
            <span class="ref-card-stock {{ $inStock ? 'is-in' : 'is-out' }}">{{ $stockLabel }}</span>
        </div>

        <a class="ref-card-cta" href="{{ $item['url'] }}">{{ __('site.view_product_details') }} <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
    </div>
</article>
