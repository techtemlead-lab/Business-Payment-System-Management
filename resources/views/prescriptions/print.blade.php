@php
    /** @var \App\Models\Prescription $prescription */
    $t = fn (string $key, array $replace = []): string => (string) __('prescription.'.$key, $replace, $lang);
    $other = $lang === 'bn' ? 'en' : 'bn';
@endphp
<!DOCTYPE html>
<html lang="{{ $lang }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $t('print_title') }} {{ $prescription->number }} — {{ $company }}</title>
    <style>@page { size: A4 portrait; margin: 10mm; }</style>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; color: #0f172a; background: #f1f5f9; font: 13px/1.5 "Hind Siliguri", "Noto Sans Bengali", Arial, sans-serif; }
        .toolbar { position: sticky; top: 0; z-index: 2; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: space-between; padding: 10px 16px; background: #0f172a; color: #fff; }
        .toolbar a, .toolbar button { border: 0; border-radius: 6px; padding: 7px 14px; font: inherit; font-weight: 600; cursor: pointer; background: #fff; color: #0f172a; text-decoration: none; }
        .toolbar button { background: #006837; color: #fff; }
        .sheet { width: 210mm; max-width: calc(100% - 24px); min-height: 277mm; margin: 16px auto; padding: 10mm; background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; overflow-x: auto; }
        table.page { width: 100%; border-collapse: collapse; }
        table.page > thead > tr > td, table.page > tfoot > tr > td, table.page > tbody > tr > td { padding: 0; }
        table.page > tfoot { display: table-footer-group; }
        table.page > thead { display: table-header-group; }
        @include('prescriptions.paper-css')
        .rxp .left, .rxp .right { word-break: break-word; }
        @media (max-width: 640px) { .sheet { padding: 12px; } }
        @media print { body { background: #fff; } .toolbar { display: none; } .sheet { margin: 0; border: 0; width: auto; max-width: none; padding: 0; min-height: 0; overflow: visible; } .rxp tr { break-inside: auto; } .rxp .med { break-inside: avoid; } }
    </style>
</head>
<body>
<div class="toolbar">
    <b>{{ $t('print_title') }} {{ $prescription->number }}</b>
    <span>
        <a href="?lang={{ $other }}">{{ $other === 'bn' ? 'বাংলা' : 'English' }}</a>
        <a href="/prescriptions/{{ $prescription->id }}/print.pdf?lang={{ $lang }}">{{ __('prescription_letterhead.print_pdf', [], $lang) }}</a>
        <button type="button" data-print>{{ $t('print_print') }}</button>
    </span>
</div>
<main class="sheet">
    <table class="page">
        <thead><tr><td><div class="rxp">@include('prescriptions.paper-header', ['letterhead' => $letterhead, 'logo' => $logo])</div></td></tr></thead>
        <tfoot><tr><td>@include('prescriptions.paper-footer', ['letterhead' => $letterhead])</td></tr></tfoot>
        <tbody><tr><td>@include('prescriptions.paper-body', ['prescription' => $prescription, 'lang' => $lang])</td></tr></tbody>
    </table>
</main>
<script nonce="{{ $csp_nonce ?? '' }}">
    document.querySelectorAll('[data-print]').forEach(function (button) {
        button.addEventListener('click', function () { window.print(); });
    });
</script>
</body>
</html>
