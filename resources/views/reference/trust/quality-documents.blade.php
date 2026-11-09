@php
    $trust = fn (string $key): string => \App\Domain\Content\PublicTrustContent::text($key);
    $pageTitle = $trust('quality_documents_title');
@endphp
@extends('reference.layout')
@section('title', $pageTitle.' · '.\App\Support\SiteCustomization::brandName())
@section('content')
<main id="main" class="ref-container ref-inner-main">
    <nav class="ref-breadcrumb" aria-label="{{ __('site.breadcrumb') }}"><a href="/" aria-label="{{ __('site.home') }}"><i class="fa-solid fa-house" aria-hidden="true"></i></a><span>/</span><span>{{ $pageTitle }}</span></nav>
    @include('reference.partials.page-plate', ['title' => $pageTitle, 'lead' => $trust('quality_documents_lead'), 'icon' => 'fa-certificate'])
    <div class="ref-about-sections-grid is-three">
        @foreach($documents as $document)
        <section class="ref-content-card">
            <div class="ref-about-icon"><i class="fa-solid {{ $document->isImage() ? 'fa-image' : 'fa-file-pdf' }}" aria-hidden="true"></i></div>
            <h2>{{ $document->title }}</h2>
            <a class="ref-secondary-button" href="{{ route('public.quality-documents.file', $document) }}" target="_blank" rel="noopener">{{ __('public_trust.quality_documents_open') }} <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i></a>
        </section>
        @endforeach
    </div>
</main>
@endsection
