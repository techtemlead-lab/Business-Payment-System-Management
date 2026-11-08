@php $businessIdentity = \App\Domain\Content\PublicTrustContent::businessIdentity(); @endphp
@if($businessIdentity !== [])
<div class="ref-business-identity">
    <h3>{{ __('public_trust.identity_heading') }}</h3>
    <ul style="list-style:none;margin:0 0 14px;padding:0;">
        @foreach($businessIdentity as $identityItem)
        <li>{{ $identityItem['label'] }}: {{ $identityItem['value'] }}</li>
        @endforeach
    </ul>
</div>
@endif
