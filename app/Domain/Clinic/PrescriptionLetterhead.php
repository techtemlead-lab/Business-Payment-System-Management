<?php

namespace App\Domain\Clinic;

use App\Models\PrescriberRegistration;
use App\Models\PrescriptionLetterhead as LetterheadRow;
use App\Models\User;
use App\Support\Audit;
use App\Support\SiteCustomization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The header and footer of a prescriber's printed prescription. Every field exists twice:
 * the Bangla block is printed on the left of the paper and the English block on the right.
 * A blank field falls back to a default (the company's for the owner-doctor), so a prescriber
 * who never opens the settings still prints a correct paper. The logo in the middle is the
 * company logo unless the prescriber uploaded their own.
 */
final class PrescriptionLetterhead
{
    /** Field name (without the language suffix) => longest text allowed. */
    public const Fields = [
        'doctor_name' => 120,
        'degrees' => 255,
        'specialities' => 255,
        'registration' => 160,
        'chamber' => 500,
        'footer_address' => 500,
        'footer_phone' => 255,
        'visiting_hours' => 255,
        'footer_note' => 500,
    ];

    public const Languages = ['bn', 'en'];

    public const LogoMaxKilobytes = 2048;

    public const LogoTypes = 'png,jpg,jpeg,webp';

    private const Disk = 'local';

    /**
     * The letterhead to print: the prescriber's own text, with a default for every blank field.
     *
     * @return array<string, string|null>
     */
    public function forPrescriber(User $prescriber): array
    {
        return [...$this->resolved($prescriber), 'logo_url' => $this->logoUrl($prescriber)];
    }

    /**
     * What the settings page shows: the text the prescriber saved (blank = not set), the defaults
     * that fill the blanks, and the logo.
     *
     * @return array{values: array<string, string>, defaults: array<string, string>, logo_url: string|null, has_own_logo: bool, company_logo_url: string|null}
     */
    public function forSettings(User $prescriber): array
    {
        $row = LetterheadRow::query()->where('user_id', $prescriber->id)->first();
        $values = [];
        foreach ($this->keys() as $key) {
            $values[$key] = $row === null ? '' : (string) $row->{$key};
        }

        return [
            'values' => $values,
            'defaults' => $this->defaults($prescriber),
            'logo_url' => $this->logoUrl($prescriber),
            'has_own_logo' => $this->ownLogoPath($prescriber) !== null,
            'company_logo_url' => SiteCustomization::logoUrl(),
        ];
    }

    /**
     * Save the prescriber's own letterhead. Blank text means "use the default". `$logo` replaces
     * the logo, `$removeLogo` goes back to the company logo.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(User $prescriber, array $data, ?UploadedFile $logo = null, bool $removeLogo = false): void
    {
        abort_unless(PrescriptionAccess::canPrescribe($prescriber), 403);
        $row = LetterheadRow::query()->firstOrNew(['user_id' => $prescriber->id]);
        $before = [];
        foreach ($this->keys() as $key) {
            $before[$key] = $row->{$key};
            $value = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) ($data[$key] ?? '')));
            $row->{$key} = $value === '' ? null : $value;
        }

        $oldLogo = $row->logo_path;
        $newLogo = null;
        if ($logo !== null) {
            $newLogo = $logo->storeAs('prescription-letterheads/'.$prescriber->id, Str::uuid().'.'.strtolower((string) $logo->guessExtension()), self::Disk);
            if ($newLogo === false) {
                throw ValidationException::withMessages(['logo' => __('prescription_letterhead.err_logo_failed')]);
            }
            $row->logo_path = $newLogo;
        } elseif ($removeLogo) {
            $row->logo_path = null;
        }

        try {
            $row->save();
        } catch (\Throwable $exception) {
            if ($newLogo !== null) {
                Storage::disk(self::Disk)->delete($newLogo);
            }
            throw $exception;
        }
        if ($oldLogo !== null && $oldLogo !== $row->logo_path) {
            Storage::disk(self::Disk)->delete($oldLogo);
        }

        Audit::record($prescriber->id, 'prescription.letterhead_saved', (string) $prescriber->id, [
            'changed_fields' => array_values(array_filter($this->keys(), fn (string $key): bool => ($before[$key] ?? null) !== $row->{$key})),
            'logo' => $logo !== null ? 'replaced' : ($removeLogo ? 'removed' : 'kept'),
        ]);
    }

    /** The prescriber's own uploaded logo as a file on this server, or null when there is none. */
    public function ownLogoPath(User $prescriber): ?string
    {
        $path = LetterheadRow::query()->where('user_id', $prescriber->id)->value('logo_path');
        if (! is_string($path) || $path === '' || ! Storage::disk(self::Disk)->exists($path)) {
            return null;
        }

        return Storage::disk(self::Disk)->path($path);
    }

    /** The logo to print as a file: the prescriber's own, else the company's. */
    public function logoFile(User $prescriber): ?string
    {
        $own = $this->ownLogoPath($prescriber);
        if ($own !== null) {
            return $own;
        }
        foreach ([SiteCustomization::logoFilePath(), public_path('madina-header-logo.png'), public_path('branding/madina-logo-latest.png')] as $candidate) {
            if ($candidate !== null && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** The same logo as a data address, so the print page needs no second request. */
    public function logoDataUri(User $prescriber): ?string
    {
        $file = $this->logoFile($prescriber);
        if ($file === null) {
            return null;
        }
        $mime = match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'image/png',
        };

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($file));
    }

    /** @return list<string> */
    public function keys(): array
    {
        $keys = [];
        foreach (self::Fields as $field => $max) {
            foreach (self::Languages as $lang) {
                $keys[] = $field.'_'.$lang;
            }
        }

        return $keys;
    }

    /**
     * Validation rules for the settings form.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        $rules = [];
        foreach (self::Fields as $field => $max) {
            foreach (self::Languages as $lang) {
                $rules[$field.'_'.$lang] = 'nullable|string|max:'.$max;
            }
        }
        $rules['logo'] = 'nullable|file|image|mimes:'.self::LogoTypes.'|max:'.self::LogoMaxKilobytes.'|dimensions:max_width=4000,max_height=4000';
        $rules['remove_logo'] = 'nullable|boolean';

        return $rules;
    }

    /** @return array<string, string> */
    private function resolved(User $prescriber): array
    {
        $row = LetterheadRow::query()->where('user_id', $prescriber->id)->first();
        $defaults = $this->defaults($prescriber);
        $out = [];
        foreach ($this->keys() as $key) {
            $own = $row === null ? '' : trim((string) $row->{$key});
            $out[$key] = $own !== '' ? $own : ($defaults[$key] ?? '');
        }

        return $out;
    }

    private function logoUrl(User $prescriber): string
    {
        if ($this->ownLogoPath($prescriber) !== null) {
            $row = LetterheadRow::query()->where('user_id', $prescriber->id)->first(['updated_at']);

            return '/prescriptions/letterhead/logo?v='.($row?->updated_at?->getTimestamp() ?? 0);
        }

        return SiteCustomization::logoUrl();
    }

    /**
     * What fills a blank field. The owner-doctor's paper carries the company's name and address;
     * every prescriber's paper carries their own name, registration and the standard visit note.
     *
     * @return array<string, string>
     */
    private function defaults(User $prescriber): array
    {
        $registration = PrescriberRegistration::query()->where('user_id', $prescriber->id)->first(['registration_type', 'registration_number']);
        $owner = PrescriptionAccess::isOwnerDoctor($prescriber);
        $custom = SiteCustomization::inVisitorLanguage(SiteCustomization::all());
        $address = trim((string) ($custom['office_address'] ?? ''));
        $phone = trim((string) ($custom['contact_phone'] ?? ''));
        $out = [];
        foreach (self::Languages as $lang) {
            $out['doctor_name_'.$lang] = (string) $prescriber->name;
            $out['degrees_'.$lang] = '';
            $out['specialities_'.$lang] = '';
            $out['registration_'.$lang] = $registration !== null
                ? __('prescription.type_'.$registration->registration_type, [], $lang).' · '.__('prescription.print_reg', [], $lang).': '.$registration->registration_number
                : '';
            $out['chamber_'.$lang] = $owner ? (string) __('portal.brand_company_bar', [], $lang) : '';
            $out['footer_address_'.$lang] = $owner ? $address : '';
            $out['footer_phone_'.$lang] = $owner ? $phone : '';
            $out['visiting_hours_'.$lang] = '';
            $out['footer_note_'.$lang] = (string) __('prescription_letterhead.default_footer_note', [], $lang);
        }

        return $out;
    }
}
