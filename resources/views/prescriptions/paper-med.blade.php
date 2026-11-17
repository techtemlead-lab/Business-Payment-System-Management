@php
    $dosing = \App\Domain\Clinic\DoseSchedule::summary($line, $lang);
    $free = collect([$line->dose, $line->frequency, $line->duration])->filter()->implode(' · ');
@endphp
<div class="med">
    <div class="nm"><b>{{ \App\Support\LocalDigits::text($number, $lang) }}. {{ $line->medicine_name }}</b>@if ($line->strength) {{ $line->strength }}@endif @if (! $clinicPrescription || $line->product_id !== null)× {{ \App\Support\LocalDigits::text($line->quantity, $lang) }}@endif</div>
    @if ($dosing !== '')<div class="ds">{{ $dosing }}</div>@endif
    @if ($free !== '')<div class="ex">{{ $free }}</div>@endif
    @if ($line->instructions)<div class="ex">{{ $line->instructions }}</div>@endif
</div>
