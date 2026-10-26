@php
    $chrome = fn (string $key, ?string $custom = null): string => \App\Support\SiteChrome::text($key, $custom);
@endphp
<footer class="ref-footer"><div class="ref-container ref-footer-grid">
    <section>
        <h2>{{ $chrome('footer_support', $siteCustomization['footer_support_heading'] ?? null) }}</h2>
        <a class="ref-support-box" href="{{ $siteCustomization['footer_support_link'] ?? 'tel:+8801730532966' }}">
            <i class="{{ $siteCustomization['footer_support_icon'] ?? 'fa-solid fa-phone' }}" aria-hidden="true"></i>
            <span><small>{{ $chrome('company_support', $siteCustomization['footer_support_subtitle'] ?? null) }}</small><b>{{ $siteCustomization['footer_support_title'] ?? '+8801730532966' }}</b></span>
        </a>
        @if($href = \App\Support\SiteCustomization::publicHref($siteCustomization['footer_directory_link'] ?? '/dealers'))
        <a class="ref-support-box" href="{{ $href }}">
            <i class="{{ $siteCustomization['footer_directory_icon'] ?? 'fa-solid fa-location-dot' }}" aria-hidden="true"></i>
            <span><small>{{ $chrome('find_local_partner', $siteCustomization['footer_directory_subtitle'] ?? null) }}</small><b>{{ $chrome('dealer_directory', $siteCustomization['footer_directory_title'] ?? null) }}</b></span>
        </a>
        @endif
        @if(!empty($siteCustomization['footer_phone']) || !empty($siteCustomization['footer_email']) || !empty($siteCustomization['footer_address']))
        <div class="ref-footer-contact-info" style="margin-top:16px; font-size:12px; color:#cbd5e1; display:flex; flex-direction:column; gap:8px;">
            @if(!empty($siteCustomization['footer_phone']))
            <a href="tel:{{ preg_replace('/[^0-9+]/', '', $siteCustomization['footer_phone']) }}" style="color:#a7f3d0; display:inline-flex; align-items:center; gap:8px; text-decoration:none;">
                <i class="fa-solid fa-headset" style="color:#10b981; font-size:14px;"></i> <span><b>{{ __('site.hotline') }}:</b> {{ $siteCustomization['footer_phone'] }}</span>
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
        <h2>{{ $chrome('footer_about', $siteCustomization['footer_about_heading'] ?? null) }}</h2>
        <div class="ref-footer-links">
            @if($href = \App\Support\SiteCustomization::publicHref($siteCustomization['footer_link_about_url'] ?? '/about'))
            <a href="{{ $href }}">{{ $chrome('about', $siteCustomization['footer_link_about'] ?? null) }}</a>
            @endif
            @if($publicPageEnabled['products'] ?? true)<a href="/products">{{ $chrome('our_products') }}</a>@endif
            @if($href = \App\Support\SiteCustomization::publicHref($siteCustomization['footer_link_services_url'] ?? '/services'))
            <a href="{{ $href }}">{{ $chrome('our_services', $siteCustomization['footer_link_services'] ?? null) }}</a>
            @endif
            @if($href = \App\Support\SiteCustomization::publicHref($siteCustomization['footer_link_quality_url'] ?? '/quality'))
            <a href="{{ $href }}">{{ $chrome('quality_promise', $siteCustomization['footer_link_quality'] ?? null) }}</a>
            @endif
            @if($publicPageEnabled['dealers'] ?? true)<a href="/dealers">{{ $chrome('network') }}</a>@endif
            @if($href = \App\Support\SiteCustomization::publicHref($siteCustomization['footer_link_partnership_url'] ?? '/partnership'))
            <a href="{{ $href }}">{{ $chrome('become_a_partner', $siteCustomization['footer_link_partnership'] ?? null) }}</a>
            @endif
            <a href="{{ \App\Support\PortalHost::url('dealer', '/login') }}">{{ $chrome('dealer_login') }}</a>
            <a href="{{ \App\Support\PortalHost::url('subdealer', '/login') }}">{{ $chrome('subdealer_login') }}</a>
            @if($href = \App\Support\SiteCustomization::publicHref($siteCustomization['footer_link_contact_url'] ?? '/contact'))
            <a href="{{ $href }}">{{ $chrome('contact_us', $siteCustomization['footer_link_contact'] ?? null) }}</a>
            @endif
            @if($href = \App\Support\SiteCustomization::publicHref($siteCustomization['footer_link_faq_url'] ?? '/faq'))
            <a href="{{ $href }}">{{ $chrome('faq', $siteCustomization['footer_link_faq'] ?? null) }}</a>
            @endif
            <a href="/notice">{{ $chrome('company_notice', $siteCustomization['footer_link_notice'] ?? null) }}</a>
            @if($href = \App\Support\SiteCustomization::publicHref($siteCustomization['footer_link_dealer_apply_url'] ?? '/partnership'))
            <a href="{{ $href }}">{{ $chrome('apply_for_dealership', $siteCustomization['footer_link_dealer_apply'] ?? null) }}</a>
            @endif
        </div>
    </section>
    <section>
        <h2>{{ $chrome('footer_stay_connected', $siteCustomization['footer_stay_connected_heading'] ?? null) }}</h2>
        <div style="margin-bottom:14px;"><a href="/" aria-label="{{ __('site.brand_home') }}"><img src="{{ $siteCustomization['footer_logo_url'] ?? '/madina-header-logo-white.png?v=6' }}" alt="{{ __('site.company_bar') }}" style="height:56px;width:auto;max-width:200px;background:transparent;padding:0;border-radius:0;box-shadow:none;object-fit:contain;display:inline-block;"></a></div>
        <h3>{{ $chrome('company_intro_title', $siteCustomization['footer_about_title'] ?? null) }}</h3>
        <p>{{ $chrome('footer_about_desc', $siteCustomization['footer_about_desc'] ?? null) }}</p>
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
    <span>@if(app()->getLocale() === 'bn' || blank($siteCustomization['copyright_text'] ?? null)){{ __('site.copyright', ['year' => date('Y')]) }}@else{{ $siteCustomization['copyright_text'] }}@endif</span>
    <span>{{ __('site.timezone_currency') }}</span>
    <nav class="ref-footer-legal" aria-label="{{ __('site.legal_nav') }}">
        @if($href = \App\Support\SiteCustomization::publicHref($siteCustomization['footer_link_privacy_url'] ?? '/privacy'))
        <a href="{{ $href }}">{{ $chrome('privacy_policy', $siteCustomization['footer_link_privacy'] ?? null) }}</a>
        @endif
        @if($href = \App\Support\SiteCustomization::publicHref($siteCustomization['footer_link_terms_url'] ?? '/terms'))
        <a href="{{ $href }}">{{ $chrome('terms_of_use', $siteCustomization['footer_link_terms'] ?? null) }}</a>
        @endif
        @if($href = \App\Support\SiteCustomization::publicHref($siteCustomization['footer_link_faq_url'] ?? '/faq'))
        <a href="{{ $href }}">{{ $chrome('faq', $siteCustomization['footer_link_faq'] ?? null) }}</a>
        @endif
    </nav>
</div>
</footer>
<aside class="ref-floating-tools" aria-label="{{ $chrome('company_support_tools') }}">
    <a href="{{ $siteCustomization['whatsapp_link'] ?? '/contact' }}" class="ref-chat" aria-label="{{ $chrome('customer_support_chat') }}"><i class="fa-brands fa-whatsapp" aria-hidden="true"></i><b>{{ $chrome('floating_chat_label', $siteCustomization['floating_chat_label'] ?? null) }}</b></a>
</aside>
<nav class="ref-mobile-nav" aria-label="{{ __('site.mobile_navigation') }}">
    @if($publicPageEnabled['products'] ?? true)
    <a href="/products" class="ref-mobile-nav-item">
        <i class="fa-solid fa-store" aria-hidden="true"></i>
        <span>{{ __('site.products') }}</span>
    </a>
    @elseif($publicPageEnabled['services'] ?? true)
    <a href="/services" class="ref-mobile-nav-item">
        <i class="fa-solid fa-hand-holding-heart" aria-hidden="true"></i>
        <span>{{ __('site.services') }}</span>
    </a>
    @elseif($publicPageEnabled['about'] ?? true)
    <a href="/about" class="ref-mobile-nav-item">
        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
        <span>{{ __('site.about') }}</span>
    </a>
    @endif
    @if($publicPageEnabled['dealers'] ?? true)
    <a href="/dealers" class="ref-mobile-nav-item">
        <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
        <span>{{ __('site.dealers') }}</span>
    </a>
    @elseif($publicPageEnabled['partnership'] ?? true)
    <a href="/partnership" class="ref-mobile-nav-item">
        <i class="fa-solid fa-handshake" aria-hidden="true"></i>
        <span>{{ __('site.partner') }}</span>
    </a>
    @elseif($publicPageEnabled['contact'] ?? true)
    <a href="/contact" class="ref-mobile-nav-item">
        <i class="fa-solid fa-headset" aria-hidden="true"></i>
        <span>{{ __('site.contact') }}</span>
    </a>
    @endif
    <a href="/" class="ref-mobile-home" aria-label="{{ __('site.home') }}">
        <span class="ref-mobile-home-icon"><i class="fa-solid fa-house" aria-hidden="true"></i></span>
        <span class="ref-mobile-home-label">{{ __('site.home') }}</span>
    </a>
    @if($publicPageEnabled['about'] ?? true)
    <a href="/about" class="ref-mobile-nav-item">
        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
        <span>{{ __('site.about') }}</span>
    </a>
    @elseif($publicPageEnabled['faq'] ?? true)
    <a href="/faq" class="ref-mobile-nav-item">
        <i class="fa-solid fa-circle-question" aria-hidden="true"></i>
        <span>{{ __('site.faq') }}</span>
    </a>
    @elseif($publicPageEnabled['contact'] ?? true)
    <a href="/contact" class="ref-mobile-nav-item">
        <i class="fa-solid fa-headset" aria-hidden="true"></i>
        <span>{{ __('site.contact') }}</span>
    </a>
    @endif
    @auth
    <a href="{{ \App\Support\PortalHost::homeUrl(auth()->user()) }}" class="ref-mobile-nav-item">
        <i class="fa-solid fa-user-check" aria-hidden="true"></i>
        <span>{{ __('site.account') }}</span>
    </a>
    @else
    <a href="{{ \App\Support\PortalHost::url('dealer', '/login') }}" class="ref-mobile-nav-item">
        <i class="fa-solid fa-user" aria-hidden="true"></i>
        <span>{{ __('site.account') }}</span>
    </a>
    @endauth
</nav>
<button class="ref-back-top" aria-label="{{ __('site.back_to_top') }}"><i class="fa-solid fa-arrow-up" aria-hidden="true"></i></button><div class="ref-toast" role="status" aria-live="polite"></div>
@php
    $refSiteUi = [
        'in_stock' => __('site.in_stock'),
        'out_of_stock' => __('site.out_of_stock'),
        'low_stock' => __('site.low_stock'),
        'product_link_copied' => __('site.product_link_copied'),
        'product_link_copy_fallback' => __('site.product_link_copy_fallback'),
        'geolocation_unsupported' => __('site.geolocation_unsupported'),
        'detecting_location' => __('site.detecting_location'),
        'location_detected' => __('site.location_detected'),
        'location_denied' => __('site.location_denied'),
        'location_failed' => __('site.location_failed'),
        'product_image' => __('site.product_image'),
    ];
@endphp
<script type="application/json" id="ref-site-ui">{!! json_encode($refSiteUi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) !!}</script>
<!-- Global Quick View Modal Dialog -->
<dialog class="ref-quickview-dialog" id="refQuickViewDialog" aria-labelledby="qvTitle">
    <div class="ref-qv-modal-inner">
        <button type="button" class="ref-qv-close-btn" id="refQvCloseBtn" aria-label="{{ __('site.close_preview') }}">
            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
        </button>
        <div class="ref-quickview-grid">
            <div class="ref-qv-image-col">
                <img id="qvImage" src="" alt="{{ __('site.product_image') }}" loading="lazy">

            </div>
            <div class="ref-qv-info-col">
                <div class="ref-qv-cat" id="qvCategory"></div>
                <h2 class="ref-qv-title" id="qvTitle"></h2>
                <div class="ref-qv-stock-row" id="qvStockRow"></div>
                <div class="ref-qv-price-row">
                    <span>{{ __('site.mrp') }}</span><strong class="ref-qv-price" id="qvMrp"></strong>
                </div>
                <p class="ref-qv-desc" id="qvDescription"></p>
                <div class="ref-qv-actions">
                    @if($publicPageEnabled['dealers'] ?? true)
                    <a href="/dealers" class="ref-primary-button">{{ __('site.find_a_dealer') }}</a>
                    @elseif($publicPageEnabled['contact'] ?? true)
                    <a href="/contact" class="ref-primary-button">{{ __('site.contact_us') }}</a>
                    @endif
                    <a href="#" class="ref-secondary-button ref-qv-view-details" id="qvViewDetails">
                        {{ __('site.view_full_details') }} <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>
</dialog>
