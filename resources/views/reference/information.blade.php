@extends('reference.layout')
@section('title',($page==='dealers'?'Dealer Directory':ucfirst($page)).' · Madina Natural Health Care')
@section('content')
<main id="main" class="ref-container ref-inner-main"><nav class="ref-breadcrumb" aria-label="Breadcrumb"><a href="/" aria-label="Home"><i class="fa-solid fa-house" aria-hidden="true"></i></a><span>/</span><span>{{ $page==='dealers'?'Dealer Directory':ucfirst($page) }}</span></nav>
@if($notice)<div class="ref-alert ref-notice" role="status"><b>{{ $notice->title }}</b><p>{{ $notice->body }}</p></div>@endif
@if($page==='about')
<article class="ref-content-card ref-about-hero">
    <h1>{{ $content->title ?? ($siteCustomization['about_hero_title'] ?? 'About Madina Natural Health Care') }}</h1>
    <div class="ref-content-prose">{{ $content->body ?? ($siteCustomization['about_hero_lead'] ?? 'Madina Natural Health Care connects quality wellness products, dealers, healthcare practitioners and communities through an approved distribution network across Bangladesh.') }}</div>
</article>

<div class="ref-about-sections-grid">
    <section class="ref-content-card">
        <div class="ref-about-icon"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i></div>
        <h2>{{ $siteCustomization['about_history_title'] ?? 'Company History' }}</h2>
        <p>{{ $siteCustomization['about_history'] ?? 'Established with a commitment to pure wellness, Madina Natural Health Care began by addressing the growing need for authentic natural food supplements, unani medicines and accessible community healthcare. Over the years, our organization has expanded from local healthcare service points into a nationwide network connecting dedicated dealers, sub-dealers and health centers across Bangladesh.' }}</p>
    </section>

    <section class="ref-content-card">
        <div class="ref-about-icon"><i class="fa-solid fa-bullseye" aria-hidden="true"></i></div>
        <h2>{{ $siteCustomization['about_mission_title'] ?? 'Our Mission' }}</h2>
        <p>{{ $siteCustomization['about_mission'] ?? 'To formulate, source and deliver reliable, high-standard natural health products while empowering local entrepreneurship through a transparent, staff-verified dealer and distribution ecosystem.' }}</p>
    </section>

    <section class="ref-content-card">
        <div class="ref-about-icon"><i class="fa-solid fa-eye" aria-hidden="true"></i></div>
        <h2>{{ $siteCustomization['about_vision_title'] ?? 'Our Vision' }}</h2>
        <p>{{ $siteCustomization['about_vision'] ?? 'To become Bangladesh’s foremost trusted name in natural preventive healthcare, unani medicine and community wellness, fostering healthier families and resilient partner livelihoods in every district.' }}</p>
    </section>

    <section class="ref-content-card">
        <div class="ref-about-icon"><i class="fa-solid fa-list-check" aria-hidden="true"></i></div>
        <h2>{{ $siteCustomization['about_objectives_title'] ?? 'Core Objectives' }}</h2>
        <ul class="ref-about-objectives">
            <li><strong>{{ $siteCustomization['about_obj1_title'] ?? 'Authentic Wellness' }}:</strong> {{ $siteCustomization['about_obj1_desc'] ?? 'Supply safe, verified unani and dietary supplements with clear quality standards.' }}</li>
            <li><strong>{{ $siteCustomization['about_obj2_title'] ?? 'Empowered Partners' }}:</strong> {{ $siteCustomization['about_obj2_desc'] ?? 'Support authorized dealers and sub-dealers with fair terms, structured training and dedicated portals.' }}</li>
            <li><strong>{{ $siteCustomization['about_obj3_title'] ?? 'Accessible Care' }}:</strong> {{ $siteCustomization['about_obj3_desc'] ?? 'Connect rural and urban communities with nearby partner outlets and physiotherapy assistance.' }}</li>
            <li><strong>{{ $siteCustomization['about_obj4_title'] ?? 'Transparent Operations' }}:</strong> {{ $siteCustomization['about_obj4_desc'] ?? 'Maintain staff-audited orders, manual payment verifications and clear distribution records.' }}</li>
        </ul>
    </section>
</div>

<div class="ref-information-links">
    <a href="/products" class="ref-primary-button">{{ $siteCustomization['about_btn_products'] ?? 'Explore Products' }}</a>
    <a href="/dealers" class="ref-secondary-button"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> {{ $siteCustomization['about_btn_dealers'] ?? 'Find a Dealer' }}</a>
    <a href="/contact">{{ $siteCustomization['about_btn_contact'] ?? 'Contact Head Office' }} <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
</div>
@endif

@if($page==='contact')
<h1 class="ref-page-title">{{ $siteCustomization['contact_title'] ?? 'Contact Us' }}</h1>
<div class="ref-contact-grid">
    <section class="ref-content-card">
        <h2>{{ $siteCustomization['contact_company_title'] ?? 'Madina Natural Health Care' }}</h2>
        <p>{{ $siteCustomization['contact_company_desc'] ?? 'For product information, dealer enquiries or support, send us a message or visit our head office.' }}</p>
        @if($content)<div class="ref-content-prose">{{ $content->body }}</div>@endif
        <div class="ref-contact-topic">
            <i class="fa-solid fa-phone-volume" aria-hidden="true" style="color:#006837;"></i>
            <div>
                <h3>{{ $siteCustomization['contact_phone_title'] ?? 'Customer Care & Hotline' }}</h3>
                <p>
                    <a href="tel:{{ preg_replace('/[^0-9+]/', '', $siteCustomization['contact_phone'] ?? '+8801730532966') }}" style="color:#006837; font-weight:700; font-size:16px; text-decoration:none;">
                        {{ $siteCustomization['contact_phone'] ?? '+8801730532966' }}
                    </a>
                    @if(!empty($siteCustomization['contact_phone_hours'] ?? 'Sat - Thu: 9:00 AM - 8:00 PM'))
                    <br><small style="color:#64748b;">{{ $siteCustomization['contact_phone_hours'] ?? 'Sat - Thu: 9:00 AM - 8:00 PM' }}</small>
                    @endif
                </p>
            </div>
        </div>

        <div class="ref-contact-topic">
            <i class="fa-solid fa-envelope-open-text" aria-hidden="true" style="color:#0284c7;"></i>
            <div>
                <h3>{{ $siteCustomization['contact_email_title'] ?? 'Official Email' }}</h3>
                <p>
                    <a href="mailto:{{ $siteCustomization['contact_email'] ?? 'contact@madinanatural.com' }}" style="color:#006837; font-weight:600; text-decoration:none;">
                        {{ $siteCustomization['contact_email'] ?? 'contact@madinanatural.com' }}
                    </a>
                </p>
            </div>
        </div>

        <div class="ref-contact-topic"><i class="fa-solid fa-store" aria-hidden="true"></i><div><h3>{{ $siteCustomization['contact_topic1_title'] ?? 'Dealer Enquiries' }}</h3><p>{{ $siteCustomization['contact_topic1_desc'] ?? 'Dealer accounts are created by company staff after review.' }}</p></div></div>
        <div class="ref-contact-topic"><i class="fa-solid fa-box-open" aria-hidden="true"></i><div><h3>{{ $siteCustomization['contact_topic2_title'] ?? 'Products & Support' }}</h3><p>{{ $siteCustomization['contact_topic2_desc'] ?? 'Ask about availability, orders and your local dealer.' }}</p></div></div>
        <div class="ref-contact-topic"><i class="fa-solid fa-location-dot" aria-hidden="true"></i><div><h3>{{ $siteCustomization['contact_topic3_title'] ?? 'Head Office' }}</h3><p>{{ $siteCustomization['office_address'] ?? 'Noyarhat, Ashulia, Savar, Dhaka, Bangladesh' }}</p></div></div>

        @php
            $socialMap = [
                'facebook' => $siteCustomization['social_facebook'] ?? '#',
                'youtube' => $siteCustomization['social_youtube'] ?? '#',
                'linkedin' => $siteCustomization['social_linkedin'] ?? '#',
                'instagram' => $siteCustomization['social_instagram'] ?? '#',
                'whatsapp' => $siteCustomization['social_whatsapp'] ?? 'https://wa.me/8801730532966',
            ];
        @endphp
        <div style="margin-top:20px; padding-top:16px; border-top:1px solid #e2e8f0;">
            <h3 style="font-size:14px; font-weight:700; color:#1e293b; margin-bottom:10px;">{{ $siteCustomization['contact_social_title'] ?? 'Connect With Us Online' }}</h3>
            <div class="ref-socials" style="margin-top:0;">
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
            </div>
        </div>

        <a href="/dealers" class="ref-primary-button" style="margin-top:18px;">{{ $siteCustomization['about_btn_dealers'] ?? 'Find a Dealer' }}</a>
    </section>
    <section class="ref-content-card">
        <h2>{{ $siteCustomization['contact_form_title'] ?? 'Send an Enquiry' }}</h2>
        @if(session('success'))<div class="ref-alert ref-success" role="status">{{ session('success') }}</div>@endif
        @if($errors->any())<div class="ref-alert ref-error" role="alert">{{ $errors->first() }}</div>@endif
        <form class="ref-form" action="/enquiries" method="post">
            @csrf
            <label for="enquiry-name">Your Name</label>
            <input id="enquiry-name" name="name" value="{{ old('name') }}" maxlength="150" required autocomplete="name">
            <label for="enquiry-phone">Phone Number</label>
            <input id="enquiry-phone" type="tel" name="phone" value="{{ old('phone') }}" maxlength="30" required autocomplete="tel">
            <label for="enquiry-topic">Enquiry Type</label>
            <select id="enquiry-topic" name="topic">
                @foreach(['Dealer enquiry','Product enquiry','Support','Complaint'] as $topic)
                <option @selected(old('topic', request('topic'))===$topic)>{{ $topic }}</option>
                @endforeach
            </select>
            <label for="enquiry-message">Message</label>
            <textarea id="enquiry-message" name="message" rows="5" minlength="10" maxlength="2000" required>{{ old('message') }}</textarea>
            <input name="website" type="text" class="ref-honeypot" tabindex="-1" autocomplete="off" aria-hidden="true">
            <button class="ref-primary-button" type="submit">Send Message <i class="fa-solid fa-paper-plane" aria-hidden="true"></i></button>
        </form>
    </section>
</div>

<section class="ref-content-card ref-map-card">
    <div class="ref-map-header">
        <h2><i class="fa-solid fa-map-location-dot" aria-hidden="true"></i> {{ $siteCustomization['map_title'] ?? 'Office & Healthcare Center Location' }}</h2>
        <p>{{ $siteCustomization['map_subtitle'] ?? 'Visit Madina Natural Health Care at our registered center or navigate with map directions.' }}</p>
    </div>
    <div class="ref-map-wrapper">
        <iframe 
            src="{{ $siteCustomization['map_embed_url'] ?? 'https://www.openstreetmap.org/export/embed.html?bbox=90.22,23.88,90.30,23.95&amp;layer=mapnik&amp;marker=23.914,90.260' }}" 
            title="Madina Natural Health Care Office Location Map" 
            loading="lazy">
        </iframe>
    </div>
    <div class="ref-map-footer">
        <div>
            <strong>{{ $siteCustomization['map_facility'] ?? 'Madina Natural Health Care & Physiotherapy Center' }}</strong>
            <p><i class="fa-solid fa-location-dot" aria-hidden="true"></i> {{ $siteCustomization['office_address'] ?? 'Noyarhat, Ashulia, Savar, Dhaka, Bangladesh' }}</p>
        </div>
        <a href="{{ $siteCustomization['map_directions_url'] ?? 'https://www.openstreetmap.org/?mlat=23.914&amp;mlon=90.260#map=15/23.914/90.260' }}" target="_blank" rel="noopener noreferrer" class="ref-primary-button">
            <i class="fa-solid fa-diamond-turn-right" aria-hidden="true"></i> {{ $siteCustomization['map_directions_text'] ?? 'Open Map Directions' }}
        </a>
    </div>
</section>
@endif

@if($page==='dealers')
<section class="ref-directory-heading">
    <h1>{{ $siteCustomization['directory_title'] ?? 'Dealer Directory' }}</h1>
    <p>{{ $siteCustomization['directory_subtitle'] ?? 'Find a company-approved dealer point or search nearest dealers in your area.' }}</p>

    <div class="ref-location-search-bar">
        <button type="button" class="ref-location-btn" id="find-nearest-btn">
            <i class="fa-solid fa-location-crosshairs" aria-hidden="true"></i> {{ $siteCustomization['directory_gps_btn'] ?? 'Use My Current Location (Nearest Dealers)' }}
        </button>
        <span class="ref-location-status" id="location-status" role="status" aria-live="polite"></span>
    </div>

    @if(request()->filled(['lat', 'lng']))
    <div class="ref-alert ref-notice" style="margin-top:16px; margin-bottom:12px;">
        <i class="fa-solid fa-location-dot" aria-hidden="true"></i> Showing dealers sorted by distance to your detected location.
        <a href="/dealers" style="margin-left:12px; font-weight:700; color:#ef4a23;">Clear location filter</a>
    </div>
    @endif

    <form method="get" class="ref-directory-filter">
        @if(request('lat'))<input type="hidden" name="lat" value="{{ request('lat') }}">@endif
        @if(request('lng'))<input type="hidden" name="lng" value="{{ request('lng') }}">@endif
        <label>Division
            <select name="division">
                <option value="">All divisions</option>
                @foreach($divisions as $division)
                <option @selected(request('division')===$division)>{{ $division }}</option>
                @endforeach
            </select>
        </label>
        <label>District
            <select name="district">
                <option value="">All districts</option>
                @foreach($districts as $district)
                <option @selected(request('district')===$district)>{{ $district }}</option>
                @endforeach
            </select>
        </label>
        <button class="ref-primary-button">{{ $siteCustomization['directory_filter_btn'] ?? 'Find Dealers' }}</button>
        <a href="/dealers">{{ $siteCustomization['directory_clear_btn'] ?? 'Clear' }}</a>
    </form>
</section>

<div class="ref-directory-grid">
    @forelse($dealers as $dealer)
    <article class="ref-content-card">
        <div class="ref-dealer-icon"><i class="fa-solid fa-store" aria-hidden="true"></i></div>
        <span class="ref-dealer-badge">Approved Dealer</span>
        <h2>{{ $dealer->name }}</h2>
        @php
            $profile = is_array($dealer->profile) ? $dealer->profile : [];
            $pharmacy = $profile['pharmacy'] ?? null;
            $locationParts = array_filter([$profile['union'] ?? null, $profile['upazila'] ?? null, $dealer->district, $dealer->division]);
        @endphp
        @if(!empty($pharmacy))
        <p class="ref-dealer-point"><i class="fa-solid fa-clinic-medical" aria-hidden="true"></i> <b>{{ $pharmacy }}</b></p>
        @endif
        <p><i class="fa-solid fa-location-dot" aria-hidden="true"></i> {{ implode(', ', $locationParts) ?: ($dealer->district . ($dealer->district && $dealer->division ? ' · ' : '') . $dealer->division) }}</p>
        <div class="ref-dealer-meta">
            <small class="ref-dealer-code"><i class="fa-solid fa-id-badge" aria-hidden="true"></i> {{ $dealer->dealer_code }}</small>
            @if(isset($dealer->distance_km) && $dealer->distance_km !== null)
            <span class="ref-dealer-distance"><i class="fa-solid fa-location-arrow" aria-hidden="true"></i> {{ $dealer->distance_km }} km away</span>
            @endif
        </div>
        @if($dealer->phone)
        <p><i class="fa-solid fa-phone" aria-hidden="true"></i> {{ $dealer->phone }}</p>
        @endif
    </article>
    @empty
    <div class="ref-empty">
        <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
        <h2>{{ $siteCustomization['directory_empty_title'] ?? 'No published dealers in this area yet.' }}</h2>
        <p>{{ $siteCustomization['directory_empty_desc'] ?? 'Contact us for help finding a local dealer.' }}</p>
        <a href="/contact" class="ref-primary-button">Contact Us</a>
    </div>
    @endforelse
</div>

@if($dealers->hasPages())
<nav class="ref-pagination" aria-label="Dealer pages">
    @if($dealers->previousPageUrl())<a href="{{ $dealers->previousPageUrl() }}">Previous</a>@endif
    <span>Page {{ $dealers->currentPage() }} of {{ $dealers->lastPage() }}</span>
    @if($dealers->nextPageUrl())<a href="{{ $dealers->nextPageUrl() }}">Next</a>@endif
</nav>
@endif
@endif
</main>
@endsection
