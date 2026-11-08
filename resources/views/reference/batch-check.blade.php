@extends('reference.layout')
@section('title', __('stock_lots.public_title').' · '.\App\Support\SiteCustomization::brandName())
@section('content')
<main id="main" class="ref-container ref-inner-main">
    <nav class="ref-breadcrumb" aria-label="{{ __('site.breadcrumb') }}">
        <a href="/" aria-label="{{ __('site.home') }}"><i class="fa-solid fa-house" aria-hidden="true"></i></a>
        <span>/</span>
        <span>{{ __('stock_lots.public_title') }}</span>
    </nav>

    <h1>{{ __('stock_lots.public_title') }}</h1>
    <p>{{ __('stock_lots.public_intro') }}</p>

    <form method="get" action="/batch-check">
        <label for="batch">{{ __('stock_lots.public_label') }}</label>
        <input id="batch" name="batch" type="text" maxlength="60" required value="{{ $batch }}" autocomplete="off">
        <button type="submit">{{ __('stock_lots.public_submit') }}</button>
    </form>

    @if($batch !== '' && $results === [])
        <div class="ref-alert ref-notice" role="status"><b>{{ __('stock_lots.public_not_found') }}</b></div>
    @endif

    @foreach($results as $result)
        <section aria-label="{{ __('stock_lots.public_result_title', ['batch' => $batch]) }}">
            <h2>{{ __('stock_lots.public_result_title', ['batch' => $batch]) }}</h2>
            <dl>
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
