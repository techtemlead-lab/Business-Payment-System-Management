@php
    $chrome = fn (string $key, ?string $custom = null): string => \App\Support\SiteChrome::text($key, $custom);
    $pageTitles = [
        'dealers' => $chrome('dealer_directory', $siteCustomization['directory_title'] ?? null),
        'about' => $chrome('about_hero_title', $siteCustomization['about_hero_title'] ?? null),
        'contact' => $chrome('contact_us', $siteCustomization['contact_title'] ?? null),
        'partnership' => $chrome('partnership_hero_title', $siteCustomization['partnership_hero_title'] ?? null),
        'services' => $chrome('services_hero_title', $siteCustomization['services_hero_title'] ?? null),
        'quality' => $chrome('quality_hero_title', $siteCustomization['quality_hero_title'] ?? null),
        'faq' => $chrome('faq_hero_title', $siteCustomization['faq_hero_title'] ?? null),
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
@section('title', $pageTitle.' · '.\App\Support\SiteCustomization::brandName())
@section('content')
<main id="main" class="ref-container ref-inner-main"><nav class="ref-breadcrumb" aria-label="{{ __('site.breadcrumb') }}"><a href="/" aria-label="{{ __('site.home') }}"><i class="fa-solid fa-house" aria-hidden="true"></i></a><span>/</span><span>{{ $pageTitle }}</span></nav>
@if($notice)<div class="ref-alert ref-notice" role="status"><b>{{ $notice->title }}</b><p>{{ $notice->body }}</p></div>@endif
@if($page==='about')
@include('reference.partials.page-plate', [
    'title' => $chrome('about_hero_title', $siteCustomization['about_hero_title'] ?? null),
    'lead' => $chrome('about_hero_lead', $siteCustomization['about_hero_lead'] ?? null),
    'icon' => 'fa-leaf',
])

@include('reference.partials.md-message', ['placement' => 'about'])

<div class="ref-about-story">
    <section class="ref-content-card ref-about-history">
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
</div>

<section class="ref-content-card ref-about-goals">
    <div class="ref-about-icon"><i class="fa-solid fa-list-check" aria-hidden="true"></i></div>
    <h2>{{ $chrome('about_objectives_title', $siteCustomization['about_objectives_title'] ?? null) }}</h2>
    <ul class="ref-about-objectives">
        @foreach([1,2,3,4] as $i)
        <li><strong>{{ $chrome('about_obj'.$i.'_title', $siteCustomization['about_obj'.$i.'_title'] ?? null) }}:</strong> {{ $chrome('about_obj'.$i.'_desc', $siteCustomization['about_obj'.$i.'_desc'] ?? null) }}</li>
        @endforeach
    </ul>
</section>

<section class="ref-page-closer">
    <div>
        <h2>{{ $chrome('about_network_title', $siteCustomization['about_network_title'] ?? null) }}</h2>
        <p>{{ $chrome('about_network', $siteCustomization['about_network'] ?? null) }}</p>
    </div>
    <div class="ref-information-links">
        @if($publicPageEnabled['products'] ?? true)<a href="/products" class="ref-primary-button">{{ $chrome('about_btn_products', $siteCustomization['about_btn_products'] ?? null) }}</a>@endif
        @if($publicPageEnabled['dealers'] ?? true)<a href="/dealers" class="ref-secondary-button"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> {{ $chrome('about_btn_dealers', $siteCustomization['about_btn_dealers'] ?? null) }}</a>@endif
        @if($publicPageEnabled['partnership'] ?? true)<a href="/partnership" class="ref-ghost-button">{{ __('site.become_a_partner') }}</a>@endif
        @if($publicPageEnabled['contact'] ?? true)<a href="/contact" class="ref-ghost-button">{{ $chrome('about_btn_contact', $siteCustomization['about_btn_contact'] ?? null) }}</a>@endif
    </div>
</section>
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
        'contact_topic_dealer' => 'dealer_enquiry',
        'contact_topic_partnership' => 'partnership_enquiry',
        'contact_topic_product' => 'product_enquiry',
        'contact_topic_support' => 'support',
        'contact_topic_complaint' => 'complaint',
    ];
@endphp
@include('reference.partials.page-plate', [
    'title' => $chrome('contact_title', $siteCustomization['contact_title'] ?? null),
    'lead' => $chrome('contact_company_desc', $siteCustomization['contact_company_desc'] ?? null),
    'icon' => 'fa-headset',
])
<div class="ref-contact-grid">
    <section class="ref-content-card">
        <h2>{{ $chrome('contact_company_title', $siteCustomization['contact_company_title'] ?? null) }}</h2>
        @if($content)<div class="ref-content-prose">{{ $content->body }}</div>@endif
        <div class="ref-contact-topic">
            <i class="fa-solid fa-phone-volume" aria-hidden="true"></i>
            <div>
                <h3>{{ $chrome('contact_phone_title', $siteCustomization['contact_phone_title'] ?? null) }}</h3>
                <p>
                    <a href="{{ \App\Support\SiteCustomization::phoneLink((string) ($siteCustomization['contact_phone'] ?? '')) }}" class="ref-contact-link is-phone">
                        {{ $siteCustomization['contact_phone'] ?? '' }}
                    </a>
                    @if($secondPhone = \App\Support\SiteCustomization::secondPhone())
                    <br><a href="{{ \App\Support\SiteCustomization::phoneLink($secondPhone) }}" class="ref-contact-link is-phone">{{ $secondPhone }}</a>
                    @endif
                    @if(filled($siteCustomization['contact_phone_hours'] ?? null))
                    <br><small class="ref-contact-hours">{{ $chrome('contact_phone_hours', $siteCustomization['contact_phone_hours']) }}</small>
                    @endif
                </p>
            </div>
        </div>

        @if(!empty($siteCustomization['contact_email']))
        <div class="ref-contact-topic">
            <i class="fa-solid fa-envelope-open-text" aria-hidden="true"></i>
            <div>
                <h3>{{ $chrome('contact_email_title', $siteCustomization['contact_email_title'] ?? null) }}</h3>
                <p>
                    <a href="mailto:{{ $siteCustomization['contact_email'] ?? '' }}" class="ref-contact-link">
                        {{ $siteCustomization['contact_email'] ?? '' }}
                    </a>
                </p>
            </div>
        </div>

        @endif

        <div class="ref-contact-topic"><i class="fa-solid fa-store" aria-hidden="true"></i><div><h3>{{ $chrome('contact_topic1_title', $siteCustomization['contact_topic1_title'] ?? null) }}</h3><p>{{ $chrome('contact_topic1_desc', $siteCustomization['contact_topic1_desc'] ?? null) }}</p></div></div>
        <div class="ref-contact-topic"><i class="fa-solid fa-box-open" aria-hidden="true"></i><div><h3>{{ $chrome('contact_topic2_title', $siteCustomization['contact_topic2_title'] ?? null) }}</h3><p>{{ $chrome('contact_topic2_desc', $siteCustomization['contact_topic2_desc'] ?? null) }}</p></div></div>
        <div class="ref-contact-topic"><i class="fa-solid fa-location-dot" aria-hidden="true"></i><div><h3>{{ $chrome('contact_topic3_title', $siteCustomization['contact_topic3_title'] ?? null) }}</h3><p>{{ $chrome('office_address', $siteCustomization['office_address'] ?? null) }}</p></div></div>

        @if(\App\Support\SiteCustomization::socialLinks() !== [])
        <div class="ref-contact-socials">
            <h3>{{ $chrome('contact_social_title', $siteCustomization['contact_social_title'] ?? null) }}</h3>
            @include('reference.partials.social-links')
        </div>
        @endif

        @if($publicPageEnabled['dealers'] ?? true)
        <a href="/dealers" class="ref-primary-button ref-contact-dealers">{{ $chrome('about_btn_dealers', $siteCustomization['about_btn_dealers'] ?? null) }}</a>
        @endif
    </section>
    <section class="ref-content-card">
        <h2>{{ $chrome('contact_form_title', $siteCustomization['contact_form_title'] ?? null) }}</h2>
        <div id="enquiry-status" class="ref-form-status">
            @if(session('success'))<div class="ref-alert ref-success" role="status">{{ session('success') }}</div>@endif
            @if($errors->any())<div class="ref-alert ref-error" role="alert">{{ $errors->first() }}</div>@endif
        </div>
        <form class="ref-form" action="/enquiries" method="post">
            @csrf
            <label for="enquiry-name">{{ $chrome('contact_form_name') }}</label>
            <input id="enquiry-name" name="name" value="{{ old('name') }}" maxlength="150" required autocomplete="name">
            <label for="enquiry-phone">{{ $chrome('contact_form_phone') }}</label>
            <input id="enquiry-phone" type="tel" name="phone" value="{{ old('phone') }}" maxlength="30" required autocomplete="tel">
            <label for="enquiry-topic">{{ $chrome('contact_form_topic') }}</label>
            @php
                $selectedTopic = old('topic', request('topic'));
                $selectedTopicKey = match ($selectedTopic) {
                    'Dealer enquiry', 'dealer_enquiry' => 'dealer_enquiry',
                    'Partnership enquiry', 'partnership_enquiry' => 'partnership_enquiry',
                    'Product enquiry', 'product_enquiry' => 'product_enquiry',
                    'Support', 'support' => 'support',
                    'Complaint', 'complaint' => 'complaint',
                    default => $selectedTopic,
                };
            @endphp
            <select id="enquiry-topic" name="topic">
                @foreach($enquiryTopicValues as $topicKey => $topicValue)
                <option value="{{ $topicValue }}" @selected($selectedTopicKey===$topicValue)>{{ $enquiryTopics[$topicKey] }}</option>
                @endforeach
            </select>
            @php $referredBy = old('referred_by', request('referred_by')); @endphp
            <label for="enquiry-referred-by">{{ $chrome('contact_form_referred_by', null) ?: __('site.contact_form_referred_by') }}</label>
            <input id="enquiry-referred-by" name="referred_by" value="{{ is_string($referredBy) ? strtoupper($referredBy) : '' }}" maxlength="40" autocomplete="off" @if(filled($referredBy)) readonly @endif>
            <label for="enquiry-message">{{ $chrome('contact_form_message') }}</label>
            <textarea id="enquiry-message" name="message" rows="5" minlength="10" maxlength="2000" required>{{ old('message') }}</textarea>
            <input name="website" type="text" class="ref-honeypot" tabindex="-1" autocomplete="off" aria-hidden="true">
            <button class="ref-primary-button" type="submit" id="enquiry">{{ $chrome('contact_form_submit') }} <i class="fa-solid fa-paper-plane" aria-hidden="true"></i></button>
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
        <iframe title="{{ __('site.office_map_title') }}" src="{{ $maps['embed_url'] }}" width="100%" height="320" style="border:0;border-radius:12px;" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe>
    </div>
    @endif
    <div class="ref-map-footer">
        <div>
            <strong>{{ $chrome('map_facility', $siteCustomization['map_facility'] ?? null) }}</strong>
            <p><i class="fa-solid fa-location-dot" aria-hidden="true"></i> {{ $chrome('office_address', $siteCustomization['office_address'] ?? null) }}</p>
        </div>
        <a href="{{ $maps['directions_url'] ?? ('https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode($siteCustomization['office_address'] ?? __('site.office_address'))) }}" target="_blank" rel="noopener noreferrer" class="ref-primary-button">
            <i class="fa-solid fa-diamond-turn-right" aria-hidden="true"></i> {{ $chrome('map_directions_text', $siteCustomization['map_directions_text'] ?? null) }}
        </a>
    </div>
</section>
@endif

@if($page==='dealers')
@include('reference.partials.page-plate', [
    'title' => $chrome('directory_title', $siteCustomization['directory_title'] ?? null),
    'lead' => $chrome('directory_subtitle', $siteCustomization['directory_subtitle'] ?? null),
    'icon' => 'fa-store',
])
<section class="ref-directory-heading ref-directory-tools">
    <div class="ref-location-search-bar">
        <button type="button" class="ref-location-btn" id="find-nearest-btn">
            <i class="fa-solid fa-location-crosshairs" aria-hidden="true"></i> {{ $chrome('directory_gps_btn', $siteCustomization['directory_gps_btn'] ?? null) }}
        </button>
        <span class="ref-location-status" id="location-status" role="status" aria-live="polite"></span>
    </div>

    @if(request()->filled(['lat', 'lng']))
    <div class="ref-alert ref-notice ref-directory-location-note">
        <i class="fa-solid fa-location-dot" aria-hidden="true"></i> {{ $chrome('directory_location_notice') }}
        <a href="/dealers">{{ $chrome('directory_clear_location') }}</a>
    </div>
    @endif

    <form method="get" class="ref-directory-filter">
        @if(request('lat'))<input type="hidden" name="lat" value="{{ request('lat') }}">@endif
        @if(request('lng'))<input type="hidden" name="lng" value="{{ request('lng') }}">@endif
        <label>{{ $chrome('directory_division') }}
            <select name="division">
                <option value="">{{ $chrome('directory_all_divisions') }}</option>
                @foreach($divisions as $division)
                <option value="{{ $division['id'] }}" @selected((string) request('division') === (string) $division['id'])>{{ $division['name'] }}</option>
                @endforeach
            </select>
        </label>
        <label>{{ $chrome('directory_district') }}
            <select name="district">
                <option value="">{{ $chrome('directory_all_districts') }}</option>
                @foreach($districts as $district)
                <option value="{{ $district['id'] }}" @selected((string) request('district') === (string) $district['id'])>{{ $district['name'] }}</option>
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
            <span class="ref-dealer-distance"><i class="fa-solid fa-location-arrow" aria-hidden="true"></i> {{ __('site.directory_distance_km', ['km' => \App\Support\LocalDigits::text($dealer->distance_km)]) }}</span>
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
    <span>{{ __('site.directory_pagination_page_of', ['page' => \App\Support\LocalDigits::text($dealers->currentPage()), 'pages' => \App\Support\LocalDigits::text($dealers->lastPage())]) }}</span>
    @if($dealers->nextPageUrl())<a href="{{ $dealers->nextPageUrl() }}">{{ $chrome('directory_pagination_next') }}</a>@endif
</nav>
@endif
@endif

@if($page==='partnership')
@include('reference.partials.page-plate', [
    'title' => $content->title ?? $chrome('partnership_hero_title', $siteCustomization['partnership_hero_title'] ?? null),
    'lead' => $content->body ?? $chrome('partnership_hero_lead', $siteCustomization['partnership_hero_lead'] ?? null),
    'icon' => 'fa-handshake-angle',
])
<h2 class="ref-section-heading">{{ $chrome('partnership_why_title', $siteCustomization['partnership_why_title'] ?? null) }}</h2>
<div class="ref-about-sections-grid is-four">
    @foreach([1,2,3,4] as $i)
    <section class="ref-content-card">
        <div class="ref-about-icon"><i class="fa-solid {{ ['fa-leaf','fa-clipboard-check','fa-laptop','fa-map-location-dot'][$i-1] }}" aria-hidden="true"></i></div>
        <h2>{{ $chrome('partnership_benefit'.$i.'_title', $siteCustomization['partnership_benefit'.$i.'_title'] ?? null) }}</h2>
        <p>{{ $chrome('partnership_benefit'.$i.'_desc', $siteCustomization['partnership_benefit'.$i.'_desc'] ?? null) }}</p>
    </section>
    @endforeach
</div>
<section class="ref-content-card ref-steps-card">
    <h2>{{ $chrome('partnership_steps_title', $siteCustomization['partnership_steps_title'] ?? null) }}</h2>
    <ol class="ref-steps">
        @foreach([1,2,3] as $i)
        <li>
            <h3>{{ $chrome('partnership_step'.$i.'_title', $siteCustomization['partnership_step'.$i.'_title'] ?? null) }}</h3>
            <p>{{ $chrome('partnership_step'.$i.'_desc', $siteCustomization['partnership_step'.$i.'_desc'] ?? null) }}</p>
        </li>
        @endforeach
    </ol>
</section>
<section class="ref-page-closer">
    <div>
        <h2>{{ $chrome('partnership_cta_title', $siteCustomization['partnership_cta_title'] ?? null) }}</h2>
        <p>{{ $chrome('partnership_cta_body', $siteCustomization['partnership_cta_body'] ?? null) }}</p>
    </div>
    <div class="ref-information-links">
        @if($partnershipCta = \App\Support\SiteCustomization::publicHref($siteCustomization['partnership_cta_link'] ?? '#partner-apply'))
        <a href="{{ $partnershipCta }}" class="ref-primary-button">{{ $chrome('partnership_cta_btn', $siteCustomization['partnership_cta_btn'] ?? null) }}</a>
        @endif
        @if($publicPageEnabled['dealers'] ?? true)
        <a href="/dealers" class="ref-secondary-button"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> {{ __('site.find_a_dealer') }}</a>
        @endif
    </div>
</section>
<section id="partner-apply" class="ref-content-card ref-partner-apply-card">
    <div class="ref-partner-card-header">
        <div class="ref-partner-header-top">
            <div class="ref-partner-icon-badge">
                <i class="fa-solid fa-handshake-angle" aria-hidden="true"></i>
            </div>
            <h2 class="ref-partner-title">{{ __('site.partnership_form_title') }}</h2>
        </div>
        <p class="ref-partner-lead">{{ __('site.partnership_form_hint') }}</p>

        <div class="ref-partner-trust-pills">
            <div class="ref-trust-pill">
                <i class="fa-solid fa-bolt-lightning" aria-hidden="true"></i>
                <span>{{ __('site.partner_trust_badge_review') }}</span>
            </div>
            <div class="ref-trust-pill">
                <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                <span>{{ __('site.partner_trust_badge_confidential') }}</span>
            </div>
            <div class="ref-trust-pill">
                <i class="fa-solid fa-headset" aria-hidden="true"></i>
                <span>{{ __('site.partner_trust_badge_support') }}</span>
            </div>
        </div>
    </div>

    <div id="partner-apply-status" class="ref-form-status">
    @if(session('success'))
        <div class="ref-alert ref-success ref-flash-success" role="status">
            <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
            <span>{{ session('success') }}</span>
        </div>
    @endif
    @if($errors->any())
        <div class="ref-alert ref-error" role="alert">
            <div class="ref-error-header" style="display:flex;align-items:center;gap:8px;font-weight:700;margin-bottom:6px;">
                <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                <span>{{ __('site.form_fix_errors') }}</span>
            </div>
            <ul class="ref-form-errors" style="margin:0;padding-left:20px;">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    </div>

    <form method="post" action="/partnership/apply" class="ref-partner-apply-form">
        @csrf
        <input type="text" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true" class="ref-honeypot" style="position:absolute;left:-9999px;height:0;width:0;opacity:0;">

        {{-- Section 1: Partnership Tier Selection --}}
        <div class="ref-form-section">
            <div class="ref-section-header">
                <span class="ref-section-num">1</span>
                <div>
                    <h3 class="ref-section-title">{{ __('site.partner_section_tier') }}</h3>
                    <p class="ref-section-desc">{{ __('site.partnership_type') }}</p>
                </div>
            </div>

            <fieldset class="ref-role-fieldset" style="border:0;padding:0;margin:0;">
                <legend class="ref-sr-only">{{ __('site.partnership_type') }}</legend>
                <div class="ref-role-selector">
                    <label class="ref-role-card">
                        <input type="radio" name="role" value="dealer" @checked(old('role') === 'dealer') required class="ref-role-radio">
                        <div class="ref-role-box">
                            <div class="ref-role-head">
                                <div class="ref-role-icon"><i class="fa-solid fa-store" aria-hidden="true"></i></div>
                                <div class="ref-role-meta">
                                    <span class="ref-role-name">{{ __('site.role_dealer') }}</span>
                                    <span class="ref-role-tag dealer">{{ __('site.partner_dealer_badge') }}</span>
                                </div>
                                <div class="ref-role-check"><i class="fa-solid fa-circle-check" aria-hidden="true"></i></div>
                            </div>
                            <p class="ref-role-desc">{{ __('site.partner_dealer_desc') }}</p>
                        </div>
                    </label>

                    <label class="ref-role-card">
                        <input type="radio" name="role" value="sub_dealer" @checked(old('role', 'sub_dealer') === 'sub_dealer') required class="ref-role-radio">
                        <div class="ref-role-box">
                            <div class="ref-role-head">
                                <div class="ref-role-icon"><i class="fa-solid fa-shop" aria-hidden="true"></i></div>
                                <div class="ref-role-meta">
                                    <span class="ref-role-name">{{ __('site.role_sub_dealer') }}</span>
                                    <span class="ref-role-tag sub">{{ __('site.partner_sub_dealer_badge') }}</span>
                                </div>
                                <div class="ref-role-check"><i class="fa-solid fa-circle-check" aria-hidden="true"></i></div>
                            </div>
                            <p class="ref-role-desc">{{ __('site.partner_sub_dealer_desc') }}</p>
                        </div>
                    </label>
                </div>
            </fieldset>
        </div>

        {{-- Section 2: Personal & Contact Information --}}
        <div class="ref-form-section">
            <div class="ref-section-header">
                <span class="ref-section-num">2</span>
                <div>
                    <h3 class="ref-section-title">{{ __('site.partner_section_personal') }}</h3>
                </div>
            </div>

            <div class="ref-form-grid ref-grid-2">
                <div class="ref-field-group">
                    <label for="partner-name" class="ref-field-label">
                        <span>{{ __('site.full_name') }} <span class="ref-req">*</span></span>
                    </label>
                    <div class="ref-input-wrap">
                        <i class="fa-solid fa-user ref-input-icon" aria-hidden="true"></i>
                        <input id="partner-name" type="text" name="name" value="{{ old('name') }}" required maxlength="150" autocomplete="name" placeholder="{{ __('site.full_name') }}" class="ref-input-control">
                    </div>
                </div>

                <div class="ref-field-group">
                    <label for="partner-phone" class="ref-field-label">
                        <span>{{ __('site.mobile') }} <span class="ref-req">*</span></span>
                    </label>
                    <div class="ref-input-wrap">
                        <i class="fa-solid fa-phone ref-input-icon" aria-hidden="true"></i>
                        <input id="partner-phone" type="tel" name="phone" value="{{ old('phone') }}" required maxlength="30" autocomplete="tel" placeholder="01XXXXXXXXX" class="ref-input-control">
                    </div>
                </div>

                <div class="ref-field-group">
                    <label for="partner-email" class="ref-field-label">
                        <span>{{ __('site.email') }} <span class="ref-req">*</span></span>
                    </label>
                    <div class="ref-input-wrap">
                        <i class="fa-solid fa-envelope ref-input-icon" aria-hidden="true"></i>
                        <input id="partner-email" type="email" name="email" value="{{ old('email') }}" required maxlength="254" autocomplete="email" placeholder="example@email.com" class="ref-input-control">
                    </div>
                </div>

                <div class="ref-field-group">
                    <label for="partner-dob" class="ref-field-label">
                        <span>{{ __('site.partner_date_of_birth') }} <span class="ref-req">*</span></span>
                    </label>
                    <div class="ref-input-wrap">
                        <i class="fa-solid fa-cake-candles ref-input-icon" aria-hidden="true"></i>
                        <input id="partner-dob" type="date" name="date_of_birth" value="{{ old('date_of_birth') }}" required max="{{ \App\Domain\Accounts\PartnerAge::latestAllowedBirthDate() }}" autocomplete="bday" class="ref-input-control">
                    </div>
                    <small class="ref-field-hint">{{ __('site.partner_date_of_birth_hint') }}</small>
                </div>

                <div class="ref-field-group">
                    <label for="partner-sponsor" class="ref-field-label">
                        <span>{{ __('site.sponsor_code_optional') }}</span>
                        <span class="ref-badge-optional">{{ __('site.optional') }}</span>
                    </label>
                    <div class="ref-input-wrap">
                        <i class="fa-solid fa-id-badge ref-input-icon" aria-hidden="true"></i>
                        <input id="partner-sponsor" type="text" name="sponsor_code" value="{{ old('sponsor_code', request('sponsor_code')) }}" maxlength="40" autocomplete="off" placeholder="e.g. D-1042" class="ref-input-control">
                    </div>
                    <small class="ref-field-hint">{{ __('site.partner_sponsor_hint') }}</small>
                </div>
            </div>
        </div>

        {{-- Section 3: Proposed Business Territory --}}
        <div class="ref-form-section">
            <div class="ref-section-header">
                <span class="ref-section-num">3</span>
                <div>
                    <h3 class="ref-section-title">{{ __('site.partner_section_location') }}</h3>
                </div>
            </div>

            <div class="ref-form-grid ref-grid-3">
                <div class="ref-field-group">
                    <label for="partner-division" class="ref-field-label">
                        <span>{{ __('site.division') }} <span class="ref-req">*</span></span>
                    </label>
                    <div class="ref-input-wrap">
                        <i class="fa-solid fa-map ref-input-icon" aria-hidden="true"></i>
                        <input id="partner-division" type="text" name="division" list="bd-division-list" value="{{ old('division') }}" required maxlength="80" autocomplete="address-level1" placeholder="{{ __('site.division') }}" class="ref-input-control">
                        <datalist id="bd-division-list">
                            <option value="Dhaka">
                            <option value="Chattogram">
                            <option value="Rajshahi">
                            <option value="Khulna">
                            <option value="Barishal">
                            <option value="Sylhet">
                            <option value="Rangpur">
                            <option value="Mymensingh">
                            <option value="ঢাকা">
                            <option value="চট্টগ্রাম">
                            <option value="রাজশাহী">
                            <option value="খুলনা">
                            <option value="বরিশাল">
                            <option value="সিলেট">
                            <option value="রংপুর">
                            <option value="ময়মনসিংহ">
                        </datalist>
                    </div>
                </div>

                <div class="ref-field-group">
                    <label for="partner-district" class="ref-field-label">
                        <span>{{ __('site.district') }} <span class="ref-req">*</span></span>
                    </label>
                    <div class="ref-input-wrap">
                        <i class="fa-solid fa-city ref-input-icon" aria-hidden="true"></i>
                        <input id="partner-district" type="text" name="district" value="{{ old('district') }}" required maxlength="80" autocomplete="address-level2" placeholder="{{ __('site.district') }}" class="ref-input-control">
                    </div>
                </div>

                <div class="ref-field-group">
                    <label for="partner-upazila" class="ref-field-label">
                        <span>{{ __('site.upazila_optional') }}</span>
                        <span class="ref-badge-optional">{{ __('site.optional') }}</span>
                    </label>
                    <div class="ref-input-wrap">
                        <i class="fa-solid fa-location-dot ref-input-icon" aria-hidden="true"></i>
                        <input id="partner-upazila" type="text" name="upazila" value="{{ old('upazila') }}" maxlength="150" autocomplete="address-level3" placeholder="{{ __('site.upazila_optional') }}" class="ref-input-control">
                    </div>
                </div>
            </div>
        </div>

        {{-- Section 4: Experience / Additional Notes --}}
        <div class="ref-form-section" style="border-bottom:0;padding-bottom:0;margin-bottom:0;">
            <div class="ref-section-header">
                <span class="ref-section-num">4</span>
                <div>
                    <h3 class="ref-section-title">{{ __('site.partner_section_notes') }}</h3>
                </div>
            </div>

            <div class="ref-field-group">
                <label for="partner-message" class="ref-field-label">
                    <span>{{ __('site.message_optional') }}</span>
                    <span class="ref-badge-optional">{{ __('site.optional') }}</span>
                </label>
                <div class="ref-textarea-wrap">
                    <textarea id="partner-message" name="message" maxlength="2000" rows="4" class="ref-textarea-control" placeholder="{{ __('site.partner_message_placeholder') }}">{{ old('message') }}</textarea>
                </div>
            </div>
        </div>

        {{-- Submit Button & Trust Guarantee Note --}}
        <div class="ref-partner-submit-area">
            <p class="ref-field-hint">{{ __('site.partner_directory_notice') }}</p>
            <button type="submit" class="ref-partner-submit-btn">
                <span>{{ __('site.submit_application') }}</span>
                <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
            </button>
            <div class="ref-partner-trust-note">
                <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                <span>{{ __('site.partner_security_note') }}</span>
            </div>
        </div>
    </form>
</section>
@endif

@if($page==='services')
@include('reference.partials.page-plate', [
    'title' => $chrome('services_hero_title', $siteCustomization['services_hero_title'] ?? null),
    'lead' => $chrome('services_hero_lead', $siteCustomization['services_hero_lead'] ?? null),
    'icon' => 'fa-hand-holding-medical',
])
<div class="ref-about-sections-grid is-three">
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
@include('reference.partials.page-plate', [
    'title' => $content->title ?? $chrome('quality_hero_title', $siteCustomization['quality_hero_title'] ?? null),
    'lead' => $content->body ?? $chrome('quality_hero_lead', $siteCustomization['quality_hero_lead'] ?? null),
    'icon' => 'fa-award',
])
<div class="ref-about-sections-grid is-three">
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
    @if($publicPageEnabled['partnership'] ?? true)<a href="/partnership" class="ref-ghost-button">{{ __('site.become_a_partner') }}</a>@endif
</div>
@endif

@if($page==='faq')
@php
    $faqItems = \App\Support\SiteCustomization::faqItemsForDisplay($siteCustomization);
    $faqSecondPhone = \App\Support\SiteCustomization::secondPhone();
@endphp
@include('reference.partials.page-plate', [
    'title' => $chrome('faq_hero_title', $siteCustomization['faq_hero_title'] ?? null),
    'lead' => $chrome('faq_hero_lead', $siteCustomization['faq_hero_lead'] ?? null),
    'icon' => 'fa-circle-question',
])
<div class="ref-faq-layout">
    <div class="ref-faq-list">
        @foreach($faqItems as $item)
        <details class="ref-faq-item" name="faq">
            <summary>
                <span>{{ $item['question'] ?? '' }}</span>
                <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
            </summary>
            <p>{{ $item['answer'] ?? '' }}</p>
        </details>
        @endforeach
    </div>
    <aside class="ref-faq-help">
        <h2>{{ $chrome('contact_phone_title', $siteCustomization['contact_phone_title'] ?? null) }}</h2>
        <a class="ref-faq-help-phone" href="{{ \App\Support\SiteCustomization::phoneLink((string) ($siteCustomization['contact_phone'] ?? '')) }}">{{ $siteCustomization['contact_phone'] ?? '' }}</a>
        @if($faqSecondPhone)
        <a class="ref-faq-help-phone" href="{{ \App\Support\SiteCustomization::phoneLink($faqSecondPhone) }}">{{ $faqSecondPhone }}</a>
        @endif
        @if($publicPageEnabled['contact'] ?? true)<a href="/contact" class="ref-plate-button">{{ $chrome('faq_still_need_help') }}</a>@endif
        @if($publicPageEnabled['partnership'] ?? true)<a href="/partnership" class="ref-faq-help-link">{{ __('site.partnership_enquiries') }}</a>@endif
    </aside>
</div>
@endif

@if(in_array($page, ['privacy', 'terms', 'notice', 'circular-training', 'circular-price', 'circular-meeting'], true))
@php
    $isLegalPage = in_array($page, ['privacy', 'terms'], true);
    $hasNoticeText = filled($content->body ?? null);
@endphp
@component('reference.partials.page-plate', [
    'title' => $isLegalPage
        ? $chrome($page.'_hero_title', $siteCustomization[$page.'_hero_title'] ?? null)
        : ($content->title ?? ($siteCustomization[$page.'_hero_title'] ?? ($pageTitles[$page] ?? ucfirst($page)))),
    'lead' => $isLegalPage ? $chrome($page.'_hero_lead', $siteCustomization[$page.'_hero_lead'] ?? null) : null,
    'icon' => ['privacy' => 'fa-shield-halved', 'terms' => 'fa-scale-balanced'][$page] ?? 'fa-bullhorn',
])
    @if($isLegalPage)
    <span class="ref-legal-meta-item"><i class="fa-regular fa-calendar-check" aria-hidden="true"></i> {{ $chrome('legal_meta_effective', $siteCustomization['legal_meta_effective'] ?? null) }}</span>
    <span class="ref-legal-meta-item"><i class="fa-solid fa-building-shield" aria-hidden="true"></i> {{ $chrome('legal_meta_governance', $siteCustomization['legal_meta_governance'] ?? null) }}</span>
    <span class="ref-legal-meta-item"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> {{ $chrome('legal_meta_location', $siteCustomization['legal_meta_location'] ?? null) }}</span>
    @endif
@endcomponent
@if($isLegalPage)
    @include('reference.partials.trust-prose', ['text' => $chrome($page.'_body', $siteCustomization[$page.'_body'] ?? null), 'asPage' => true])
@elseif($hasNoticeText)
    @include('reference.partials.trust-prose', ['text' => $content->body, 'asPage' => true])
@else
    <div class="ref-empty ref-notice-empty">
        <i class="fa-solid fa-bullhorn" aria-hidden="true"></i>
        <p>{{ \App\Support\SiteChrome::ownerText('notice_empty', $siteCustomization['notice_empty'] ?? null, $siteCustomization['notice_empty_bn'] ?? null) }}</p>
    </div>
@endif
<div class="ref-information-links ref-legal-links">
    @if($publicPageEnabled['contact'] ?? true)<a href="/contact" class="ref-primary-button">{{ $chrome('contact_head_office', $siteCustomization['about_btn_contact'] ?? null) }}</a>@endif
    @if($isLegalPage)
        @php $altLegal = $page === 'privacy' ? 'terms' : 'privacy'; @endphp
        @if($publicPageEnabled[$altLegal] ?? true)
        <a href="/{{ $altLegal }}" class="ref-secondary-button">{{ $page === 'privacy' ? __('site.terms_of_use') : __('site.privacy_policy') }}</a>
        @endif
        @if($publicPageEnabled['faq'] ?? true)
        <a href="/faq">{{ __('site.faq') }}</a>
        @endif
    @elseif($publicPageEnabled['partnership'] ?? true)
        <a href="/partnership">{{ __('site.become_a_partner') }}</a>
    @endif
</div>
@endif
</main>
@endsection
