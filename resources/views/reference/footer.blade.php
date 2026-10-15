<footer class="ref-footer"><div class="ref-container ref-footer-grid">
    <section>
        <h2>{{ $siteCustomization['footer_support_heading'] ?? 'SUPPORT' }}</h2>
        <a class="ref-support-box" href="{{ $siteCustomization['footer_support_link'] ?? '/contact' }}">
            <i class="fa-solid fa-phone" aria-hidden="true"></i>
            <span><small>{{ $siteCustomization['footer_support_subtitle'] ?? 'Company support' }}</small><b>{{ $siteCustomization['footer_support_title'] ?? 'Contact Us' }}</b></span>
        </a>
        <a class="ref-support-box" href="{{ $siteCustomization['footer_directory_link'] ?? '/dealers' }}">
            <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
            <span><small>{{ $siteCustomization['footer_directory_subtitle'] ?? 'Find your local partner' }}</small><b>{{ $siteCustomization['footer_directory_title'] ?? 'Dealer Directory' }}</b></span>
        </a>
    </section>
    <section>
        <h2>{{ $siteCustomization['footer_about_heading'] ?? 'ABOUT US' }}</h2>
        <div class="ref-footer-links"><a href="/about">{{ $siteCustomization['footer_link_about'] ?? 'About Us' }}</a><a href="/contact">{{ $siteCustomization['footer_link_vendor'] ?? 'Vendor Program' }}</a><a href="/workspace/orders">{{ $siteCustomization['footer_link_orders'] ?? 'Order Tracking' }}</a><a href="/about">{{ $siteCustomization['footer_link_blog'] ?? 'Blog & Tips' }}</a><a href="/contact">{{ $siteCustomization['footer_link_contact'] ?? 'Contact Us' }}</a><a href="/contact">{{ $siteCustomization['footer_link_dealer_apply'] ?? 'Apply for Dealership' }}</a></div>
    </section>
    <section>
        <h2>{{ $siteCustomization['footer_stay_connected_heading'] ?? 'STAY CONNECTED' }}</h2>
        <div style="margin-bottom:14px;"><a href="/" aria-label="Madina Natural Health Care home"><img src="/madina-header-logo.png?v=6" alt="Madina Natural Health Care" style="height:56px;width:56px;background:transparent;padding:0;border-radius:0;box-shadow:none;object-fit:contain;display:inline-block;"></a></div>
        <h3>{{ $siteCustomization['footer_about_title'] ?? 'Madina Natural Health Care' }}</h3>
        <p>{{ $siteCustomization['footer_about_desc'] ?? 'Products, partners and communities across Bangladesh.' }}</p>
        <div class="ref-socials">
            @php
                $socialMap = [
                    'facebook' => $siteCustomization['social_facebook'] ?? '/contact',
                    'youtube' => $siteCustomization['social_youtube'] ?? '/contact',
                    'linkedin' => $siteCustomization['social_linkedin'] ?? '/contact',
                    'instagram' => $siteCustomization['social_instagram'] ?? '/contact',
                    'whatsapp' => $siteCustomization['social_whatsapp'] ?? '/contact',
                ];
            @endphp
            @foreach($socialMap as $social => $link)
            <a href="{{ $link ?: '/contact' }}" aria-label="{{ ucfirst($social) }}"><img src="/reference/icons/{{ $social }}.png" alt=""></a>
            @endforeach
        </div>
        <div class="ref-app-badges"><img src="/reference/icons/google-play.png" alt="Google Play"><img src="/reference/icons/app-store.png" alt="App Store"></div>
    </section>
</div>
<div class="ref-container ref-footer-bottom">
    <span>{{ $siteCustomization['copyright_text'] ?? ('© ' . date('Y') . ' Madina Natural Health Care. All rights reserved.') }}</span>
    <img src="/reference/icons/payment_logo.png" alt="Payment methods">
</div>
</footer>
<aside class="ref-floating-tools" aria-label="Shopping tools">
    <button data-open-compare><i class="fa-solid fa-shuffle" aria-hidden="true"></i><b>{{ $siteCustomization['floating_compare_label'] ?? 'COMPARE' }}</b><span data-compare-count>0</span></button>
    <button data-open-cart><i class="fa-solid fa-basket-shopping" aria-hidden="true"></i><b>{{ $siteCustomization['floating_cart_label'] ?? 'CART' }}</b><span data-cart-count>0</span></button>
    <a href="{{ $siteCustomization['whatsapp_link'] ?? '/contact' }}" class="ref-chat" aria-label="Customer support chat"><i class="fa-brands fa-whatsapp" aria-hidden="true"></i><b>{{ $siteCustomization['floating_chat_label'] ?? 'CHAT' }}</b></a>
</aside>
<nav class="ref-mobile-nav" aria-label="Mobile navigation"><a href="/products"><i class="fa-solid fa-bars" aria-hidden="true"></i>Categories</a><a href="/products"><i class="fa-solid fa-store" aria-hidden="true"></i>Shop</a><a href="/" class="ref-mobile-home"><i class="fa-solid fa-house" aria-hidden="true"></i>Home</a><button data-open-cart><i class="fa-solid fa-cart-shopping" aria-hidden="true"></i>Cart <span data-cart-count>0</span></button><a href="/login"><i class="fa-solid fa-user" aria-hidden="true"></i>Account</a></nav>
<button class="ref-back-top" aria-label="Back to top"><i class="fa-solid fa-arrow-up" aria-hidden="true"></i></button><div class="ref-toast" role="status" aria-live="polite"></div>
<dialog class="ref-cart-dialog"><div class="ref-dialog-title"><h2>{{ $siteCustomization['cart_dialog_title'] ?? 'Your Cart' }}</h2><button data-close-cart aria-label="Close cart"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div><div class="ref-cart-items"></div><a href="/login" class="ref-cart-login">{{ $siteCustomization['cart_dialog_btn'] ?? 'Sign in to request products' }}</a><p>{{ $siteCustomization['cart_dialog_notice'] ?? 'Product requests and payments are reviewed by company staff.' }}</p></dialog>
<dialog class="ref-compare-dialog"><div class="ref-dialog-title"><h2>{{ $siteCustomization['compare_dialog_title'] ?? 'Product Comparison' }}</h2><button data-close-compare aria-label="Close comparison"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div><p>{{ $siteCustomization['compare_dialog_desc'] ?? 'Select products to compare.' }}</p><div class="ref-compare-selects">@for($slot=0;$slot<3;$slot++)<label>Product {{ $slot+1 }}<select data-compare-slot="{{ $slot }}"><option value="">Select product</option>@foreach($referenceProducts as $product)<option value="{{ $product['id'] }}" data-name="{{ $product['name'] }}" data-price="{{ $product['price_display'] }}" data-stock="{{ $product['stock_display'] }}" data-image="{{ $product['image_path'] }}">{{ $product['name'] }}</option>@endforeach</select></label>@endfor</div><div class="ref-compare-results"></div></dialog>
<dialog class="ref-promotion-dialog" aria-label="Company promotion"><button data-close-promotion aria-label="Close promotion"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button><a href="/products"><img src="/reference/promotion.png" alt="Company promotion"></a></dialog>
