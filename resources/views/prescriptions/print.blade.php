@php
    /** @var \App\Models\Prescription $prescription */
    $t = fn (string $key, array $replace = []): string => (string) __('prescription.'.$key, $replace, $lang);
    $patient = $prescription->patient;
    $place = $patient?->placeCode ? trim(\App\Models\PlaceCode::displayLabel($patient->placeCode->code).' '.\App\Models\PlaceCode::displayLabel($patient->placeCode->name)) : null;
    $other = $lang === 'bn' ? 'en' : 'bn';
    $digits = fn (string|int|float|null $value): string => \App\Support\LocalDigits::text($value, $lang);
@endphp
<!DOCTYPE html>
<html lang="{{ $lang }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $t('print_title') }} {{ $prescription->number }} — {{ $company }}</title>
    <style>@page { size: A4 portrait; margin: 12mm; }</style>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; color: #0f172a; background: #f1f5f9; font: 13px/1.5 "Hind Siliguri", "Noto Sans Bengali", Arial, sans-serif; }
        .toolbar { position: sticky; top: 0; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: space-between; padding: 10px 16px; background: #0f172a; color: #fff; }
        .toolbar a, .toolbar button { border: 0; border-radius: 6px; padding: 7px 14px; font: inherit; font-weight: 600; cursor: pointer; background: #fff; color: #0f172a; text-decoration: none; }
        .toolbar button { background: #006837; color: #fff; }
        .sheet { width: 210mm; max-width: calc(100% - 24px); margin: 16px auto; padding: 14mm 16mm; background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; }
        .head { display: flex; justify-content: space-between; gap: 16px; border-bottom: 2px solid #006837; padding-bottom: 8px; }
        .doctor b { display: block; font-size: 18px; color: #006837; }
        .muted { color: #475569; }
        .head-meta { text-align: right; }
        .patient { display: flex; flex-wrap: wrap; gap: 6px 18px; padding: 8px 0; border-bottom: 1px solid #cbd5e1; }
        .patient span b { color: #334155; }
        .body { display: grid; grid-template-columns: 34% 1fr; gap: 16px; margin-top: 12px; min-height: 120mm; }
        .side h3, .main h3 { margin: 10px 0 4px; font-size: 13px; color: #006837; }
        .side p { margin: 0 0 6px; white-space: pre-wrap; }
        .rx { font-size: 26px; font-weight: 700; color: #006837; margin: 0; }
        ol { padding-left: 18px; margin: 6px 0; }
        ol li { margin-bottom: 8px; }
        ol li small { display: block; color: #475569; }
        ol li .dosing { margin-top: 2px; font-size: 14px; font-weight: 600; color: #0f172a; }
        table.totals { margin-left: auto; margin-top: 10px; border-collapse: collapse; }
        table.totals td { padding: 3px 8px; }
        table.totals td.amt { text-align: right; font-weight: 600; }
        .sign { margin-top: 40px; text-align: right; }
        .sign div { display: inline-block; min-width: 180px; border-top: 1px solid #0f172a; padding-top: 4px; text-align: center; }
        @media (max-width: 640px) { .body { grid-template-columns: 1fr; } .sheet { padding: 16px; } .head { flex-direction: column; gap: 6px; } .head-meta { text-align: left; } }
        @media print { body { background: #fff; } .toolbar { display: none; } .sheet { margin: 0; border: 0; width: auto; max-width: none; padding: 0; } }
    </style>
</head>
<body>
<div class="toolbar">
    <b>{{ $t('print_title') }} {{ $prescription->number }}</b>
    <span>
        <a href="?lang={{ $other }}">{{ $other === 'bn' ? 'বাংলা' : 'English' }}</a>
        <button type="button" data-print>{{ $t('print_print') }}</button>
    </span>
</div>
<main class="sheet">
    <header class="head">
        <div class="doctor">
            <b>{{ $prescription->prescriber?->name }}</b>
            @if ($registration)
                <div>{{ $t('type_'.$registration->registration_type) }}</div>
                <div>{{ $t('print_reg') }}: {{ $registration->registration_number }}</div>
            @endif
        </div>
        <div class="muted head-meta">
            <div>{{ $company }}</div>
            <div>{{ $t('rx_number') }} {{ $prescription->number }}</div>
            <div>{{ $t('print_date') }}: {{ $digits($prescription->prescribed_on->format('d-m-Y')) }}</div>
        </div>
    </header>
    <section class="patient">
        <span><b>{{ $t('print_patient') }}:</b> {{ $patient?->name }}</span>
        @if ($patient?->age !== null)<span><b>{{ $t('print_age') }}:</b> {{ $t('print_years', ['age' => $digits($patient->age)]) }}</span>@endif
        @if ($patient?->sex)<span><b>{{ $t('print_sex') }}:</b> {{ $t('sex_'.$patient->sex) }}</span>@endif
        @if ($phone)<span><b>{{ $t('print_phone') }}:</b> {{ $phone }}</span>@endif
        @if ($place)<span><b>{{ $t('print_place') }}:</b> {{ $place }}</span>@endif
    </section>
    <section class="body">
        <aside class="side">
            @php $vitals = \App\Domain\Clinic\Vitals::lines($prescription->vitals, $lang); @endphp
            @if ($vitals !== [])
                <h3>{{ __('clinic_records.vitals_title', [], $lang) }}</h3>
                @foreach ($vitals as $reading)<p>{{ $reading['label'] }}: {{ $reading['value'] }}</p>@endforeach
            @endif
            @if ($prescription->complaints)<h3>{{ $t('rx_complaints') }}</h3><p>{{ $prescription->complaints }}</p>@endif
            @if ($prescription->diagnosis)<h3>{{ $t('rx_diagnosis') }}</h3><p>{{ $prescription->diagnosis }}</p>@endif
            @if ($prescription->advice)<h3>{{ $t('rx_advice') }}</h3><p>{{ $prescription->advice }}</p>@endif
            @if ($prescription->follow_up_on)<h3>{{ $t('rx_follow_up') }}</h3><p>{{ $digits($prescription->follow_up_on->format('d-m-Y')) }}</p>@endif
        </aside>
        <div class="main">
            <p class="rx">℞</p>
            <ol>
                @foreach ($prescription->lines as $line)
                    <li>
                        <b>{{ $line->medicine_name }}</b> × {{ $digits($line->quantity) }}
                        @php $dosing = \App\Domain\Clinic\DoseSchedule::summary($line, $lang); @endphp
                        @if ($dosing !== '')<div class="dosing">{{ $dosing }}</div>@endif
                        <small>{{ collect([$line->dose, $line->frequency, $line->duration])->filter()->implode(' · ') }}</small>
                        @if ($line->instructions)<small>{{ $line->instructions }}</small>@endif
                    </li>
                @endforeach
            </ol>
            <table class="totals">
                <tr><td>{{ $t('rx_subtotal') }}</td><td class="amt">৳ {{ $digits($prescription->subtotal) }}</td></tr>
                @if ((float) $prescription->discount_total > 0)<tr><td>{{ $t('rx_discount_total') }}</td><td class="amt">− ৳ {{ $digits($prescription->discount_total) }}</td></tr>@endif
                <tr><td><b>{{ $t('rx_total') }}</b></td><td class="amt">৳ {{ $digits($prescription->total) }}</td></tr>
                @if ($prescription->status === \App\Models\Prescription::StatusDispensed)
                    <tr><td>{{ $t('payment_method') }}</td><td class="amt">{{ $t('payment_'.$prescription->payment_method) }}@if ($prescription->payment_reference) · {{ $t('payment_ref_short') }} {{ $prescription->payment_reference }}@endif</td></tr>
                @endif
            </table>
        </div>
    </section>
    <div class="sign"><div>{{ $t('print_signature') }}</div></div>
</main>
<script nonce="{{ $csp_nonce ?? '' }}">
    document.querySelectorAll('[data-print]').forEach(function (button) {
        button.addEventListener('click', function () { window.print(); });
    });
</script>
</body>
</html>
