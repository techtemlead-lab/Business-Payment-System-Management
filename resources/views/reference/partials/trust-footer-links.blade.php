@foreach(\App\Domain\Content\PublicTrustContent::footerLinks() as $trustLink)
<a href="{{ $trustLink['url'] }}">{{ $trustLink['label'] }}</a>
@endforeach
