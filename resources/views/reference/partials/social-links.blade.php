@php $socialLinks = \App\Support\SiteCustomization::socialLinks(); @endphp
@if($socialLinks !== [])
<div class="ref-socials">
    @foreach($socialLinks as $socialLink)
    <a class="ref-social is-{{ $socialLink['network'] }}" href="{{ $socialLink['url'] }}" target="_blank" rel="noopener noreferrer" aria-label="{{ $socialLink['label'] }}" title="{{ $socialLink['label'] }}">
        @if($socialLink['icon'] !== '')
        <i class="fa-brands {{ $socialLink['icon'] }}" aria-hidden="true"></i>
        @else
        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="{{ $socialLink['path'] }}"/></svg>
        @endif
    </a>
    @endforeach
</div>
@endif
