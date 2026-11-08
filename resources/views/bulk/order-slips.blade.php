@php($t = fn (string $key, array $replace = []): string => (string) __('productivity.'.$key, $replace))
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $t('slips_title') }}</title>
    <style>
        body { font-family: 'Hind Siliguri', 'Segoe UI', sans-serif; margin: 0; background: #f3f5f2; color: #153d30; font-size: 14px; }
        .toolbar { display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; background: #fff; border-bottom: 1px solid #dbe2d8; }
        .toolbar button { padding: 8px 16px; font: inherit; cursor: pointer; }
        .slip { background: #fff; margin: 16px auto; padding: 20px; max-width: 720px; border: 1px solid #dbe2d8; }
        .slip h2 { margin: 0 0 4px; font-size: 18px; }
        .slip dl { display: grid; grid-template-columns: 110px 1fr; gap: 2px 10px; margin: 10px 0; }
        .slip dt { color: #52665d; } .slip dd { margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 6px; border-bottom: 1px solid #e4e9e2; }
        td.qty, th.qty { text-align: right; }
        @media print { body { background: #fff; } .toolbar { display: none; } .slip { margin: 0; border: 0; max-width: none; page-break-after: always; } .slip:last-child { page-break-after: auto; } }
    </style>
</head>
<body>
<div class="toolbar">
    <b>{{ $t('slips_count', ['count' => $orders->count()]) }}</b>
    <button type="button" data-print>{{ $t('slips_print') }}</button>
</div>
@forelse ($orders as $order)
    <section class="slip">
        <h2>{{ $order->number }}</h2>
        <dl>
            <dt>{{ $t('slips_date') }}</dt><dd>{{ $order->created_at?->timezone('Asia/Dhaka')->format('d M Y') }}</dd>
            <dt>{{ $t('slips_partner') }}</dt><dd>{{ $order->user?->name }} @if ($order->user?->dealer_code) ({{ $order->user->dealer_code }}) @endif</dd>
            <dt>{{ $t('slips_phone') }}</dt><dd>{{ \App\Support\PhoneIdentity::maskedForPrint($order->user?->phone) }}</dd>
            <dt>{{ $t('slips_area') }}</dt><dd>{{ collect([$order->user?->district, $order->user?->division])->filter()->join(', ') }}</dd>
            <dt>{{ $t('slips_branch') }}</dt><dd>{{ $order->fulfillmentBranch?->name }}</dd>
        </dl>
        <table>
            <thead><tr><th>{{ $t('slips_item') }}</th><th>{{ $t('slips_sku') }}</th><th class="qty">{{ $t('slips_quantity') }}</th></tr></thead>
            <tbody>
            @foreach ($order->lines as $line)
                <tr><td>{{ $line->name }}</td><td>{{ $line->sku }}</td><td class="qty">{{ $line->quantity }}</td></tr>
            @endforeach
            </tbody>
        </table>
    </section>
@empty
    <section class="slip"><p>{{ $t('slips_none') }}</p></section>
@endforelse
<script nonce="{{ $csp_nonce ?? '' }}">
    document.querySelectorAll('[data-print]').forEach(function (button) {
        button.addEventListener('click', function () { window.print(); });
    });
</script>
</body>
</html>
