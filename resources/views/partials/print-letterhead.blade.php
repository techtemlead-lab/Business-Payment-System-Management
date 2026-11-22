{{-- The letterhead at the top of the printed lists. Styles: .header, .logo-cell, .legal-cell, .brand-name, .brand-line (see the list views). --}}
<table class="header">
    <tr>
        <td class="logo-cell">
            @if (! empty($company['logo_path']))
                <img src="{{ $company['logo_path'] }}" alt="" width="140" height="140" style="margin-top:4px">
            @endif
        </td>
        <td style="text-align:center;vertical-align:top">
            <div class="brand-name">{{ $company['name'] }}</div>
            <div class="brand-line">{{ $company['address'] }}</div>
            <div style="margin-top:3px">
                @include('partials.letterhead-contacts', ['company' => $company, 'size' => '13pt', 'localDigits' => true])
            </div>
        </td>
        <td class="legal-cell">
            @include('partials.letterhead-legal', ['company' => $company])
        </td>
    </tr>
</table>
