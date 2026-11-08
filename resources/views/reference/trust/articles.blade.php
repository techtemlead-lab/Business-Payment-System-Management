@php
    $trust = fn (string $key): string => \App\Domain\Content\PublicTrustContent::text($key);
    $pageTitle = $trust('articles_title');
@endphp
@extends('reference.layout')
@section('title', $pageTitle.' · '.\App\Support\SiteCustomization::brandName())
@section('content')
<main id="main" class="ref-container ref-inner-main">
    <nav class="ref-breadcrumb" aria-label="{{ __('site.breadcrumb') }}"><a href="/" aria-label="{{ __('site.home') }}"><i class="fa-solid fa-house" aria-hidden="true"></i></a><span>/</span><span>{{ $pageTitle }}</span></nav>
    <article class="ref-content-card ref-about-hero">
        <h1>{{ $pageTitle }}</h1>
        <div class="ref-content-prose">{{ $trust('articles_lead') }}</div>
    </article>
    <div class="ref-about-sections-grid">
        @foreach($articles as $article)
        <section class="ref-content-card">
            @if($article->image_url)
            <img src="{{ $article->image_url }}" alt="" loading="lazy" decoding="async" style="width:100%;height:auto;max-height:200px;object-fit:cover;border-radius:8px;margin-bottom:14px;">
            @endif
            <h2>{{ $article->title }}</h2>
            <p>{{ $article->excerpt() }}</p>
            <a class="ref-secondary-button" href="{{ route('public.articles.show', $article) }}">{{ __('public_trust.articles_read') }} <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
        </section>
        @endforeach
    </div>
    @if($articles->hasPages())
    <div style="margin-top:22px;">{{ $articles->links() }}</div>
    @endif
    <p class="ref-form-hint" style="margin-top:22px;">{{ $trust('articles_disclaimer') }}</p>
</main>
@endsection
