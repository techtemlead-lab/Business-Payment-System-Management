<footer class="ref-footer"><div class="ref-container ref-footer-grid">
    <section>
        <h2>{{ $siteCustomization['footer_support_heading'] ?? 'SUPPORT' }}</h2>
        <a class="ref-support-box" href="{{ $siteCustomization['footer_support_link'] ?? 'tel:+8801730532966' }}">
            <i class="{{ $siteCustomization['footer_support_icon'] ?? 'fa-solid fa-phone' }}" aria-hidden="true"></i>
            <span><small>{{ $siteCustomization['footer_support_subtitle'] ?? 'Company support' }}</small><b>{{ $siteCustomization['footer_support_title'] ?? '+8801730532966' }}</b></span>
        </a>
        <a class="ref-support-box" href="{{ $siteCustomization['footer_directory_link'] ?? '/dealers' }}">
            <i class="{{ $siteCustomization['footer_directory_icon'] ?? 'fa-solid fa-location-dot' }}" aria-hidden="true"></i>
            <span><small>{{ $siteCustomization['footer_directory_subtitle'] ?? 'Find your local partner' }}</small><b>{{ $siteCustomization['footer_directory_title'] ?? 'Dealer Directory' }}</b></span>
        </a>
        @if(!empty($siteCustomization['footer_phone']) || !empty($siteCustomization['footer_email']) || !empty($siteCustomization['footer_address']))
        <div class="ref-footer-contact-info" style="margin-top:16px; font-size:12px; color:#cbd5e1; display:flex; flex-direction:column; gap:8px;">
            @if(!empty($siteCustomization['footer_phone']))
            <a href="tel:{{ preg_replace('/[^0-9+]/', '', $siteCustomization['footer_phone']) }}" style="color:#a7f3d0; display:inline-flex; align-items:center; gap:8px; text-decoration:none;">
                <i class="fa-solid fa-headset" style="color:#10b981; font-size:14px;"></i> <span><b>Hotline:</b> {{ $siteCustomization['footer_phone'] }}</span>
            </a>
            @endif
            @if(!empty($siteCustomization['footer_email']))
            <a href="mailto:{{ $siteCustomization['footer_email'] }}" style="color:#cbd5e1; display:inline-flex; align-items:center; gap:8px; text-decoration:none;">
                <i class="fa-solid fa-envelope" style="color:#38bdf8; font-size:14px;"></i> <span>{{ $siteCustomization['footer_email'] }}</span>
            </a>
            @endif
            @if(!empty($siteCustomization['footer_address']))
            <span style="color:#94a3b8; display:inline-flex; align-items:center; gap:8px;">
                <i class="fa-solid fa-map-pin" style="color:#f59e0b; font-size:14px;"></i> <span>{{ $siteCustomization['footer_address'] }}</span>
            </span>
            @endif
        </div>
        @endif
    </section>
    <section>
        <h2>{{ $siteCustomization['footer_about_heading'] ?? 'ABOUT US' }}</h2>
        <div class="ref-footer-links">
            <a href="{{ $siteCustomization['footer_link_about_url'] ?? '/about' }}">{{ $siteCustomization['footer_link_about'] ?? 'About Us' }}</a>
            <a href="{{ $siteCustomization['footer_link_vendor_url'] ?? '/contact' }}">{{ $siteCustomization['footer_link_vendor'] ?? 'Vendor Program' }}</a>
            <a href="{{ $siteCustomization['footer_link_orders_url'] ?? '/workspace/orders' }}">{{ $siteCustomization['footer_link_orders'] ?? 'Order Tracking' }}</a>
            <a href="{{ $siteCustomization['footer_link_blog_url'] ?? '/about' }}">{{ $siteCustomization['footer_link_blog'] ?? 'Blog & Tips' }}</a>
            <a href="{{ $siteCustomization['footer_link_contact_url'] ?? '/contact' }}">{{ $siteCustomization['footer_link_contact'] ?? 'Contact Us' }}</a>
            <a href="{{ $siteCustomization['footer_link_dealer_apply_url'] ?? '/contact' }}">{{ $siteCustomization['footer_link_dealer_apply'] ?? 'Apply for Dealership' }}</a>
        </div>
    </section>
    <section>
        <h2>{{ $siteCustomization['footer_stay_connected_heading'] ?? 'STAY CONNECTED' }}</h2>
        <div style="margin-bottom:14px;"><a href="/" aria-label="Madina Natural Health Care home"><img src="{{ $siteCustomization['footer_logo_url'] ?? '/madina-header-logo-white.png?v=6' }}" alt="Madina Natural Health Care" style="height:56px;width:auto;max-width:200px;background:transparent;padding:0;border-radius:0;box-shadow:none;object-fit:contain;display:inline-block;"></a></div>
        <h3>{{ $siteCustomization['footer_about_title'] ?? 'Madina Natural Health Care' }}</h3>
        <p>{{ $siteCustomization['footer_about_desc'] ?? 'Products, partners and communities across Bangladesh.' }}</p>
        <div class="ref-socials">
            @php
                $socialMap = [
                    'facebook' => $siteCustomization['social_facebook'] ?? '#',
                    'youtube' => $siteCustomization['social_youtube'] ?? '#',
                    'linkedin' => $siteCustomization['social_linkedin'] ?? '#',
                    'instagram' => $siteCustomization['social_instagram'] ?? '#',
                    'whatsapp' => $siteCustomization['social_whatsapp'] ?? 'https://wa.me/8801730532966',
                ];
            @endphp
            @foreach($socialMap as $social => $link)
            @php
                $href = $link ?: '#';
                if ($social === 'whatsapp' && $href !== '#' && !str_starts_with($href, 'http')) {
                    $cleaned = preg_replace('/[^0-9]/', '', $href);
                    $href = $cleaned ? 'https://wa.me/' . (str_starts_with($cleaned, '01') ? '88' . $cleaned : $cleaned) : '#';
                }
            @endphp
            <a href="{{ $href }}" target="_blank" rel="noopener noreferrer" aria-label="{{ ucfirst($social) }}"><img src="/reference/icons/{{ $social }}.png" alt="{{ ucfirst($social) }}"></a>
            @endforeach
            @if(!empty($siteCustomization['social_twitter']))
            <a href="{{ $siteCustomization['social_twitter'] }}" target="_blank" rel="noopener noreferrer" aria-label="X / Twitter" class="ref-social-fa" style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:50%;background:#0f172a;color:#fff;font-size:14px;"><i class="fa-brands fa-x-twitter"></i></a>
            @endif
            @if(!empty($siteCustomization['social_tiktok']))
            <a href="{{ $siteCustomization['social_tiktok'] }}" target="_blank" rel="noopener noreferrer" aria-label="TikTok" class="ref-social-fa" style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:50%;background:#000;color:#fff;font-size:14px;"><i class="fa-brands fa-tiktok"></i></a>
            @endif
            @if(!empty($siteCustomization['social_telegram']))
            <a href="{{ $siteCustomization['social_telegram'] }}" target="_blank" rel="noopener noreferrer" aria-label="Telegram" class="ref-social-fa" style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:50%;background:#229ed9;color:#fff;font-size:14px;"><i class="fa-brands fa-telegram"></i></a>
            @endif
        </div>
        @if(($siteCustomization['app_badges_enabled'] ?? '1') === '1')
        <div class="ref-app-badges">
            <a href="{{ $siteCustomization['app_google_play_link'] ?? '#' }}" target="_blank" rel="noopener noreferrer" aria-label="Get it on Google Play">
                <img src="/reference/icons/google-play.png" alt="Google Play">
            </a>
            <a href="{{ $siteCustomization['app_apple_store_link'] ?? '#' }}" target="_blank" rel="noopener noreferrer" aria-label="Download on App Store">
                <img src="/reference/icons/app-store.png" alt="App Store">
            </a>
        </div>
        @endif
    </section>
</div>
<div class="ref-container ref-footer-bottom">
    <span>{{ $siteCustomization['copyright_text'] ?? ('© ' . date('Y') . ' Madina Natural Health Care. All rights reserved.') }}</span>
    @if(($siteCustomization['payment_methods_enabled'] ?? '1') === '1')
    <img src="{{ $siteCustomization['payment_methods_image'] ?? '/reference/icons/payment_logo.png' }}" alt="{{ $siteCustomization['payment_methods_alt'] ?? 'Payment methods' }}">
    @endif
</div>
</footer>
<aside class="ref-floating-tools" aria-label="Shopping tools">
    <button data-open-compare><i class="fa-solid fa-shuffle" aria-hidden="true"></i><b>{{ $siteCustomization['floating_compare_label'] ?? 'COMPARE' }}</b><span data-compare-count>0</span></button>
    <button data-open-cart><i class="fa-solid fa-basket-shopping" aria-hidden="true"></i><b>{{ $siteCustomization['floating_cart_label'] ?? 'CART' }}</b><span data-cart-count>0</span></button>
    <a href="{{ $siteCustomization['whatsapp_link'] ?? '/contact' }}" class="ref-chat" aria-label="Customer support chat"><i class="fa-brands fa-whatsapp" aria-hidden="true"></i><b>{{ $siteCustomization['floating_chat_label'] ?? 'CHAT' }}</b></a>
</aside>
<nav class="ref-mobile-nav" aria-label="Mobile navigation">
    <a href="/products" class="ref-mobile-nav-item">
        <i class="fa-solid fa-store" aria-hidden="true"></i>
        <span>Shop</span>
    </a>
    <a href="/#featured-products" class="ref-mobile-nav-item">
        <i class="fa-solid fa-gift" aria-hidden="true"></i>
        <span>Offers</span>
    </a>
    <a href="/" class="ref-mobile-home" aria-label="Home">
        <span class="ref-mobile-home-icon"><i class="fa-solid fa-house" aria-hidden="true"></i></span>
        <span class="ref-mobile-home-label">Home</span>
    </a>
    <button type="button" class="ref-mobile-nav-item" id="refMobileBottomCategories" aria-label="Product categories">
        <i class="fa-solid fa-table-cells-large" aria-hidden="true"></i>
        <span>Categories</span>
    </button>
    @auth
    <a href="{{ auth()->user()->staff() ? '/company' : '/workspace' }}" class="ref-mobile-nav-item">
        <i class="fa-solid fa-user-check" aria-hidden="true"></i>
        <span>Account</span>
    </a>
    @else
    <a href="/login" class="ref-mobile-nav-item">
        <i class="fa-solid fa-user" aria-hidden="true"></i>
        <span>Account</span>
    </a>
    @endauth
</nav>
<button class="ref-back-top" aria-label="Back to top"><i class="fa-solid fa-arrow-up" aria-hidden="true"></i></button><div class="ref-toast" role="status" aria-live="polite"></div>
<!-- Global Quick View Modal Dialog -->
<dialog class="ref-quickview-dialog" id="refQuickViewDialog" aria-labelledby="qvTitle">
    <div class="ref-qv-modal-inner">
        <button type="button" class="ref-qv-close-btn" id="refQvCloseBtn" aria-label="Close preview">
            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
        </button>
        <div class="ref-quickview-grid">
            <div class="ref-qv-image-col">
                <img id="qvImage" src="" alt="Product Image" loading="lazy">
                <span class="ref-qv-badge" id="qvBadge" style="display: none;"></span>
            </div>
            <div class="ref-qv-info-col">
                <div class="ref-qv-cat" id="qvCategory"></div>
                <h2 class="ref-qv-title" id="qvTitle"></h2>
                <div class="ref-qv-stock-row" id="qvStockRow"></div>
                <div class="ref-qv-price-row">
                    <strong class="ref-qv-price" id="qvPrice"></strong>
                    <del class="ref-qv-mrp" id="qvMrp"></del>
                    <span class="ref-save-pill" id="qvSave" style="display: none;"></span>
                </div>
                <p class="ref-qv-desc" id="qvDescription"></p>
                <div class="ref-qv-actions">
                    <button type="button" class="ref-primary-button ref-qv-add-cart" id="qvAddCart">
                        <i class="fa-solid fa-cart-plus" aria-hidden="true"></i> {{ $siteCustomization['product_add_to_cart_btn'] ?? 'Add to Cart' }}
                    </button>
                    <a href="#" class="ref-secondary-button ref-qv-view-details" id="qvViewDetails">
                        View Full Details <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>
</dialog>
<dialog class="ref-cart-dialog"><div class="ref-dialog-title"><h2>{{ $siteCustomization['cart_dialog_title'] ?? 'Your Cart' }}</h2><button data-close-cart aria-label="Close cart"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div><div class="ref-cart-items"></div><a href="/login" class="ref-cart-login">{{ $siteCustomization['cart_dialog_btn'] ?? 'Sign in to request products' }}</a><p>{{ $siteCustomization['cart_dialog_notice'] ?? 'Product requests and payments are reviewed by company staff.' }}</p></dialog>
<dialog class="ref-compare-dialog"><div class="ref-dialog-title"><h2>{{ $siteCustomization['compare_dialog_title'] ?? 'Product Comparison' }}</h2><button data-close-compare aria-label="Close comparison"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div><p>{{ $siteCustomization['compare_dialog_desc'] ?? 'Select products to compare.' }}</p><div class="ref-compare-selects">@for($slot=0;$slot<3;$slot++)<label>Product {{ $slot+1 }}<select data-compare-slot="{{ $slot }}"><option value="">Select product</option>@foreach($referenceProducts as $product)<option value="{{ $product['id'] }}" data-name="{{ $product['name'] }}" data-price="{{ $product['price_display'] }}" data-stock="{{ $product['stock_display'] }}" data-image="{{ $product['image_path'] }}">{{ $product['name'] }}</option>@endforeach</select></label>@endfor</div><div class="ref-compare-results"></div></dialog>
<dialog class="ref-promotion-dialog" aria-label="Company promotion"><button data-close-promotion aria-label="Close promotion"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button><a href="/products"><img src="/branding/madina-promotion-popup.jpg?v=20261015" alt="Madina Natural Health Care & Physiotherapy Center Promotion"></a></dialog>
