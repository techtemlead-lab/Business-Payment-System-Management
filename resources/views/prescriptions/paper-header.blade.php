@php
    /** @var array<string, string|null> $letterhead */
    $block = function (string $lang) use ($letterhead): string {
        $out = '';
        if (filled($letterhead['doctor_name_'.$lang])) { $out .= '<div class="name">'.e($letterhead['doctor_name_'.$lang]).'</div>'; }
        foreach (['degrees', 'specialities'] as $field) {
            if (filled($letterhead[$field.'_'.$lang])) { $out .= '<div class="line">'.nl2br(e($letterhead[$field.'_'.$lang])).'</div>'; }
        }
        if (filled($letterhead['chamber_'.$lang])) { $out .= '<div class="line">'.nl2br(e($letterhead['chamber_'.$lang])).'</div>'; }
        if (filled($letterhead['registration_'.$lang])) { $out .= '<div class="reg">'.e($letterhead['registration_'.$lang]).'</div>'; }

        return $out;
    };
@endphp
<table class="hd" style="width:100%;border-collapse:collapse"><tr>
    <td style="width:38%;vertical-align:top" lang="bn">{!! $block('bn') !!}</td>
    <td class="logo" style="width:24%;text-align:center;vertical-align:middle">@if ($logo)<img src="{{ $logo }}" alt="" style="max-width:34mm;max-height:24mm">@endif</td>
    <td style="width:38%;vertical-align:top" lang="en">{!! $block('en') !!}</td>
</tr></table>
<div class="rule" style="border-top:0.5mm solid #0f172a"></div>
