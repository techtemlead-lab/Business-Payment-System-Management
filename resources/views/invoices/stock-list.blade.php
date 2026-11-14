@use('App\Support\LocalDigits')
@use('App\Support\Money')
@php
    $digits = static fn ($value): string => LocalDigits::text((string) $value);
    $amount = static fn (?string $value): string => $value === null ? '—' : '৳ '.$digits(Money::grouped($value));
    $percent = static fn (?string $value): string => $value === null ? '—' : $digits($value).'%';
    $day = static fn (?string $value): string => $value === null ? '—' : $digits(\Carbon\CarbonImmutable::parse($value)->format('d/m/Y'));
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('stock_print.title') }}</title>
    <style>
        body { font-family: freeserif, sans-serif; color: #0f172a; font-size: 9.5pt; margin: 0; }
        table.header { width: 100%; border-collapse: collapse; }
        table.header td { vertical-align: middle; }
        .logo-cell { width: 64px; }
        .brand-name { font-size: 17pt; font-weight: bold; color: #006837; }
        .brand-line { font-size: 9pt; color: #334155; }
        .title { text-align: center; margin: 8px 0 6px; }
        .title span { font-size: 14pt; font-weight: bold; color: #006837; border-bottom: 2px solid #006837; padding: 0 18px 2px; }
        table.meta { width: 100%; border-collapse: collapse; margin-bottom: 6px; font-size: 9.5pt; }
        table.items { width: 100%; border-collapse: collapse; }
        table.items th { background: #e3f1eb; color: #00482f; border: 1px solid #94a3b8; padding: 4px 3px; text-align: center; font-size: 9pt; }
        table.items td { border: 1px solid #cbd5e1; padding: 3px 4px; vertical-align: middle; }
        table.items tr { page-break-inside: avoid; }
        .num { text-align: right; }
        .mid { text-align: center; }
        tr.grand td { background: #f1f5f9; border-top: 2px solid #006837; font-weight: bold; }
        .note { font-size: 8pt; color: #64748b; margin-top: 8px; line-height: 1.5; }
        .empty { text-align: center; color: #64748b; padding: 14px 0; }
    </style>
</head>
<body>
<table class="header">
    <tr>
        <td class="logo-cell">
            @if (! empty($company['logo_path']))
                <img src="{{ $company['logo_path'] }}" alt="" width="58" height="58">
            @endif
        </td>
        <td style="text-align:center">
            <div class="brand-name">{{ $company['name'] }}</div>
            <div class="brand-line">{{ $company['address'] }}</div>
            @if ($company['phones'] !== '')
                <div class="brand-line">{{ __('portal.inv_mobile_short') }}: {{ $digits($company['phones']) }}</div>
            @endif
        </td>
        <td class="logo-cell"></td>
    </tr>
</table>

<div class="title"><span>{{ __('stock_print.title') }}</span></div>

<table class="meta">
    <tr>
        <td>{{ __('stock_print.branch') }} : <b>{{ $branch !== '' ? $branch : '—' }}</b></td>
        <td style="text-align:right">{{ __('stock_print.printed_on') }} : <b>{{ $digits($printed_at->format('d/m/Y')) }}</b></td>
    </tr>
</table>

<table class="items" repeat_header="1">
    <thead>
        <tr>
            <th style="width:5%">{{ __('stock_print.col_serial') }}</th>
            <th style="width:24%">{{ __('stock_print.col_product') }}</th>
            <th style="width:11%">{{ __('stock_print.col_pack') }}</th>
            <th style="width:11%">{{ __('stock_print.col_expiry') }}</th>
            <th style="width:8%">{{ __('stock_print.col_quantity') }}</th>
            <th style="width:9%">{{ __('stock_print.col_mrp') }}</th>
            <th style="width:8%">{{ __('stock_print.col_dealer_discount') }}</th>
            <th style="width:9%">{{ __('stock_print.col_sub_dealer_discount') }}</th>
            <th style="width:15%">{{ __('stock_print.col_total') }}</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($rows as $row)
            <tr>
                <td class="mid">{{ $digits($row['serial']) }}</td>
                <td>{{ $row['name'] }}</td>
                <td class="mid">{{ $row['pack_size'] ?? '—' }}</td>
                <td class="mid">{{ $day($row['expires_on']) }}</td>
                <td class="num">{{ $digits($row['quantity']) }}</td>
                <td class="num">{{ $amount($row['mrp']) }}</td>
                <td class="mid">{{ $percent($row['dealer_discount']) }}</td>
                <td class="mid">{{ $percent($row['sub_dealer_discount']) }}</td>
                <td class="num">{{ $amount($row['total']) }}</td>
            </tr>
        @empty
            <tr><td colspan="9" class="empty">{{ __('stock_print.empty') }}</td></tr>
        @endforelse
        @if ($rows !== [])
            <tr class="grand">
                <td colspan="4" class="num">{{ __('stock_print.grand_total') }}</td>
                <td class="num">{{ $digits($total_quantity) }}</td>
                <td colspan="3"></td>
                <td class="num">{{ $amount($total_value) }}</td>
            </tr>
        @endif
    </tbody>
</table>

<div class="note">{{ __('stock_print.note') }}</div>
</body>
</html>
