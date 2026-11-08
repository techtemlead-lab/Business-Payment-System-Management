@php($t = fn (string $key): string => (string) __('shipping_label.'.$key))
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $t('page_title') }} · {{ $order->number }}</title>
    <style>
        body { font-family: 'Hind Siliguri', 'Segoe UI', sans-serif; margin: 0; background: #f3f5f2; color: #111; font-size: 15px; }
        .label { background: #fff; margin: 16px auto; padding: 20px; max-width: 520px; border: 2px solid #111; }
        h2 { margin: 0 0 6px; font-size: 13px; text-transform: uppercase; letter-spacing: .04em; color: #444; }
        .receiver { font-size: 22px; line-height: 1.35; margin-bottom: 16px; }
        .receiver p, .sender p { margin: 0 0 4px; }
        .receiver .phone { font-size: 26px; font-weight: 700; }
        .sender { font-size: 14px; border-top: 1px dashed #777; padding-top: 12px; margin-bottom: 12px; }
        dl { display: grid; grid-template-columns: 140px 1fr; gap: 3px 10px; margin: 0; border-top: 1px dashed #777; padding-top: 12px; }
        dt { color: #444; } dd { margin: 0; font-weight: 600; }
        @media print { body { background: #fff; } .label { margin: 0; max-width: none; border-width: 1px; } }
    </style>
</head>
<body>
<section class="label">
    <h2>{{ $t('to') }}</h2>
    <div class="receiver">
        <p><b>{{ $receiver['name'] }}</b></p>
        <p>{{ $receiver['address'] }}</p>
        <p class="phone">{{ $receiver['phone'] }}</p>
    </div>
    <div class="sender">
        <h2>{{ $t('from') }}</h2>
        <p><b>{{ $sender['name'] }}</b></p>
        <p>{{ $sender['address'] }}</p>
        <p>{{ $sender['phone'] }}</p>
    </div>
    <dl>
        <dt>{{ $t('order') }}</dt><dd>{{ $order->number }}</dd>
        <dt>{{ $t('date') }}</dt><dd>{{ $order->created_at?->timezone('Asia/Dhaka')->format('d M Y') }}</dd>
        <dt>{{ $t('pieces') }}</dt><dd>{{ $pieces }}</dd>
        @if ($courier)
            <dt>{{ $t('courier') }}</dt><dd>{{ $courier }}</dd>
        @endif
        @if ($consignment)
            <dt>{{ $t('consignment') }}</dt><dd>{{ $consignment }}</dd>
        @endif
    </dl>
</section>
<script nonce="{{ $csp_nonce ?? '' }}">
    window.addEventListener('load', function () { window.print(); });
</script>
</body>
</html>
