@php
    $trust = fn (string $key): string => \App\Domain\Content\PublicTrustContent::text($key);
    $pageTitle = $trust('articles_title');
@endphp
@extends('reference.layout')
@section('title', $pageTitle.' · '.\App\Support\SiteCustomization::brandName())
@section('content')
<main id="main" class="ref-container ref-inner-main">
    <nav class="ref-breadcrumb" aria-label="{{ __('site.breadcrumb') }}"><a href="/" aria-label="{{ __('site.home') }}"><i class="fa-solid fa-house" aria-hidden="true"></i></a><span>/</span><span>{{ $pageTitle }}</span></nav>
    @include('reference.partials.page-plate', ['title' => $pageTitle, 'lead' => $trust('articles_lead'), 'icon' => 'fa-book-open'])
    <div class="ref-about-sections-grid is-three">
        @foreach($articles as $article)
        <section class="ref-content-card">
            @if($article->image_url)
            <img class="ref-article-picture" src="{{ $article->image_url }}" alt="" loading="lazy" decoding="async">
            @else
            @php
                $stockImage = \App\Support\StockImage::forSection('article');
            @endphp
            <img class="ref-article-picture" src="{{ $stockImage['url'] }}" alt="{{ $stockImage['alt'] }}" width="{{ $stockImage['width'] }}" height="{{ $stockImage['height'] }}" loading="lazy" decoding="async">
            @endif
            <h2>{{ $article->displayTitle() }}</h2>
            <p>{{ $article->excerpt() }}</p>
            <a class="ref-secondary-button" href="{{ route('public.articles.show', $article) }}">{{ __('public_trust.articles_read') }}</a>
        </section>
        @endforeach
    </div>
    @if($articles->hasPages())
    <div class="ref-page-note">{{ $articles->links() }}</div>
    @endif
    <p class="ref-form-hint ref-page-note">{{ $trust('articles_disclaimer') }}</p>
</main>
@endsection
