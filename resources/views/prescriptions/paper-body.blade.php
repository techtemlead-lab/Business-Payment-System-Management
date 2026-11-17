@php
    /** @var \App\Models\Prescription $prescription */
    $forPdf = $forPdf ?? false;
    $t = fn (string $key, array $replace = []): string => (string) __('prescription.'.$key, $replace, $lang);
    $l = fn (string $key, array $replace = []): string => (string) __('prescription_letterhead.'.$key, $replace, $lang);
    $digits = fn (string|int|float|null $value): string => \App\Support\LocalDigits::text($value, $lang);
    // The PDF font has no taka sign, so the PDF writes the word.
    $money = fn (string|int|float|null $value): string => $forPdf ? $digits($value).' '.($lang === 'bn' ? 'টাকা' : 'Tk') : '৳ '.$digits($value);
    $patient = $prescription->patient;
    $investigations = $prescription->investigations ?? [];
    $foodsAllowed = $prescription->foods_allowed ?? [];
    $foodsAvoided = $prescription->foods_avoided ?? [];
    $clinicPrescription = $prescription->kind === \App\Models\Prescription::KindClinic;
    $billed = $prescription->lines->contains(fn ($line): bool => ! $clinicPrescription || $line->product_id !== null);
    // Fields the writing screen adds are read carefully so an older prescription still prints.
    $address = $prescription->patient_address ?? null;
    $patientCode = $prescription->patient_code ?? $prescription->number;
    $visitType = $prescription->visit_type ?? null;
    $examination = $prescription->examination ?? null;
    $adviceLines = collect(preg_split('/\R/u', (string) $prescription->advice) ?: [])->map(fn ($line) => trim($line))->filter()->values();
    $vitals = \App\Domain\Clinic\Vitals::lines($prescription->vitals, $lang);
    // The phone is always masked on paper (first three and last four digits).
    $maskedPhone = \App\Support\PhoneIdentity::maskedForPrint($patient?->phone);
    $ageSex = collect([
        $patient?->age !== null ? $t('print_years', ['age' => $digits($patient->age)]) : null,
        $patient?->sex ? $t('sex_'.$patient->sex) : null,
        $maskedPhone !== '' ? $t('print_phone').': '.$maskedPhone : null,
    ])->filter()->implode(' · ');
    $cell = 'padding:1mm 1mm;border-bottom:0.3mm solid #0f172a';
    $lines = $prescription->lines->values();
    $hasFoods = $foodsAllowed !== [] || $foodsAvoided !== [];
    $edge = 'width:33%;border-right:0.3mm solid #0f172a;padding:0 3mm 0 0;vertical-align:top';
    $main = 'padding:0 0 0 4mm;vertical-align:top';
@endphp
<div class="rxp">
<table class="bar" style="width:100%;border-collapse:collapse"><tr>
    <td style="{{ $cell }}"><b>{{ $t('print_patient') }}:</b> {{ $patient?->name }}</td>
    <td style="{{ $cell }}"><b>{{ $l('print_id') }}:</b> {{ $digits($patientCode) }}</td>
    <td style="{{ $cell }}"><b>{{ $t('print_date') }}:</b> {{ $digits($prescription->prescribed_on->format('d/m/Y')) }}</td>
</tr><tr>
    <td style="{{ $cell }}">@if ($ageSex !== '')<b>{{ $t('print_age') }}:</b> {{ $ageSex }}@endif</td>
    <td style="{{ $cell }}">@if (filled($address))<b>{{ $l('print_address') }}:</b> {{ $address }}@endif</td>
    <td style="{{ $cell }}">@if (in_array($visitType, ['new', 'old'], true))<b>{{ $l('print_visit') }}:</b> {{ $l('visit_'.$visitType) }}@endif</td>
</tr></table>
{{-- One row for every medicine and advice line: a long list carries on to the next page row by row, and the left cell's line carries on with it. --}}
<table class="cols" style="width:100%;border-collapse:collapse">
    <tr>
        <td class="left" style="{{ $edge }}">
            @if ($vitals !== [])
                <h4>{{ __('clinic_records.vitals_title', [], $lang) }}</h4>
                @foreach ($vitals as $reading)<p class="txt">{{ $reading['label'] }}: {{ $reading['value'] }}</p>@endforeach
            @endif
            @if ($prescription->complaints)<h4>{{ $l('complaint') }}</h4><p class="txt">{!! nl2br(e($prescription->complaints)) !!}</p>@endif
            @if (filled($examination))<h4>{{ $l('on_examination') }}</h4><p class="txt">{!! nl2br(e($examination)) !!}</p>@endif
            @if ($prescription->diagnosis)<h4>{{ $t('rx_diagnosis') }}</h4><p class="txt">{!! nl2br(e($prescription->diagnosis)) !!}</p>@endif
            @if ($investigations !== [])
                <h4>{{ $l('investigation') }}</h4>
                <ul>@foreach ($investigations as $investigation)<li>{{ $investigation }}</li>@endforeach</ul>
            @endif
        </td>
        <td class="right" style="{{ $main }}">@if ($lines->isNotEmpty())<p class="rxsym">Rx</p>@endif
            @foreach ($lines->take(3) as $line)@include('prescriptions.paper-med', ['line' => $line, 'number' => $loop->iteration, 'clinicPrescription' => $clinicPrescription, 'lang' => $lang])@endforeach</td>
    </tr>
    @foreach ($lines->slice(3)->values() as $line)
        <tr>
            <td style="{{ $edge }}">&nbsp;</td>
            <td class="right" style="{{ $main }}">@include('prescriptions.paper-med', ['line' => $line, 'number' => $loop->iteration + 3, 'clinicPrescription' => $clinicPrescription, 'lang' => $lang])</td>
        </tr>
    @endforeach
    @if ($lines->isNotEmpty() && $billed)
        <tr>
            <td style="{{ $edge }}">&nbsp;</td>
            <td class="right" style="{{ $main }}">
                <table class="totals" style="margin-left:auto;width:auto">
                    <tr><td>{{ $t('rx_subtotal') }}</td><td style="text-align:right">{{ $money($prescription->subtotal) }}</td></tr>
                    @if ((float) $prescription->discount_total > 0)<tr><td>{{ $t('rx_discount_total') }}</td><td style="text-align:right">− {{ $money($prescription->discount_total) }}</td></tr>@endif
                    <tr><td><b>{{ $t('rx_total') }}</b></td><td style="text-align:right"><b>{{ $money($prescription->total) }}</b></td></tr>
                    @if ($prescription->status === \App\Models\Prescription::StatusDispensed)
                        <tr><td>{{ $t('payment_method') }}</td><td style="text-align:right">{{ $t('payment_'.$prescription->payment_method) }}@if ($prescription->payment_reference) · {{ $t('payment_ref_short') }} {{ $prescription->payment_reference }}@endif</td></tr>
                    @endif
                </table>
            </td>
        </tr>
    @endif
    @if ($hasFoods)
        <tr>
            <td style="{{ $edge }}">&nbsp;</td>
            <td class="right" style="{{ $main }}">
                <table style="width:100%;border-collapse:collapse"><tr>
                    @if ($foodsAllowed !== [])<td style="width:50%"><h4>{{ $t('rx_foods_allowed') }}</h4><ul>@foreach ($foodsAllowed as $food)<li>{{ $food }}</li>@endforeach</ul></td>@endif
                    @if ($foodsAvoided !== [])<td style="width:50%"><h4>{{ $t('rx_foods_avoided') }}</h4><ul>@foreach ($foodsAvoided as $food)<li>{{ $food }}</li>@endforeach</ul></td>@endif
                </tr></table>
            </td>
        </tr>
    @endif
    @if ($adviceLines->isNotEmpty())
        <tr><td style="{{ $edge }}">&nbsp;</td><td class="right" style="{{ $main }}"><h4>{{ $l('advice_heading') }}:</h4></td></tr>
        @foreach ($adviceLines as $advice)
            <tr>
                <td style="{{ $edge }}">&nbsp;</td>
                <td class="right" style="{{ $main }}"><table style="width:100%;border-collapse:collapse"><tr><td style="width:6mm;padding:0 0 1mm 0;font-size:10pt">{{ $digits($loop->iteration) }}.</td><td style="padding:0 0 1mm 0;font-size:10pt">{{ $advice }}</td></tr></table></td>
            </tr>
        @endforeach
    @endif
    <tr>
        <td style="{{ $edge }}">&nbsp;</td>
        <td class="right" style="{{ $main }}">
            @if ($prescription->follow_up_on)
                <h4>{{ $l('next_visit') }}:</h4>
                <p class="txt">{{ $l('next_visit_on', ['date' => $digits($prescription->follow_up_on->format('d/m/Y'))]) }}</p>
            @endif
            <table style="width:100%;border-collapse:collapse;margin-top:14mm"><tr>
                <td style="width:50%"></td>
                <td style="width:50%;border-top:0.3mm dotted #0f172a;text-align:center;padding-top:1mm;font-size:10pt">{{ $t('print_signature') }}</td>
            </tr></table>
        </td>
    </tr>
</table>
</div>
