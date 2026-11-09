@extends('reference.layout')
@section('title', $title.' · '.\App\Support\SiteCustomization::brandName())
@section('content')
<main id="main" class="ref-container ref-inner-main">
    <nav class="ref-breadcrumb" aria-label="{{ __('site.breadcrumb') }}"><a href="/" aria-label="{{ __('site.home') }}"><i class="fa-solid fa-house" aria-hidden="true"></i></a><span>/</span><span>{{ $title }}</span></nav>
    @include('reference.partials.page-plate', [
        'title' => $title,
        'lead' => $lead,
        'icon' => ['refund-policy' => 'fa-rotate-left', 'delivery-policy' => 'fa-truck-fast', 'code-of-conduct' => 'fa-handshake', 'partner-exit-policy' => 'fa-door-open'][$page] ?? 'fa-file-lines',
    ])
    @include('reference.partials.trust-prose', ['text' => $body, 'asPage' => true])
    <div class="ref-information-links ref-legal-links">
        @if(\App\Domain\Content\PublicTrustContent::isVisible('grievance'))<a href="/grievance" class="ref-primary-button">{{ __('public_trust.footer_grievance') }}</a>@endif
        @if($publicPageEnabled['contact'] ?? true)<a href="/contact" class="ref-secondary-button">{{ __('site.contact_us') }}</a>@endif
    </div>
</main>
@endsection
