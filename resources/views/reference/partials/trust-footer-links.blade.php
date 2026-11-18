@foreach(\App\Domain\Content\PublicTrustContent::footerLinks() as $trustLink)
<a href="{{ \App\Support\PortalHost::publicPath($trustLink['url']) }}">{{ $trustLink['label'] }}</a>
@endforeach
