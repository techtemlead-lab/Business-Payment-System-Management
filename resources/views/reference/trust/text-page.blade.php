@extends('reference.layout')
@section('title', $title.' · '.\App\Support\SiteCustomization::brandName())
@section('content')
<main id="main" class="ref-container ref-inner-main">
    <nav class="ref-breadcrumb" aria-label="{{ __('site.breadcrumb') }}"><a href="/" aria-label="{{ __('site.home') }}"><i class="fa-solid fa-house" aria-hidden="true"></i></a><span>/</span><span>{{ $title }}</span></nav>
    <article class="ref-content-card ref-about-hero ref-legal-hero">
        <h1>{{ $title }}</h1>
        @if(filled($lead))
        <div class="ref-content-prose ref-legal-lead">{{ $lead }}</div>
        @endif
    </article>
    <section class="ref-content-card ref-legal-body-card" style="margin-top:22px;">
        @include('reference.partials.trust-prose', ['text' => $body])
    </section>
    <div class="ref-information-links ref-legal-links">
        @if(\App\Domain\Content\PublicTrustContent::isVisible('grievance'))<a href="/grievance" class="ref-primary-button">{{ __('public_trust.footer_grievance') }}</a>@endif
        @if($publicPageEnabled['contact'] ?? true)<a href="/contact" class="ref-secondary-button">{{ __('site.contact_us') }}</a>@endif
    </div>
</main>
@endsection
