@php
    $chrome = fn (string $key, ?string $custom = null): string => \App\Support\SiteChrome::text($key, $custom);
    $pageTitles = [
        'dealers' => $chrome('dealer_directory', $siteCustomization['directory_title'] ?? null),
        'about' => $chrome('about', $siteCustomization['about_hero_title'] ?? null),
        'contact' => $chrome('contact_us', $siteCustomization['contact_title'] ?? null),
        'partnership' => $chrome('partnership_hero_title', $siteCustomization['partnership_hero_title'] ?? null),
        'services' => $chrome('our_services', $siteCustomization['services_hero_title'] ?? null),
        'quality' => $chrome('quality_promise', $siteCustomization['quality_hero_title'] ?? null),
        'faq' => $chrome('faq', $siteCustomization['faq_hero_title'] ?? null),
        'privacy' => $chrome('privacy_policy', $siteCustomization['privacy_hero_title'] ?? null),
        'terms' => $chrome('terms_of_use', $siteCustomization['terms_hero_title'] ?? null),
        'notice' => $chrome('company_notice'),
        'circular-training' => $chrome('circular_training'),
        'circular-price' => $chrome('circular_price'),
        'circular-meeting' => $chrome('circular_meeting'),
    ];
    $pageTitle = filled($content->title ?? null) ? $content->title : ($pageTitles[$page] ?? ucfirst($page));
@endphp
@extends('reference.layout')
@section('title',$pageTitle.' · Madina Natural Health Care')
@section('content')
<main id="main" class="ref-container ref-inner-main"><nav class="ref-breadcrumb" aria-label="Breadcrumb"><a href="/" aria-label="Home"><i class="fa-solid fa-house" aria-hidden="true"></i></a><span>/</span><span>{{ $pageTitle }}</span></nav>
@if($notice)<div class="ref-alert ref-notice" role="status"><b>{{ $notice->title }}</b><p>{{ $notice->body }}</p></div>@endif
@if($page==='about')
<article class="ref-content-card ref-about-hero">
    <h1>{{ $content->title ?? $chrome('about_hero_title', $siteCustomization['about_hero_title'] ?? null) }}</h1>
    <div class="ref-content-prose">{{ $content->body ?? $chrome('about_hero_lead', $siteCustomization['about_hero_lead'] ?? null) }}</div>
</article>

<div class="ref-about-sections-grid">
    <section class="ref-content-card">
        <div class="ref-about-icon"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i></div>
        <h2>{{ $chrome('about_history_title', $siteCustomization['about_history_title'] ?? null) }}</h2>
        <p>{{ $chrome('about_history', $siteCustomization['about_history'] ?? null) }}</p>
    </section>

    <section class="ref-content-card">
        <div class="ref-about-icon"><i class="fa-solid fa-bullseye" aria-hidden="true"></i></div>
        <h2>{{ $chrome('about_mission_title', $siteCustomization['about_mission_title'] ?? null) }}</h2>
        <p>{{ $chrome('about_mission', $siteCustomization['about_mission'] ?? null) }}</p>
    </section>

    <section class="ref-content-card">
        <div class="ref-about-icon"><i class="fa-solid fa-eye" aria-hidden="true"></i></div>
        <h2>{{ $chrome('about_vision_title', $siteCustomization['about_vision_title'] ?? null) }}</h2>
        <p>{{ $chrome('about_vision', $siteCustomization['about_vision'] ?? null) }}</p>
    </section>

    <section class="ref-content-card">
        <div class="ref-about-icon"><i class="fa-solid fa-list-check" aria-hidden="true"></i></div>
        <h2>{{ $chrome('about_objectives_title', $siteCustomization['about_objectives_title'] ?? null) }}</h2>
        <ul class="ref-about-objectives">
            @foreach([1,2,3,4] as $i)
            <li><strong>{{ $chrome('about_obj'.$i.'_title', $siteCustomization['about_obj'.$i.'_title'] ?? null) }}:</strong> {{ $chrome('about_obj'.$i.'_desc', $siteCustomization['about_obj'.$i.'_desc'] ?? null) }}</li>
            @endforeach
        </ul>
    </section>
</div>

<div class="ref-information-links">
    @if($publicPageEnabled['products'] ?? true)<a href="/products" class="ref-primary-button">{{ $chrome('about_btn_products', $siteCustomization['about_btn_products'] ?? null) }}</a>@endif
    @if($publicPageEnabled['dealers'] ?? true)<a href="/dealers" class="ref-secondary-button"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> {{ $chrome('about_btn_dealers', $siteCustomization['about_btn_dealers'] ?? null) }}</a>@endif
    @if($publicPageEnabled['partnership'] ?? true)<a href="/partnership">{{ __('site.become_a_partner') }}</a>@endif
    @if($publicPageEnabled['contact'] ?? true)<a href="/contact">{{ $chrome('about_btn_contact', $siteCustomization['about_btn_contact'] ?? null) }} <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>@endif
</div>
@endif

@if($page==='contact')
@php
    $enquiryTopics = [
        'contact_topic_dealer' => $chrome('contact_topic_dealer'),
        'contact_topic_partnership' => $chrome('contact_topic_partnership'),
        'contact_topic_product' => $chrome('contact_topic_product'),
        'contact_topic_support' => $chrome('contact_topic_support'),
        'contact_topic_complaint' => $chrome('contact_topic_complaint'),
    ];
    $enquiryTopicValues = [
        'contact_topic_dealer' => 'Dealer enquiry',
        'contact_topic_partnership' => 'Partnership enquiry',
        'contact_topic_product' => 'Product enquiry',
        'contact_topic_support' => 'Support',
        'contact_topic_complaint' => 'Complaint',
    ];
@endphp
<h1 class="ref-page-title">{{ $chrome('contact_title', $siteCustomization['contact_title'] ?? null) }}</h1>
<div class="ref-contact-grid">
    <section class="ref-content-card">
        <h2>{{ $chrome('contact_company_title', $siteCustomization['contact_company_title'] ?? null) }}</h2>
        <p>{{ $chrome('contact_company_desc', $siteCustomization['contact_company_desc'] ?? null) }}</p>
        @if($content)<div class="ref-content-prose">{{ $content->body }}</div>@endif
        <div class="ref-contact-topic">
            <i class="fa-solid fa-phone-volume" aria-hidden="true" style="color:#006837;"></i>
            <div>
                <h3>{{ $chrome('contact_phone_title', $siteCustomization['contact_phone_title'] ?? null) }}</h3>
                <p>
                    <a href="tel:{{ preg_replace('/[^0-9+]/', '', $siteCustomization['contact_phone'] ?? '+8801730532966') }}" style="color:#006837; font-weight:700; font-size:16px; text-decoration:none;">
                        {{ $siteCustomization['contact_phone'] ?? '+8801730532966' }}
                    </a>
                    @php
                        $phoneHoursCustom = filled($siteCustomization['contact_phone_hours'] ?? null) ? $siteCustomization['contact_phone_hours'] : null;
                        $phoneHours = $phoneHoursCustom !== null
                            ? $chrome('contact_phone_hours', $phoneHoursCustom)
                            : (app()->getLocale() === 'bn' ? (string) __('site.contact_phone_hours') : null);
                    @endphp
                    @if(filled($phoneHours))
                    <br><small style="color:#64748b;">{{ $phoneHours }}</small>
                    @endif
                </p>
            </div>
        </div>

        @if(!empty($siteCustomization['contact_email']))
        <div class="ref-contact-topic">
            <i class="fa-solid fa-envelope-open-text" aria-hidden="true" style="color:#0284c7;"></i>
            <div>
                <h3>{{ $chrome('contact_email_title', $siteCustomization['contact_email_title'] ?? null) }}</h3>
                <p>
                    <a href="mailto:{{ $siteCustomization['contact_email'] ?? '' }}" style="color:#006837; font-weight:600; text-decoration:none;">
                        {{ $siteCustomization['contact_email'] ?? '' }}
                    </a>
                </p>
            </div>
        </div>

        @endif

        <div class="ref-contact-topic"><i class="fa-solid fa-store" aria-hidden="true"></i><div><h3>{{ $chrome('contact_topic1_title', $siteCustomization['contact_topic1_title'] ?? null) }}</h3><p>{{ $chrome('contact_topic1_desc', $siteCustomization['contact_topic1_desc'] ?? null) }}</p></div></div>
        <div class="ref-contact-topic"><i class="fa-solid fa-box-open" aria-hidden="true"></i><div><h3>{{ $chrome('contact_topic2_title', $siteCustomization['contact_topic2_title'] ?? null) }}</h3><p>{{ $chrome('contact_topic2_desc', $siteCustomization['contact_topic2_desc'] ?? null) }}</p></div></div>
        <div class="ref-contact-topic"><i class="fa-solid fa-location-dot" aria-hidden="true"></i><div><h3>{{ $chrome('contact_topic3_title', $siteCustomization['contact_topic3_title'] ?? null) }}</h3><p>{{ $chrome('office_address', $siteCustomization['office_address'] ?? null) }}</p></div></div>

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
            <h3 style="font-size:14px; font-weight:700; color:#1e293b; margin-bottom:10px;">{{ $chrome('contact_social_title', $siteCustomization['contact_social_title'] ?? null) }}</h3>
            <div class="ref-socials" style="margin-top:0;">
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
            </div>
        </div>

        @if($publicPageEnabled['dealers'] ?? true)
        <a href="/dealers" class="ref-primary-button" style="margin-top:18px;">{{ $chrome('about_btn_dealers', $siteCustomization['about_btn_dealers'] ?? null) }}</a>
        @endif
    </section>
    <section class="ref-content-card">
        <h2>{{ $chrome('contact_form_title', $siteCustomization['contact_form_title'] ?? null) }}</h2>
        @if(session('success'))<div class="ref-alert ref-success" role="status">{{ session('success') }}</div>@endif
        @if($errors->any())<div class="ref-alert ref-error" role="alert">{{ $errors->first() }}</div>@endif
        <form class="ref-form" action="/enquiries" method="post">
            @csrf
            <label for="enquiry-name">{{ $chrome('contact_form_name') }}</label>
            <input id="enquiry-name" name="name" value="{{ old('name') }}" maxlength="150" required autocomplete="name">
            <label for="enquiry-phone">{{ $chrome('contact_form_phone') }}</label>
            <input id="enquiry-phone" type="tel" name="phone" value="{{ old('phone') }}" maxlength="30" required autocomplete="tel">
            <label for="enquiry-topic">{{ $chrome('contact_form_topic') }}</label>
            <select id="enquiry-topic" name="topic">
                @foreach($enquiryTopicValues as $topicKey => $topicValue)
                <option value="{{ $topicValue }}" @selected(old('topic', request('topic'))===$topicValue)>{{ $enquiryTopics[$topicKey] }}</option>
                @endforeach
            </select>
            <label for="enquiry-message">{{ $chrome('contact_form_message') }}</label>
            <textarea id="enquiry-message" name="message" rows="5" minlength="10" maxlength="2000" required>{{ old('message') }}</textarea>
            <input name="website" type="text" class="ref-honeypot" tabindex="-1" autocomplete="off" aria-hidden="true">
            <button class="ref-primary-button" type="submit">{{ $chrome('contact_form_submit') }} <i class="fa-solid fa-paper-plane" aria-hidden="true"></i></button>
        </form>
    </section>
</div>

<section class="ref-content-card ref-map-card">
    <div class="ref-map-header">
        <h2><i class="fa-solid fa-map-location-dot" aria-hidden="true"></i> {{ $chrome('map_title', $siteCustomization['map_title'] ?? null) }}</h2>
        <p>{{ $chrome('map_subtitle', $siteCustomization['map_subtitle'] ?? null) }}</p>
    </div>
    @if(!empty($maps['embed_url']))
    <div class="ref-map-embed" style="margin:16px 0;">
        <iframe title="Office location map" src="{{ $maps['embed_url'] }}" width="100%" height="320" style="border:0;border-radius:12px;" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe>
    </div>
    @endif
    <div class="ref-map-footer">
        <div>
            <strong>{{ $chrome('map_facility', $siteCustomization['map_facility'] ?? null) }}</strong>
            <p><i class="fa-solid fa-location-dot" aria-hidden="true"></i> {{ $chrome('office_address', $siteCustomization['office_address'] ?? null) }}</p>
        </div>
        <a href="{{ $maps['directions_url'] ?? ('https://www.openstreetmap.org/search?query=' . rawurlencode($siteCustomization['office_address'] ?? __('site.office_address'))) }}" target="_blank" rel="noopener noreferrer" class="ref-primary-button">
            <i class="fa-solid fa-diamond-turn-right" aria-hidden="true"></i> {{ $chrome('map_directions_text', $siteCustomization['map_directions_text'] ?? null) }}
        </a>
    </div>
</section>
@endif

@if($page==='dealers')
<section class="ref-directory-heading">
    <h1>{{ $chrome('directory_title', $siteCustomization['directory_title'] ?? null) }}</h1>
    <p>{{ $chrome('directory_subtitle', $siteCustomization['directory_subtitle'] ?? null) }}</p>

    <div class="ref-location-search-bar">
        <button type="button" class="ref-location-btn" id="find-nearest-btn">
            <i class="fa-solid fa-location-crosshairs" aria-hidden="true"></i> {{ $chrome('directory_gps_btn', $siteCustomization['directory_gps_btn'] ?? null) }}
        </button>
        <span class="ref-location-status" id="location-status" role="status" aria-live="polite"></span>
    </div>

    @if(request()->filled(['lat', 'lng']))
    <div class="ref-alert ref-notice" style="margin-top:16px; margin-bottom:12px;">
        <i class="fa-solid fa-location-dot" aria-hidden="true"></i> {{ $chrome('directory_location_notice') }}
        <a href="/dealers" style="margin-left:12px; font-weight:700; color:#ef4a23;">{{ $chrome('directory_clear_location') }}</a>
    </div>
    @endif

    <form method="get" class="ref-directory-filter">
        @if(request('lat'))<input type="hidden" name="lat" value="{{ request('lat') }}">@endif
        @if(request('lng'))<input type="hidden" name="lng" value="{{ request('lng') }}">@endif
        <label>{{ $chrome('directory_division') }}
            <select name="division">
                <option value="">{{ $chrome('directory_all_divisions') }}</option>
                @foreach($divisions as $division)
                <option @selected(request('division')===$division)>{{ $division }}</option>
                @endforeach
            </select>
        </label>
        <label>{{ $chrome('directory_district') }}
            <select name="district">
                <option value="">{{ $chrome('directory_all_districts') }}</option>
                @foreach($districts as $district)
                <option @selected(request('district')===$district)>{{ $district }}</option>
                @endforeach
            </select>
        </label>
        <button class="ref-primary-button">{{ $chrome('directory_filter_btn', $siteCustomization['directory_filter_btn'] ?? null) }}</button>
        <a href="/dealers">{{ $chrome('directory_clear_btn', $siteCustomization['directory_clear_btn'] ?? null) }}</a>
    </form>
</section>

<div class="ref-directory-grid">
    @forelse($dealers as $dealer)
    <article class="ref-content-card">
        <div class="ref-dealer-icon"><i class="fa-solid fa-store" aria-hidden="true"></i></div>
        <span class="ref-dealer-badge">{{ $chrome('directory_approved_badge') }}</span>
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
            <span class="ref-dealer-distance"><i class="fa-solid fa-location-arrow" aria-hidden="true"></i> {{ __('site.directory_distance_km', ['km' => $dealer->distance_km]) }}</span>
            @endif
        </div>
        @if($dealer->phone)
        <p><i class="fa-solid fa-phone" aria-hidden="true"></i> {{ $dealer->phone }}</p>
        @endif
    </article>
    @empty
    <div class="ref-empty">
        <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
        <h2>{{ $chrome('directory_empty_title', $siteCustomization['directory_empty_title'] ?? null) }}</h2>
        <p>{{ $chrome('directory_empty_desc', $siteCustomization['directory_empty_desc'] ?? null) }}</p>
        @if($publicPageEnabled['contact'] ?? true)<a href="/contact" class="ref-primary-button">{{ $chrome('contact_us') }}</a>@endif
    </div>
    @endforelse
</div>

@if($dealers->hasPages())
<nav class="ref-pagination" aria-label="{{ $chrome('directory_pagination_label') }}">
    @if($dealers->previousPageUrl())<a href="{{ $dealers->previousPageUrl() }}">{{ $chrome('directory_pagination_previous') }}</a>@endif
    <span>{{ __('site.directory_pagination_page_of', ['page' => $dealers->currentPage(), 'pages' => $dealers->lastPage()]) }}</span>
    @if($dealers->nextPageUrl())<a href="{{ $dealers->nextPageUrl() }}">{{ $chrome('directory_pagination_next') }}</a>@endif
</nav>
@endif
@endif

@if($page==='partnership')
<article class="ref-content-card ref-about-hero">
    <h1>{{ $content->title ?? $chrome('partnership_hero_title', $siteCustomization['partnership_hero_title'] ?? null) }}</h1>
    <div class="ref-content-prose">{{ $content->body ?? $chrome('partnership_hero_lead', $siteCustomization['partnership_hero_lead'] ?? null) }}</div>
</article>
<h2 class="ref-page-title" style="margin-top:28px;font-size:20px;">{{ $chrome('partnership_why_title', $siteCustomization['partnership_why_title'] ?? null) }}</h2>
<div class="ref-about-sections-grid">
    @foreach([1,2,3,4] as $i)
    <section class="ref-content-card">
        <div class="ref-about-icon"><i class="fa-solid {{ ['fa-leaf','fa-clipboard-check','fa-laptop','fa-map-location-dot'][$i-1] }}" aria-hidden="true"></i></div>
        <h2>{{ $chrome('partnership_benefit'.$i.'_title', $siteCustomization['partnership_benefit'.$i.'_title'] ?? null) }}</h2>
        <p>{{ $chrome('partnership_benefit'.$i.'_desc', $siteCustomization['partnership_benefit'.$i.'_desc'] ?? null) }}</p>
    </section>
    @endforeach
</div>
<section class="ref-content-card" style="margin-top:24px;">
    <h2>{{ $chrome('partnership_steps_title', $siteCustomization['partnership_steps_title'] ?? null) }}</h2>
    <ol class="ref-about-objectives" style="list-style:decimal;padding-left:20px;">
        @foreach([1,2,3] as $i)
        <li style="margin-bottom:12px;"><strong>{{ $chrome('partnership_step'.$i.'_title', $siteCustomization['partnership_step'.$i.'_title'] ?? null) }}:</strong> {{ $chrome('partnership_step'.$i.'_desc', $siteCustomization['partnership_step'.$i.'_desc'] ?? null) }}</li>
        @endforeach
    </ol>
</section>
<section class="ref-content-card" style="margin-top:24px;background:linear-gradient(135deg,#f3f7f4,#fff);">
    <h2>{{ $chrome('partnership_cta_title', $siteCustomization['partnership_cta_title'] ?? null) }}</h2>
    <p>{{ $chrome('partnership_cta_body', $siteCustomization['partnership_cta_body'] ?? null) }}</p>
    <div class="ref-information-links" style="margin-top:16px;">
        <a href="{{ $siteCustomization['partnership_cta_link'] ?? '#partner-apply' }}" class="ref-primary-button">{{ $chrome('partnership_cta_btn', $siteCustomization['partnership_cta_btn'] ?? null) }}</a>
        @if($publicPageEnabled['dealers'] ?? true)
        <a href="/dealers" class="ref-secondary-button"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> {{ __('site.find_a_dealer') }}</a>
        @endif
    </div>
</section>
<section id="partner-apply" class="ref-content-card" style="margin-top:24px;">
    <h2>{{ __('site.partnership_form_title') }}</h2>
    <p>{{ __('site.partnership_form_hint') }}</p>
    @if(session('success'))
        <p class="ref-flash-success" role="status">{{ session('success') }}</p>
    @endif
    @if($errors->any())
        <ul class="ref-form-errors" role="alert">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif
    <form method="post" action="/partnership/apply" class="ref-partner-apply-form" style="display:grid;gap:14px;margin-top:16px;">
        @csrf
        <input type="text" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;height:0;width:0;opacity:0;">
        <label>{{ __('site.full_name') }}<input name="name" value="{{ old('name') }}" required maxlength="150"></label>
        <label>{{ __('site.email') }}<input type="email" name="email" value="{{ old('email') }}" required maxlength="254"></label>
        <label>{{ __('site.mobile') }}<input name="phone" value="{{ old('phone') }}" required maxlength="30"></label>
        <label>{{ __('site.partnership_type') }}
            <select name="role" required>
                <option value="dealer" @selected(old('role')==='dealer')>{{ __('site.role_dealer') }}</option>
                <option value="sub_dealer" @selected(old('role', 'sub_dealer')==='sub_dealer')>{{ __('site.role_sub_dealer') }}</option>
            </select>
        </label>
        <label>{{ __('site.division') }}<input name="division" value="{{ old('division') }}" required maxlength="80"></label>
        <label>{{ __('site.district') }}<input name="district" value="{{ old('district') }}" required maxlength="80"></label>
        <label>{{ __('site.upazila_optional') }}<input name="upazila" value="{{ old('upazila') }}" maxlength="150"></label>
        <label>{{ __('site.sponsor_code_optional') }}<input name="sponsor_code" value="{{ old('sponsor_code', request('sponsor_code')) }}" maxlength="40" autocomplete="off"></label>
        <label>{{ __('site.message_optional') }}<textarea name="message" maxlength="2000" rows="4">{{ old('message') }}</textarea></label>
        <button type="submit" class="ref-primary-button">{{ __('site.submit_application') }}</button>
    </form>
</section>
@endif

@if($page==='services')
<article class="ref-content-card ref-about-hero">
    <h1>{{ $content->title ?? $chrome('services_hero_title', $siteCustomization['services_hero_title'] ?? null) }}</h1>
    <div class="ref-content-prose">{{ $content->body ?? $chrome('services_hero_lead', $siteCustomization['services_hero_lead'] ?? null) }}</div>
</article>
<div class="ref-about-sections-grid">
    @foreach([1,2,3] as $i)
    <section class="ref-content-card">
        <div class="ref-about-icon"><i class="fa-solid {{ ['fa-stethoscope','fa-hand-holding-medical','fa-store'][$i-1] }}" aria-hidden="true"></i></div>
        <h2>{{ $chrome('services_card'.$i.'_title', $siteCustomization['services_card'.$i.'_title'] ?? null) }}</h2>
        <p>{{ $chrome('services_card'.$i.'_desc', $siteCustomization['services_card'.$i.'_desc'] ?? null) }}</p>
    </section>
    @endforeach
</div>
<div class="ref-information-links">
    @if($cta = \App\Support\SiteCustomization::publicHref($siteCustomization['services_cta_link'] ?? '/contact'))
    <a href="{{ $cta }}" class="ref-primary-button">{{ $chrome('services_cta_btn', $siteCustomization['services_cta_btn'] ?? null) }}</a>
    @endif
    @if($publicPageEnabled['quality'] ?? true)<a href="/quality" class="ref-secondary-button">{{ $chrome('quality_promise') }}</a>@endif
</div>
@endif

@if($page==='quality')
<article class="ref-content-card ref-about-hero">
    <h1>{{ $content->title ?? $chrome('quality_hero_title', $siteCustomization['quality_hero_title'] ?? null) }}</h1>
    <div class="ref-content-prose">{{ $content->body ?? $chrome('quality_hero_lead', $siteCustomization['quality_hero_lead'] ?? null) }}</div>
</article>
<div class="ref-about-sections-grid">
    @foreach([1,2,3] as $i)
    <section class="ref-content-card">
        <div class="ref-about-icon"><i class="fa-solid {{ ['fa-shield-heart','fa-certificate','fa-file-circle-check'][$i-1] }}" aria-hidden="true"></i></div>
        <h2>{{ $chrome('quality_pillar'.$i.'_title', $siteCustomization['quality_pillar'.$i.'_title'] ?? null) }}</h2>
        <p>{{ $chrome('quality_pillar'.$i.'_desc', $siteCustomization['quality_pillar'.$i.'_desc'] ?? null) }}</p>
    </section>
    @endforeach
</div>
<div class="ref-information-links">
    @if($cta = \App\Support\SiteCustomization::publicHref($siteCustomization['quality_cta_link'] ?? '/products'))
    <a href="{{ $cta }}" class="ref-primary-button">{{ $chrome('quality_cta_btn', $siteCustomization['quality_cta_btn'] ?? null) }}</a>
    @endif
    @if($publicPageEnabled['partnership'] ?? true)<a href="/partnership">{{ __('site.become_a_partner') }} <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>@endif
</div>
@endif

@if($page==='faq')
@php
    $faqItems = app()->getLocale() === 'bn'
        ? collect(range(1, 5))->map(fn (int $i): array => [
            'question' => (string) __('site.faq_item'.$i.'_question'),
            'answer' => (string) __('site.faq_item'.$i.'_answer'),
        ])->all()
        : ($siteCustomization['faq_items'] ?? []);
@endphp
<article class="ref-content-card ref-about-hero">
    <h1>{{ $content->title ?? $chrome('faq_hero_title', $siteCustomization['faq_hero_title'] ?? null) }}</h1>
    <div class="ref-content-prose">{{ $content->body ?? $chrome('faq_hero_lead', $siteCustomization['faq_hero_lead'] ?? null) }}</div>
</article>
<div style="display:flex;flex-direction:column;gap:14px;margin-top:22px;">
    @foreach($faqItems as $item)
    <details class="ref-content-card" style="padding:18px 22px;">
        <summary style="cursor:pointer;font-weight:700;color:#011627;list-style:none;display:flex;align-items:center;justify-content:space-between;gap:12px;">
            <span>{{ $item['question'] ?? '' }}</span>
            <i class="fa-solid fa-chevron-down" aria-hidden="true" style="color:#64748b;font-size:12px;"></i>
        </summary>
        <p style="margin:14px 0 0;color:#475569;line-height:1.7;">{{ $item['answer'] ?? '' }}</p>
    </details>
    @endforeach
</div>
<div class="ref-information-links">
    @if($publicPageEnabled['contact'] ?? true)<a href="/contact" class="ref-primary-button">{{ $chrome('faq_still_need_help') }}</a>@endif
    @if($publicPageEnabled['partnership'] ?? true)<a href="/partnership" class="ref-secondary-button">{{ __('site.partnership_enquiries') }}</a>@endif
</div>
@endif

@if(in_array($page, ['privacy', 'terms', 'notice', 'circular-training', 'circular-price', 'circular-meeting'], true))
<article class="ref-content-card ref-about-hero">
    <h1>{{ $content->title ?? ($siteCustomization[$page.'_hero_title'] ?? ($pageTitles[$page] ?? ucfirst($page))) }}</h1>
    @unless(filled($content->body ?? null))
        <div class="ref-content-prose">
            @if(in_array($page, ['notice', 'circular-training', 'circular-price', 'circular-meeting'], true))
                {{ app()->getLocale() === 'bn' || blank($siteCustomization[$page.'_hero_lead'] ?? null) ? __('site.notice_empty') : $siteCustomization[$page.'_hero_lead'] }}
            @else
                {{ $siteCustomization[$page.'_hero_lead'] ?? '' }}
            @endif
        </div>
    @endunless
</article>
@if(filled($content->body ?? null) || in_array($page, ['privacy', 'terms'], true))
<section class="ref-content-card" style="margin-top:22px;">
    <div class="ref-content-prose" style="white-space:pre-line;">{{ $content->body ?? ($siteCustomization[$page.'_body'] ?? '') }}</div>
</section>
@endif
<div class="ref-information-links">
    @if($publicPageEnabled['contact'] ?? true)<a href="/contact" class="ref-primary-button">{{ $chrome('contact_head_office', $siteCustomization['about_btn_contact'] ?? null) }}</a>@endif
    @if(in_array($page, ['privacy', 'terms'], true))
        @php $altLegal = $page === 'privacy' ? 'terms' : 'privacy'; @endphp
        @if($publicPageEnabled[$altLegal] ?? true)
        <a href="/{{ $altLegal }}">{{ $page === 'privacy' ? __('site.terms_of_use') : __('site.privacy_policy') }} <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
        @endif
    @elseif($publicPageEnabled['partnership'] ?? true)
        <a href="/partnership">{{ __('site.become_a_partner') }} <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
    @endif
</div>
@endif
</main>
@endsection
