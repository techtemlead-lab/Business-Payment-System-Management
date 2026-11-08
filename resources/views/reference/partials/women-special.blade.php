{{-- Women special block: all text, the picture and the on/off switch come from the website editor. --}}
@php
    $womenSpecial = \App\Support\SiteCustomization::womenSpecial($siteCustomization ?? null);
@endphp
@if($womenSpecial)
<section class="ref-women-special" aria-labelledby="women-special-title">
    <div class="ref-women-copy">
        <h2 id="women-special-title">{{ $womenSpecial['title'] }}</h2>
        @if($womenSpecial['body'] !== '')
        <p>{{ $womenSpecial['body'] }}</p>
        @endif
        @if($womenSpecial['link'] !== null)
        <a href="{{ $womenSpecial['link'] }}" class="ref-primary-button">{{ $womenSpecial['button'] }} <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
        @endif
    </div>
    @if($womenSpecial['image'] !== '')
    <div class="ref-women-picture"><img src="{{ $womenSpecial['image'] }}" alt="{{ $womenSpecial['title'] }}" loading="eager" decoding="async"></div>
    @else
    <i class="fa-solid fa-spa ref-women-mark" aria-hidden="true"></i>
    @endif
</section>
@endif
