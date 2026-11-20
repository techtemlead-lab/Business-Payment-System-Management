@use('App\Support\LocalDigits')
@use('App\Support\Money')
@php
    $digits = static fn ($value): string => LocalDigits::text((string) $value);
    $amount = static fn (?string $value): string => $value === null ? '—' : '৳ '.$digits(Money::grouped($value));
    $withPictures = $withPictures ?? true;
    $percent = static fn (?string $value): string => $value === null ? '—' : $digits($value).'%';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('product_list_print.title') }}</title>
    <style>
        body { font-family: freeserif, sans-serif; color: #0f172a; font-size: 11pt; margin: 0; }
        table.header { width: 100%; border-collapse: collapse; }
        table.header td { vertical-align: top; }
        .logo-cell { width: 165px; text-align: left; }
        .legal-cell { width: 245px; text-align: right; }
        .brand-name { font-size: 25pt; font-weight: bold; line-height: 1.05; color: #006837; }
        .brand-line { font-size: 13pt; color: #334155; line-height: 1.3; }
        .title { text-align: center; margin: 8px 0 8px; }
        .title span { font-size: 18pt; font-weight: bold; color: #006837; border-bottom: 2px solid #006837; padding: 0 18px 2px; }
        table.items { width: 100%; border-collapse: collapse; }
        table.items th { background: #e3f1eb; color: #00482f; border: 1px solid #94a3b8; padding: 5px 2px; text-align: center; font-size: 10.5pt; }
        table.items td { border: 1px solid #cbd5e1; padding: 4px 5px; vertical-align: middle; font-size: 11pt; }
        table.items tr { page-break-inside: avoid; }
        .num { text-align: right; }
        .mid { text-align: center; }
        .note { font-size: 10.5pt; color: #64748b; margin-top: 8px; line-height: 1.5; }
        @if (! $withPictures)
        body { font-size: 9.5pt; }
        table.items th { padding: 3px 2px; font-size: 9pt; }
        table.items td { padding: 2px 4px; font-size: 9.5pt; }
        .title { margin: 4px 0; }
        @endif
        .empty { text-align: center; color: #64748b; padding: 14px 0; }
    </style>
</head>
<body>
@include('partials.print-letterhead', ['company' => $company])

<div class="title"><span>{{ __('product_list_print.title') }}</span></div>

<table class="items" repeat_header="1">
    <thead>
        <tr>
            <th style="width:4%">{{ __('product_list_print.col_serial') }}</th>
            @if ($withPictures)<th style="width:13%">{{ __('product_list_print.col_image') }}</th>@endif
            <th style="width:12%">{{ __('product_list_print.col_product') }}</th>
            <th style="width:7%">{{ __('product_list_print.col_pack') }}</th>
            <th style="width:8%">{{ __('product_list_print.col_group') }}</th>
            <th style="width:8%">{{ __('product_list_print.col_category') }}</th>
            <th style="width:12%">{{ __('product_list_print.col_efficacy') }}</th>
            <th style="width:12%">{{ __('product_list_print.col_usage') }}</th>
            <th style="width:8%">{{ __('product_list_print.col_mrp') }}</th>
            <th style="width:8%">{{ __('product_list_print.col_dealer_discount') }}</th>
            <th style="width:9%">{{ __('product_list_print.col_sub_dealer_discount') }}</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($rows as $row)
            <tr>
                <td class="mid">{{ $digits($row['serial']) }}</td>
                @if ($withPictures)
                <td class="mid">
                    @if (! empty($row['image']))
                        <img src="{{ $row['image'] }}" alt="" width="{{ $row['image_width'] ?? \App\Domain\Inventory\PdfPhotos::DisplayPixels }}" height="{{ $row['image_height'] ?? \App\Domain\Inventory\PdfPhotos::DisplayPixels }}">
                    @else
                        —
                    @endif
                </td>
                @endif
                <td>{{ $row['name'] }}</td>
                <td class="mid">{{ $row['pack_size'] ?? '—' }}</td>
                <td class="mid">{{ $row['group'] ?? '—' }}</td>
                <td class="mid">{{ $row['category'] ?? '—' }}</td>
                <td>{!! nl2br(e($row['efficacy'] ?? '—')) !!}</td>
                <td>{!! nl2br(e($row['usage'] ?? '—')) !!}</td>
                <td class="num">{{ $amount($row['mrp']) }}</td>
                <td class="mid">{{ $percent($row['dealer_discount']) }}</td>
                <td class="mid">{{ $percent($row['sub_dealer_discount']) }}</td>
            </tr>
        @empty
            <tr><td colspan="{{ $withPictures ? 11 : 10 }}" class="empty">{{ __('product_list_print.empty') }}</td></tr>
        @endforelse
    </tbody>
</table>

<div class="note">{{ __('product_list_print.note') }}</div>
</body>
</html>
