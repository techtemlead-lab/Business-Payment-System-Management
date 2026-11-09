@extends('reference.layout')
@section('title', __('stock_lots.public_title').' · '.\App\Support\SiteCustomization::brandName())
@section('content')
<main id="main" class="ref-container ref-inner-main">
    <nav class="ref-breadcrumb" aria-label="{{ __('site.breadcrumb') }}">
        <a href="/" aria-label="{{ __('site.home') }}"><i class="fa-solid fa-house" aria-hidden="true"></i></a>
        <span>/</span>
        <span>{{ __('stock_lots.public_title') }}</span>
    </nav>

    @include('reference.partials.page-plate', [
        'title' => __('stock_lots.public_title'),
        'lead' => __('stock_lots.public_intro'),
        'icon' => 'fa-barcode',
    ])

    <section class="ref-content-card ref-form-page">
        <form class="ref-form ref-inline-search" method="get" action="/batch-check">
            <label for="batch">{{ __('stock_lots.public_label') }}</label>
            <div class="ref-inline-search-row">
                <input id="batch" name="batch" type="text" maxlength="60" required value="{{ $batch }}" autocomplete="off">
                <button class="ref-primary-button" type="submit">{{ __('stock_lots.public_submit') }} <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i></button>
            </div>
        </form>

        @if($batch !== '' && $results === [])
            <div class="ref-alert ref-notice ref-search-outcome" role="status"><b>{{ __('stock_lots.public_not_found') }}</b></div>
        @endif
    </section>

    @foreach($results as $result)
        <section class="ref-content-card ref-form-page" aria-label="{{ __('stock_lots.public_result_title', ['batch' => $batch]) }}">
            <h2>{{ __('stock_lots.public_result_title', ['batch' => $batch]) }}</h2>
            <dl class="ref-facts">
                <dt>{{ __('stock_lots.public_product') }}</dt>
                <dd>{{ $result['product'] }}</dd>
                <dt>{{ __('stock_lots.public_expiry') }}</dt>
                <dd>{{ $result['expires_on'] ? \Carbon\CarbonImmutable::parse($result['expires_on'])->format('d/m/Y') : __('stock_lots.public_expiry_unknown') }}</dd>
                @if (filled($result['maker']))
                    <dt>{{ __('stock_lots.public_maker') }}</dt>
                    <dd>{{ $result['maker'] }}</dd>
                @endif
            </dl>
        </section>
    @endforeach
</main>
@endsection
