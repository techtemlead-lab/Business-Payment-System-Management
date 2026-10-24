<footer class="ref-footer"><div class="ref-container ref-footer-grid">
    <section>
        <h2>{{ $siteCustomization['footer_support_heading'] ?? 'SUPPORT' }}</h2>
        <a class="ref-support-box" href="{{ $siteCustomization['footer_support_link'] ?? 'tel:+8801730532966' }}">
            <i class="{{ $siteCustomization['footer_support_icon'] ?? 'fa-solid fa-phone' }}" aria-hidden="true"></i>
            <span><small>{{ $siteCustomization['footer_support_subtitle'] ?? 'Company support' }}</small><b>{{ $siteCustomization['footer_support_title'] ?? '+8801730532966' }}</b></span>
        </a>
        @if($href = \App\Support\SiteCustomization::publicHref($siteCustomization['footer_directory_link'] ?? '/dealers'))
        <a class="ref-support-box" href="{{ $href }}">
            <i class="{{ $siteCustomization['footer_directory_icon'] ?? 'fa-solid fa-location-dot' }}" aria-hidden="true"></i>
            <span><small>{{ $siteCustomization['footer_directory_subtitle'] ?? 'Find your local partner' }}</small><b>{{ $siteCustomization['footer_directory_title'] ?? 'Dealer Directory' }}</b></span>
        </a>
        @endif
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
            @if($href = \App\Support\SiteCustomization::publicHref($siteCustomization['footer_link_about_url'] ?? '/about'))
            <a href="{{ $href }}">{{ $siteCustomization['footer_link_about'] ?? 'About Us' }}</a>
            @endif
            @if($publicPageEnabled['products'] ?? true)<a href="/products">Our Products</a>@endif
            @if($href = \App\Support\SiteCustomization::publicHref($siteCustomization['footer_link_services_url'] ?? '/services'))
            <a href="{{ $href }}">{{ $siteCustomization['footer_link_services'] ?? 'Our Services' }}</a>
            @endif
            @if($href = \App\Support\SiteCustomization::publicHref($siteCustomization['footer_link_quality_url'] ?? '/quality'))
            <a href="{{ $href }}">{{ $siteCustomization['footer_link_quality'] ?? 'Quality Promise' }}</a>
            @endif
            @if($publicPageEnabled['dealers'] ?? true)<a href="/dealers">Dealer Network</a>@endif
            @if($href = \App\Support\SiteCustomization::publicHref($siteCustomization['footer_link_partnership_url'] ?? '/partnership'))
            <a href="{{ $href }}">{{ $siteCustomization['footer_link_partnership'] ?? 'Become a Partner' }}</a>
            @endif
            <a href="{{ \App\Support\PortalHost::url('dealer', '/login') }}">Dealer Login</a>
            <a href="{{ \App\Support\PortalHost::url('subdealer', '/login') }}">Sub-dealer Login</a>
            @if($href = \App\Support\SiteCustomization::publicHref($siteCustomization['footer_link_contact_url'] ?? '/contact'))
            <a href="{{ $href }}">{{ $siteCustomization['footer_link_contact'] ?? 'Contact Us' }}</a>
            @endif
            @if($href = \App\Support\SiteCustomization::publicHref($siteCustomization['footer_link_faq_url'] ?? '/faq'))
            <a href="{{ $href }}">{{ $siteCustomization['footer_link_faq'] ?? 'FAQ' }}</a>
            @endif
            <a href="/notice">{{ $siteCustomization['footer_link_notice'] ?? 'Company Notice' }}</a>
            @if($href = \App\Support\SiteCustomization::publicHref($siteCustomization['footer_link_dealer_apply_url'] ?? '/partnership'))
            <a href="{{ $href }}">{{ $siteCustomization['footer_link_dealer_apply'] ?? 'Apply for Dealership' }}</a>
            @endif
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
            @if($href !== '#')
            <a href="{{ $href }}" target="_blank" rel="noopener noreferrer" aria-label="{{ ucfirst($social) }}"><img src="/reference/icons/{{ $social }}.png" alt="{{ ucfirst($social) }}"></a>
            @endif
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
        @php
            $appLinks = collect([
                ['url' => $siteCustomization['app_google_play_link'] ?? '', 'name' => 'Google Play', 'image' => 'google-play.png'],
                ['url' => $siteCustomization['app_apple_store_link'] ?? '', 'name' => 'App Store', 'image' => 'app-store.png'],
            ])->filter(fn ($app) => str_starts_with($app['url'], 'https://'));
        @endphp
        @if(($siteCustomization['app_badges_enabled'] ?? '1') === '1' && $appLinks->isNotEmpty())
            <div class="ref-app-badges">
                @foreach($appLinks as $app)
                    <a href="{{ $app['url'] }}" target="_blank" rel="noopener noreferrer" aria-label="{{ $app['name'] }}"><img src="/reference/icons/{{ $app['image'] }}" alt="{{ $app['name'] }}"></a>
                @endforeach
            </div>
        @endif


    </section>
</div>
<div class="ref-container ref-footer-bottom">
    <span>{{ $siteCustomization['copyright_text'] ?? ('© ' . date('Y') . ' Madina Natural Health Care. All rights reserved.') }}</span>
    <nav class="ref-footer-legal" aria-label="Legal">
        @if($href = \App\Support\SiteCustomization::publicHref($siteCustomization['footer_link_privacy_url'] ?? '/privacy'))
        <a href="{{ $href }}">{{ $siteCustomization['footer_link_privacy'] ?? 'Privacy Policy' }}</a>
        @endif
        @if($href = \App\Support\SiteCustomization::publicHref($siteCustomization['footer_link_terms_url'] ?? '/terms'))
        <a href="{{ $href }}">{{ $siteCustomization['footer_link_terms'] ?? 'Terms of Use' }}</a>
        @endif
        @if($href = \App\Support\SiteCustomization::publicHref($siteCustomization['footer_link_faq_url'] ?? '/faq'))
        <a href="{{ $href }}">{{ $siteCustomization['footer_link_faq'] ?? 'FAQ' }}</a>
        @endif
    </nav>
</div>
</footer>
<aside class="ref-floating-tools" aria-label="Company support">
    <a href="{{ $siteCustomization['whatsapp_link'] ?? '/contact' }}" class="ref-chat" aria-label="Customer support chat"><i class="fa-brands fa-whatsapp" aria-hidden="true"></i><b>{{ $siteCustomization['floating_chat_label'] ?? 'CHAT' }}</b></a>
</aside>
<nav class="ref-mobile-nav" aria-label="Mobile navigation">
    @if($publicPageEnabled['products'] ?? true)
    <a href="/products" class="ref-mobile-nav-item">
        <i class="fa-solid fa-store" aria-hidden="true"></i>
        <span>Products</span>
    </a>
    @elseif($publicPageEnabled['services'] ?? true)
    <a href="/services" class="ref-mobile-nav-item">
        <i class="fa-solid fa-hand-holding-heart" aria-hidden="true"></i>
        <span>Services</span>
    </a>
    @elseif($publicPageEnabled['about'] ?? true)
    <a href="/about" class="ref-mobile-nav-item">
        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
        <span>About</span>
    </a>
    @endif
    @if($publicPageEnabled['dealers'] ?? true)
    <a href="/dealers" class="ref-mobile-nav-item">
        <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
        <span>Dealers</span>
    </a>
    @elseif($publicPageEnabled['partnership'] ?? true)
    <a href="/partnership" class="ref-mobile-nav-item">
        <i class="fa-solid fa-handshake" aria-hidden="true"></i>
        <span>Partner</span>
    </a>
    @elseif($publicPageEnabled['contact'] ?? true)
    <a href="/contact" class="ref-mobile-nav-item">
        <i class="fa-solid fa-headset" aria-hidden="true"></i>
        <span>Contact</span>
    </a>
    @endif
    <a href="/" class="ref-mobile-home" aria-label="Home">
        <span class="ref-mobile-home-icon"><i class="fa-solid fa-house" aria-hidden="true"></i></span>
        <span class="ref-mobile-home-label">Home</span>
    </a>
    @if($publicPageEnabled['about'] ?? true)
    <a href="/about" class="ref-mobile-nav-item">
        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
        <span>About</span>
    </a>
    @elseif($publicPageEnabled['faq'] ?? true)
    <a href="/faq" class="ref-mobile-nav-item">
        <i class="fa-solid fa-circle-question" aria-hidden="true"></i>
        <span>FAQ</span>
    </a>
    @elseif($publicPageEnabled['contact'] ?? true)
    <a href="/contact" class="ref-mobile-nav-item">
        <i class="fa-solid fa-headset" aria-hidden="true"></i>
        <span>Contact</span>
    </a>
    @endif
    @auth
    <a href="{{ \App\Support\PortalHost::homeUrl(auth()->user()) }}" class="ref-mobile-nav-item">
        <i class="fa-solid fa-user-check" aria-hidden="true"></i>
        <span>Account</span>
    </a>
    @else
    <a href="{{ \App\Support\PortalHost::url('dealer', '/login') }}" class="ref-mobile-nav-item">
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

            </div>
            <div class="ref-qv-info-col">
                <div class="ref-qv-cat" id="qvCategory"></div>
                <h2 class="ref-qv-title" id="qvTitle"></h2>
                <div class="ref-qv-stock-row" id="qvStockRow"></div>
                <div class="ref-qv-price-row">
                    <span>MRP</span><strong class="ref-qv-price" id="qvMrp"></strong>
                </div>
                <p class="ref-qv-desc" id="qvDescription"></p>
                <div class="ref-qv-actions">
                    @if($publicPageEnabled['dealers'] ?? true)
                    <a href="/dealers" class="ref-primary-button">Find a Dealer</a>
                    @elseif($publicPageEnabled['contact'] ?? true)
                    <a href="/contact" class="ref-primary-button">Contact Us</a>
                    @endif
                    <a href="#" class="ref-secondary-button ref-qv-view-details" id="qvViewDetails">
                        View Full Details <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>
</dialog>
