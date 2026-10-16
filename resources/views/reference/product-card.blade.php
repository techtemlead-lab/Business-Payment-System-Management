@php
    $catName = $item['category'] ?? 'General';
    $catSlug = $item['category_slug'] ?? \Illuminate\Support\Str::slug($catName ?: 'general');
    $imageSrc = !empty($item['image_path']) ? $item['image_path'] : '/madina-header-logo.png?v=6';
    $hasCustomImage = !empty($item['image_path']);

    $stock = (int)($item['stock'] ?? 0);
    $stockStatus = $item['stock_status'] ?? ($stock > 0 ? 'in_stock' : 'out_of_stock');
    $stockBadge = $item['stock_badge'] ?? '';

    $discountPct = (int)($item['discount_pct'] ?? 0);
    $savingsText = $item['savings_text'] ?? ($item['campaign']['save'] ?? '');

    if ($discountPct === 0 && !empty($item['mrp']) && !empty($item['price']) && $item['mrp'] !== $item['price']) {
        $numPrice = (float)str_replace(',', '', preg_replace('/[^\d.]/', '', (string)$item['price']));
        $numMrp = (float)str_replace(',', '', preg_replace('/[^\d.]/', '', (string)$item['mrp']));
        if ($numMrp > $numPrice && $numMrp > 0) {
            $discountPct = (int)round((($numMrp - $numPrice) / $numMrp) * 100);
            if (empty($savingsText)) {
                $savingsText = 'Save ' . number_format($numMrp - $numPrice) . ' Tk';
            }
        }
    }

    $marketingBadge = trim((string)($item['marketing_badge'] ?? ($item['campaign']['tag'] ?? '')));
    if ($marketingBadge === '' && !empty($item['featured'])) {
        $marketingBadge = 'Featured';
    }

    // Top Badge Priority: Discount > Marketing > Out of Stock
    $topBadgeText = '';
    $topBadgeClass = '';
    if ($discountPct > 0) {
        $topBadgeText = '-' . $discountPct . '% OFF';
        $topBadgeClass = 'discount';
    } elseif (!empty($marketingBadge)) {
        $topBadgeText = $marketingBadge;
        $topBadgeClass = 'marketing';
    } elseif ($stock <= 0 || $stockStatus === 'out_of_stock') {
        $topBadgeText = $siteCustomization['product_out_of_stock_label'] ?? 'Out of Stock';
        $topBadgeClass = 'out-of-stock';
    }

    // Card Body Stock Status (In Stock / Out of Stock / Low Stock)
    $displayStockText = '';
    $displayStockClass = '';
    if ($stock <= 0 || $stockStatus === 'out_of_stock') {
        $displayStockText = $siteCustomization['product_out_of_stock_label'] ?? 'Out of Stock';
        $displayStockClass = 'out-of-stock';
    } elseif (!empty($stockBadge)) {
        $displayStockText = $stockBadge;
        $displayStockClass = ($stockStatus === 'low_stock') ? 'low-stock' : 'in-stock';
    } else {
        $displayStockText = $siteCustomization['product_in_stock_label'] ?? 'In Stock';
        $displayStockClass = 'in-stock';
    }
@endphp

<article class="ref-product-card"
    data-category="{{ $catSlug }}"
    data-id="{{ $item['id'] }}"
    data-name="{{ $item['name'] }}"
    data-price="{{ $item['price'] }} Tk"
    data-mrp="{{ $item['mrp'] }} Tk"
    data-image="{{ $imageSrc }}"
    data-category-name="{{ $catName }}"
    data-stock-status="{{ $stockStatus }}"
    data-stock-count="{{ $stock }}"
    data-stock-badge="{{ $stockBadge }}"
    data-marketing-badge="{{ $marketingBadge }}"
    data-discount-pct="{{ $discountPct }}"
    data-savings-text="{{ $savingsText }}"
    data-desc="{{ e($item['description'] ?? '') }}"
    data-url="{{ $item['url'] }}">

    @if(!empty($topBadgeText))
        <div class="ref-card-badge-wrap">
            <span class="ref-card-badge {{ $topBadgeClass }}">{{ $topBadgeText }}</span>
        </div>
    @endif

    <button type="button" class="ref-wishlist" data-favorite="{{ $item['id'] }}" aria-label="Save {{ $item['name'] }}" aria-pressed="false">
        <i class="fa-regular fa-heart" aria-hidden="true"></i>
    </button>

    <div class="ref-product-photo-wrap">
        <a class="ref-product-photo" href="{{ $item['url'] }}" aria-label="{{ $item['name'] }}">
            @if($hasCustomImage)
                <img src="{{ $imageSrc }}" alt="{{ $item['name'] }}" loading="lazy">
            @else
                <div class="ref-no-image" aria-hidden="true">No Image</div>
            @endif
        </a>
        <button type="button" class="ref-quick-view-btn" data-quick-view="{{ $item['id'] }}" aria-label="Quick View {{ $item['name'] }}">
            <i class="fa-solid fa-eye" aria-hidden="true"></i> Quick View
        </button>
    </div>

    <div class="ref-product-info">
        <div class="ref-product-cat-tag">{{ $catName }}</div>
        <h3 class="ref-product-title"><a href="{{ $item['url'] }}">{{ $item['name'] }}</a></h3>

        <div class="ref-rating-row">
            <div class="ref-rating-stars">
                <span class="ref-stars">
                    <i class="fa-solid fa-star"></i>
                    <i class="fa-solid fa-star"></i>
                    <i class="fa-solid fa-star"></i>
                    <i class="fa-solid fa-star"></i>
                    <i class="fa-solid fa-star-half-stroke"></i>
                </span>
                <span class="ref-rating-num">4.9</span>
            </div>
            <span class="ref-stock-pill {{ $displayStockClass }}">
                @if(str_contains($displayStockText, '⚡'))
                    {{ $displayStockText }}
                @else
                    <span class="ref-stock-dot"></span> {{ $displayStockText }}
                @endif
            </span>
        </div>

        @php
            $rawPrice = (float)str_replace(',', '', preg_replace('/[^\d.]/', '', (string)$item['price']));
            $rawMrp = !empty($item['mrp']) ? (float)str_replace(',', '', preg_replace('/[^\d.]/', '', (string)$item['mrp'])) : 0;
            $dispPrice = number_format($rawPrice, ($rawPrice == (int)$rawPrice) ? 0 : 2);
            $dispMrp = ($rawMrp > 0) ? number_format($rawMrp, ($rawMrp == (int)$rawMrp) ? 0 : 2) : '';
        @endphp
        <div class="ref-price-row">
            <div class="ref-price-main">
                <span class="ref-price-current">৳{{ $dispPrice }}</span>
                @if($rawMrp > $rawPrice)
                    <del class="ref-price-mrp">৳{{ $dispMrp }}</del>
                @endif
            </div>
            @if(!empty($savingsText))
                <span class="ref-save-tag">{{ $savingsText }}</span>
            @endif
        </div>

        <div class="ref-card-actions">
            <button type="button" class="ref-add-cart" data-cart="{{ $item['id'] }}" data-name="{{ $item['name'] }}" data-price="{{ $item['price'] }} Tk" data-image="{{ $imageSrc }}">
                <i class="fa-solid fa-cart-plus" aria-hidden="true"></i> {{ $siteCustomization['product_add_to_cart_btn'] ?? 'Add to Cart' }}
            </button>
            <a class="ref-buy-now" href="/login" data-buy-now="{{ $item['id'] }}" data-name="{{ $item['name'] }}" data-price="{{ $item['price'] }} Tk" data-image="{{ $imageSrc }}">
                <i class="fa-solid fa-bolt" aria-hidden="true"></i> {{ $siteCustomization['product_buy_now_btn'] ?? 'Buy Now' }}
            </a>
        </div>
    </div>
</article>
