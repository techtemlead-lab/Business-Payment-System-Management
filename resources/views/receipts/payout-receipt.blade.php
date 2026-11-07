<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('portal.prc_title') }} {{ $receipt['number'] }} — {{ $company['name'] }}</title>
    <style id="page-size">@page { size: A4 portrait; margin: 12mm; }</style>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; color: #0f172a; background: #f1f5f9; font: 12px/1.45 "Hind Siliguri", "Noto Sans Bengali", freeserif, Arial, sans-serif; }
        .toolbar { position: sticky; top: 0; z-index: 5; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: space-between; padding: 10px 16px; background: #0f172a; color: #fff; }
        .toolbar b { font-size: 14px; }
        .toolbar .actions { display: flex; flex-wrap: wrap; gap: 8px; }
        .toolbar button { border: 0; border-radius: 6px; padding: 7px 14px; font: inherit; font-weight: 600; cursor: pointer; background: #fff; color: #0f172a; }
        .toolbar button.primary { background: #006837; color: #fff; }
        .sheet { position: relative; width: 210mm; max-width: calc(100% - 24px); margin: 16px auto; padding: 14mm 16mm; background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; }
        .copy { position: absolute; top: 10mm; right: 14mm; padding: 2px 8px; border: 1px solid #006837; border-radius: 4px; color: #006837; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; }
        .head { display: flex; gap: 12px; align-items: center; justify-content: center; text-align: center; border-bottom: 2px solid #006837; padding-bottom: 8px; }
        .head img { width: 56px; height: 56px; object-fit: contain; }
        .brand { font-size: 20px; font-weight: 700; color: #006837; margin: 0; }
        .muted { color: #475569; }
        h1 { text-align: center; font-size: 17px; letter-spacing: .04em; margin: 12px 0 10px; color: #006837; }
        .meta { display: flex; justify-content: space-between; gap: 12px; font-weight: 600; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; }
        .info td { padding: 4px 6px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        .info td.k { width: 38%; color: #334155; font-weight: 600; }
        .money { margin-top: 12px; }
        .money td { padding: 6px; border-bottom: 1px solid #cbd5e1; }
        .money td.amt { text-align: right; white-space: nowrap; font-weight: 600; }
        .money tr.net td { font-size: 14px; font-weight: 700; border-top: 2px solid #0f172a; border-bottom: 2px solid #0f172a; }
        .words { margin-top: 10px; padding: 6px 8px; background: #f8fafc; border-left: 3px solid #006837; }
        .signs { display: flex; justify-content: space-between; gap: 24px; margin-top: 46px; text-align: center; font-weight: 600; }
        .signs div { flex: 1; border-top: 1px solid #0f172a; padding-top: 4px; }
        .foot { margin-top: 18px; font-size: 10px; text-align: center; color: #64748b; }

        /* 80 mm receipt printer */
        body.thermal .sheet { width: 76mm; max-width: 76mm; padding: 3mm; border: 0; border-radius: 0; font-size: 11px; }
        body.thermal .head { flex-direction: column; gap: 4px; }
        body.thermal .head img { width: 36px; height: 36px; }
        body.thermal .brand { font-size: 14px; }
        body.thermal h1 { font-size: 13px; margin: 6px 0; }
        body.thermal .copy { position: static; display: inline-block; margin-bottom: 4px; }
        body.thermal .meta { flex-direction: column; gap: 2px; }
        body.thermal .info td.k { width: 45%; }
        body.thermal .signs { margin-top: 28px; gap: 10px; font-size: 10px; }

        @media print {
            body { background: #fff; }
            .toolbar { display: none !important; }
            .sheet { margin: 0; border: 0; max-width: 100%; width: 100%; padding: 0; }
            body.thermal .sheet { width: 72mm; max-width: 72mm; }
        }
    </style>
</head>
<body>
<div class="toolbar">
    <b>{{ __('portal.prc_title') }} — {{ $receipt['number'] }}</b>
    <div class="actions">
        <button type="button" class="primary" data-print="a4">{{ __('portal.prc_print_a4') }}</button>
        <button type="button" data-print="thermal">{{ __('portal.prc_print_thermal') }}</button>
    </div>
</div>

<main class="sheet">
    <span class="copy">{{ $copy_label }}</span>
    <div class="head">
        <img src="{{ $company['logo_url'] }}" alt="" width="56" height="56">
        <div>
            <p class="brand">{{ $company['name'] }}</p>
            <div class="muted">{{ $company['address'] }}</div>
            <div class="muted">{{ __('portal.inv_mobile_short') }}: {{ $company['phones'] }}</div>
        </div>
    </div>

    <h1>{{ __('portal.prc_heading') }}</h1>

    <div class="meta">
        <span>{{ __('portal.prc_number') }}: {{ $receipt['number'] }}</span>
        <span>{{ __('portal.prc_date') }}: {{ $receipt['paid_on'] }} {{ $receipt['paid_time'] }}</span>
    </div>

    <table class="info">
        <tr><td class="k">{{ __('portal.prc_paid_to') }}</td><td>{{ $partner['name'] }}</td></tr>
        <tr><td class="k">{{ __('portal.inv_dealer_code') }}</td><td>{{ $partner['code'] }}</td></tr>
        <tr><td class="k">{{ __('portal.inv_designation') }}</td><td>{{ $partner['designation'] }}</td></tr>
        <tr><td class="k">{{ __('portal.inv_mobile') }}</td><td>{{ $partner['phone'] }}</td></tr>
        <tr><td class="k">{{ __('portal.prc_method') }}</td><td>{{ $receipt['method'] }}</td></tr>
        @if ($receipt['reference'])
            <tr><td class="k">{{ __('portal.prc_reference') }}</td><td>{{ $receipt['reference'] }}</td></tr>
        @endif
        @if ($receipt['bank_name'])
            <tr><td class="k">{{ __('portal.prc_bank_name') }}</td><td>{{ $receipt['bank_name'] }}</td></tr>
        @endif
        @if ($receipt['account_masked'])
            <tr><td class="k">{{ $receipt['account_label'] }}</td><td>{{ $receipt['account_masked'] }}</td></tr>
        @endif
        @if ($receipt['is_cash'] && $receipt['received_by'])
            <tr><td class="k">{{ __('portal.prc_received_by') }}</td><td>{{ $receipt['received_by'] }}</td></tr>
        @endif
        <tr><td class="k">{{ __('portal.prc_paid_by') }}</td><td>{{ $paid_by }}</td></tr>
        <tr><td class="k">{{ __('portal.prc_withdrawal_ref') }}</td><td>{{ $receipt['withdrawal_ref'] }}</td></tr>
    </table>

    <table class="money">
        <tr><td>{{ __('portal.prc_commission_amount') }}</td><td class="amt">৳ {{ $receipt['amount'] }}</td></tr>
        @if ($receipt['has_withholding'])
            <tr><td>{{ __('portal.prc_tax_withheld') }}</td><td class="amt">− ৳ {{ $receipt['withheld'] }}</td></tr>
        @endif
        <tr class="net"><td>{{ __('portal.prc_net_paid') }}</td><td class="amt">৳ {{ $receipt['net'] }}</td></tr>
    </table>

    <div class="words"><strong>{{ __('portal.inv_in_words') }}:</strong> {{ $receipt['net_in_words'] }}</div>

    <div class="signs">
        <div>{{ __('portal.prc_sign_receiver') }}</div>
        <div>{{ __('portal.prc_sign_payer') }}</div>
    </div>

    <p class="foot">{{ __('portal.prc_footer') }}</p>
</main>

<script nonce="{{ $csp_nonce }}">
    document.querySelectorAll('[data-print]').forEach(function (button) {
        button.addEventListener('click', function () {
            var thermal = button.getAttribute('data-print') === 'thermal';
            document.body.classList.toggle('thermal', thermal);
            document.getElementById('page-size').textContent = thermal
                ? '@page { size: 80mm auto; margin: 3mm; }'
                : '@page { size: A4 portrait; margin: 12mm; }';
            window.print();
        });
    });
</script>
</body>
</html>
