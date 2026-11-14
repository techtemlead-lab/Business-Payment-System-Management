<?php

namespace App\Domain\Accounts;

use App\Domain\Content\PublicTrustContent;
use App\Models\User;
use App\Support\Audit;
use App\Support\LocalDigits;
use App\Support\PdfFonts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The printable registration form of a dealer or sub-dealer, made from the saved account:
 * the details staff entered, then four signature places. The first place is always the
 * partner's own; the titles of the other three are written by website staff in the
 * public trust settings, so the company can change them without a code change.
 * The sign-in password is never printed.
 */
final class PartnerRegistrationForm
{
    /** How many company signature places follow the partner's own. */
    public const CompanySigners = 3;

    /**
     * The form rows in the reader's language, grouped by section.
     *
     * @return list<array{title: string, rows: list<array{label: string, value: string}>}>
     */
    public function sections(User $partner, string $locale, bool $showIdentityNumber): array
    {
        $profile = is_array($partner->profile) ? $partner->profile : [];
        $t = static fn (string $key): string => __('partner_form.'.$key, [], $locale);
        $text = static fn (mixed $value): string => trim((string) ($value ?? ''));
        $digits = static fn (string $value): string => LocalDigits::text($value, $locale);
        $row = static fn (string $label, string $value): array => ['label' => $label, 'value' => $value];

        $identityType = (string) ($profile['identity_type'] ?? ($partner->nid ? 'nid' : 'birth_certificate'));
        $identityNumber = $identityType === 'nid' ? $text($partner->nid) : $text($profile['birth_certificate_number'] ?? '');
        if (! $showIdentityNumber && $identityNumber !== '') {
            $identityNumber = str_repeat('*', max(0, mb_strlen($identityNumber) - 3)).mb_substr($identityNumber, -3);
        }

        $birthDate = $text($profile['date_of_birth'] ?? '');
        if ($birthDate !== '') {
            try {
                $birthDate = CarbonImmutable::parse($birthDate)->locale($locale)->translatedFormat('d M Y');
            } catch (\Throwable) {
                // A date that cannot be read is printed as it was saved.
            }
        }

        $sponsor = $partner->sponsor_id ? User::query()->whereKey($partner->sponsor_id)->first(['name', 'dealer_code']) : null;
        $sponsorText = $sponsor ? trim($sponsor->name.' ('.($sponsor->dealer_code ?? '—').')') : '';

        return [
            ['title' => $t('section_account'), 'rows' => [
                $row($t('f_role'), $t('role_'.$partner->role)),
                $row($t('f_code'), $text($partner->dealer_code)),
                $row($t('f_registered_on'), $digits($partner->created_at ? CarbonImmutable::parse($partner->created_at)->timezone('Asia/Dhaka')->locale($locale)->translatedFormat('d M Y') : '')),
                $row($t('f_sponsor'), $sponsorText),
            ]],
            ['title' => $t('section_personal'), 'rows' => [
                $row($t('f_name_en'), $text($partner->name)),
                $row($t('f_name_bn'), $text($profile['name_bn'] ?? '')),
                $row($t('f_father'), $text($profile['father_name'] ?? '')),
                $row($t('f_mother'), $text($profile['mother_name'] ?? '')),
                $row($t('f_dob'), $digits($birthDate)),
                $row($t($identityType === 'nid' ? 'f_identity_nid' : 'f_identity_birth'), $digits($identityNumber)),
                $row($t('f_occupation'), $text($profile['occupation'] ?? '')),
                $row($t('f_phone'), $digits($text($partner->phone))),
                $row($t('f_extra_contact'), $digits($text($profile['extra_contact'] ?? ''))),
                $row($t('f_email'), $text($partner->email)),
            ]],
            ['title' => $t('section_address'), 'rows' => [
                $row($t('f_division'), $text($partner->division)),
                $row($t('f_district'), $text($partner->district)),
                $row($t('f_upazila'), $text($profile['upazila'] ?? '')),
                $row($t('f_union'), $text($profile['union'] ?? '')),
                $row($t('f_village'), $text($profile['village'] ?? '')),
                $row($t('f_post_office'), $text($profile['post_office'] ?? '')),
                $row($t('f_place_code'), $text($profile['place_code'] ?? '')),
            ]],
            ['title' => $t('section_business'), 'rows' => [
                $row($t('f_shop'), $text($profile['shop_name'] ?? $profile['pharmacy'] ?? '')),
            ]],
        ];
    }

    /**
     * The four signature places in print order: the partner first, then the company's three.
     * A company title the owner left blank stays blank, so it can be written by hand.
     *
     * @return list<array{title: string, name: string}>
     */
    public function signatures(User $partner, string $locale): array
    {
        $signatures = [[
            'title' => __('partner_form.sign_partner_'.$partner->role, [], $locale),
            'name' => trim((string) $partner->name),
        ]];
        // The saved titles follow the app language, so it is switched for the reading and put back after.
        $appLocale = app()->getLocale();
        app()->setLocale($locale);
        try {
            for ($slot = 1; $slot <= self::CompanySigners; $slot++) {
                $signatures[] = ['title' => PublicTrustContent::text('form_signer_'.$slot), 'name' => ''];
            }
        } finally {
            app()->setLocale($appLocale);
        }

        return $signatures;
    }

    public function download(User $actor, User $partner, ?string $requestedLocale = null): BinaryFileResponse
    {
        abort_unless(in_array($partner->role, ['dealer', 'sub_dealer'], true), 404);

        $locale = in_array($requestedLocale, ['en', 'bn'], true) ? $requestedLocale : $partner->preferredLocale();
        $html = $this->html($partner, $locale, $actor->allows('identity_write'));

        $dir = Storage::disk('local')->path('report-exports');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $safeCode = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($partner->dealer_code ?: 'partner')) ?: 'partner';
        $path = $dir.'/registration-form-'.$safeCode.'-'.now('Asia/Dhaka')->format('Ymd-His').'-'.Str::lower(Str::random(6)).'.pdf';

        try {
            $pdf = new Mpdf([
                'tempDir' => $dir,
                'format' => 'A4',
                'margin_left' => 14,
                'margin_right' => 14,
                'margin_top' => 12,
                'margin_bottom' => 12,
                ...PdfFonts::options(),
            ]);
            $pdf->SetTitle(__('partner_form.doc_title', ['code' => (string) $partner->dealer_code], $locale));
            $pdf->WriteHTML($html);
            $pdf->Output($path, Destination::FILE);
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        }

        // The form carries identity papers' numbers, so every download is written to the audit log.
        Audit::record($actor->id, 'account.registration_form_downloaded', (string) $partner->id, ['locale' => $locale]);

        return response()->download($path, 'Madina-registration-form-'.$safeCode.'.pdf', ['Cache-Control' => 'private, no-store'])->deleteFileAfterSend();
    }

    private function html(User $partner, string $locale, bool $showIdentityNumber): string
    {
        $t = static fn (string $key, array $replace = []): string => __('partner_form.'.$key, $replace, $locale);
        $brand = __('portal.brand_madina_natural_health_care', [], $locale);
        $title = $t('title_'.$partner->role);
        $now = now('Asia/Dhaka');
        $serial = Str::upper(substr(hash('sha256', $partner->id.'|'.$now->format('YmdHis')), 0, 10));

        $sectionHtml = '';
        foreach ($this->sections($partner, $locale, $showIdentityNumber) as $section) {
            $sectionHtml .= '<div class="sec">'.e($section['title']).'</div><table class="grid">';
            foreach (array_chunk($section['rows'], 2) as $pair) {
                $sectionHtml .= '<tr>';
                foreach ($pair as $item) {
                    $sectionHtml .= '<td class="lab">'.e($item['label']).'</td><td class="val">'.($item['value'] !== '' ? e($item['value']) : '&nbsp;').'</td>';
                }
                if (count($pair) === 1) {
                    $sectionHtml .= '<td class="lab"></td><td class="val"></td>';
                }
                $sectionHtml .= '</tr>';
            }
            $sectionHtml .= '</table>';
        }

        $signatures = $this->signatures($partner, $locale);
        $signatureRows = ['', '', '', ''];
        foreach ($signatures as $index => $signature) {
            $signatureRows[0] .= '<td class="space"></td>';
            $signatureRows[1] .= $signature['title'] !== ''
                ? '<td class="stitle">'.e($signature['title']).'</td>'
                : '<td class="stitle blank">'.e($t('sign_designation')).':</td>';
            $signatureRows[2] .= '<td class="sline">'.e($t('sign_name')).': '.($index === 0 ? e($signature['name']) : '').'</td>';
            $signatureRows[3] .= '<td class="sline">'.e($t('sign_date')).':</td>';
        }
        $signatureTable = '<table class="sigs"><tr>'.implode('</tr><tr>', $signatureRows).'</tr></table>';

        return '<html><head><style>
            body{font-family:freeserif,sans-serif;color:#0b1f33;font-size:10.5pt}
            .sheet{border:2px solid #006a4e;padding:14px 18px}
            .brand{font-size:15pt;color:#006a4e;font-weight:700}
            h1{font-size:16pt;margin:2px 0 2px;color:#0b1f33}
            .sub{font-size:9pt;color:#5b6b7a}
            .sec{background:#e3f1eb;color:#00482f;font-weight:700;padding:3px 8px;margin-top:12px;font-size:10.5pt}
            table.grid{width:100%;border-collapse:collapse}
            table.grid td{border-bottom:1px solid #dfe5e0;padding:7px 6px;vertical-align:top}
            td.lab{width:17%;color:#5b6b7a;font-size:9pt}
            td.val{width:33%;font-weight:700}
            .decl{margin-top:16px;font-size:10pt;line-height:1.6}
            table.sigs{width:100%;border-collapse:separate;border-spacing:10px 0;margin-top:14px}
            td.space{width:25%;height:26mm;border-bottom:1px solid #0b1f33}
            td.stitle{padding-top:4px;height:11mm;font-weight:700;font-size:9.5pt;text-align:center;vertical-align:top}
            td.blank{font-weight:400;color:#5b6b7a;border-bottom:1px dotted #0b1f33;vertical-align:bottom}
            td.sline{height:9mm;font-size:9pt;border-bottom:1px dotted #0b1f33;vertical-align:bottom}
            table.photo{width:34mm;border-collapse:collapse;border:1px solid #5b6b7a}
            table.photo td{height:42mm;text-align:center;vertical-align:middle;color:#5b6b7a;font-size:8.5pt}
            .foot{margin-top:16px;border-top:1px solid #dfe5e0;padding-top:6px;font-size:8pt;color:#5b6b7a}
        </style></head><body><div class="sheet">
            <table style="width:100%"><tr>
                <td style="vertical-align:top"><div class="brand">'.e($brand).'</div><h1>'.e($title).'</h1><div class="sub">'.e($t('subtitle')).'</div></td>
                <td style="width:36mm;vertical-align:top"><table class="photo"><tr><td>'.e($t('photo_box')).'</td></tr></table></td>
            </tr></table>
            '.$sectionHtml.'
            <div class="decl">'.e($t('declaration')).'</div>
            '.$signatureTable.'
            <div class="foot">'.e($t('foot', ['date' => LocalDigits::text($now->locale($locale)->translatedFormat('d M Y'), $locale), 'serial' => $serial])).'</div>
        </div></body></html>';
    }
}
