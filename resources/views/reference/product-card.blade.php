@php
    $catName = $item['category'] ?? 'General';
    $catSlug = $item['category_slug'] ?? \Illuminate\Support\Str::slug($catName);
    $imageSrc = ($item['image_path'] ?? '') ?: '/madina-header-logo.png?v=6';
    $stockStatus = $item['stock_status'] ?? 'out_of_stock';
    $inStock = $stockStatus !== 'out_of_stock';
    $stockLabel = ! $inStock
        ? ($siteCustomization['product_out_of_stock_label'] ?? 'Awaiting Stock')
        : ($stockStatus === 'low_stock' ? 'Low Stock' : ($siteCustomization['product_in_stock_label'] ?? 'In Stock'));
    $mrp = (float) str_replace(',', '', preg_replace('/[^\d.]/', '', (string) ($item['mrp'] ?? '0')));
    $displayMrp = number_format($mrp, $mrp === floor($mrp) ? 0 : 2);
@endphp
<article class="ref-product-card"
    data-category="{{ $catSlug }}"
    data-id="{{ $item['id'] }}"
    data-name="{{ $item['name'] }}"
    data-mrp="{{ $displayMrp }} BDT"
    data-image="{{ $imageSrc }}"
    data-category-name="{{ $catName }}"
    data-stock-status="{{ $stockStatus }}"
    data-desc="{{ $item['description'] ?? '' }}"
    data-url="{{ $item['url'] }}">
    <div class="ref-product-photo-wrap">
        <a class="ref-product-photo" href="{{ $item['url'] }}" aria-label="{{ $item['name'] }}">
            @if(!empty($item['image_path']))
                <img src="{{ $imageSrc }}" alt="{{ $item['name'] }}" loading="lazy">
            @else
                <div class="ref-no-image" aria-hidden="true">No Image</div>
            @endif
        </a>
        <button type="button" class="ref-quick-view-btn" data-quick-view="{{ $item['id'] }}" aria-label="Preview {{ $item['name'] }}">
            <i class="fa-solid fa-eye" aria-hidden="true"></i> Product Preview
        </button>
    </div>
    <div class="ref-product-info">
        <div class="ref-product-cat-tag">{{ $catName }}</div>
        <h3 class="ref-product-title"><a href="{{ $item['url'] }}">{{ $item['name'] }}</a></h3>
        <span class="ref-stock-pill {{ ! $inStock ? 'out-of-stock' : ($stockStatus === 'low_stock' ? 'low-stock' : 'in-stock') }}">
            <span class="ref-stock-dot"></span> {{ $stockLabel }}
        </span>
        <div class="ref-catalog-mrp"><span>MRP</span><strong>{{ $displayMrp }} BDT</strong></div>
        <a class="ref-product-details-link" href="{{ $item['url'] }}">View Product Details <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
    </div>
</article>
