@php
    /** @var array<string, string|null> $letterhead */
    $side = function (string $lang) use ($letterhead): string {
        $out = '';
        foreach (['footer_address', 'footer_phone', 'visiting_hours'] as $field) {
            if (filled($letterhead[$field.'_'.$lang])) { $out .= '<div>'.nl2br(e($letterhead[$field.'_'.$lang])).'</div>'; }
        }

        return $out;
    };
    $bn = $side('bn');
    $en = $side('en');
    $noteBn = trim((string) $letterhead['footer_note_bn']);
    $noteEn = trim((string) $letterhead['footer_note_en']);
@endphp
<div class="rxp">
<table class="ft" style="width:100%;border-collapse:collapse;border-top:0.5mm solid #0f172a">
    <tr>
        @if ($bn !== '')<td lang="bn" style="width:{{ $en !== '' ? '50%' : '100%' }};padding-top:1.5mm;font-size:9pt">{!! $bn !!}</td>@endif
        @if ($en !== '')<td lang="en" style="width:{{ $bn !== '' ? '50%' : '100%' }};padding-top:1.5mm;font-size:9pt">{!! $en !!}</td>@endif
    </tr>
    @if ($noteBn !== '' || $noteEn !== '')
        <tr><td colspan="2" style="padding-top:1mm;font-size:9pt;font-weight:bold">
            @if ($noteBn !== '')<div lang="bn">{{ $noteBn }}</div>@endif
            @if ($noteEn !== '')<div lang="en">{{ $noteEn }}</div>@endif
        </td></tr>
    @endif
</table>
</div>
