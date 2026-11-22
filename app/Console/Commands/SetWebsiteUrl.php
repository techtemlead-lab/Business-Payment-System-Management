<?php

namespace App\Console\Commands;

use App\Support\Audit;
use App\Support\CompanyWebsite;
use App\Support\SiteCustomization;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The owner wants the company's website printed as https://www.madinanhc.com everywhere (2026-11-22). Printed documents
 * already show that form whatever the server address is. This command also fixes a website setting the owner saved
 * earlier in the website editor: a stored value that is only the company's own domain ("madinanhc.com",
 * "www.madinanhc.com", "https://madinanhc.com/") becomes the new form. A value with more text around the domain
 * (an email address, a sentence, a page address) is never touched. Running it twice is safe. It lists the changes and
 * writes nothing unless --apply is given.
 */
#[Signature('madina:set-website-url {--apply : Write the changes (without it the command only lists them)}')]
#[Description('Change stored website settings that hold only the company domain to https://www.madinanhc.com (dry run unless --apply).')]
class SetWebsiteUrl extends Command
{
    public function handle(): int
    {
        $record = DB::table('content_pages')->where('slug', SiteCustomization::SLUG)->first();
        $stored = $record !== null && ! empty($record->body) ? json_decode((string) $record->body, true) : null;
        if (! is_array($stored)) {
            $this->info('No saved website settings. Nothing to change.');

            return self::SUCCESS;
        }

        $changes = [];
        $updated = $this->walk($stored, '', $changes);
        if ($changes === []) {
            $this->info('No saved website setting holds the bare company domain. Nothing to change.');

            return self::SUCCESS;
        }

        $this->table(['Setting', 'Saved value', 'New value'], $changes);
        if (! $this->option('apply')) {
            $this->warn(count($changes).' setting(s) would change. Run again with --apply to write them.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($record, $updated, $changes): void {
            DB::table('content_pages')->where('id', $record->id)->update([
                'body' => json_encode($updated, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
            Audit::record(null, 'site.website_url_normalised', SiteCustomization::SLUG, ['changed' => count($changes), 'settings' => array_column($changes, 0)]);
        });
        SiteCustomization::forgetCache();
        $this->info(count($changes).' setting(s) changed.');

        return self::SUCCESS;
    }

    /**
     * Returns the settings with every value that is only the company domain replaced, and lists each replacement.
     *
     * @param  array<array-key, mixed>  $values
     * @param  list<array{0: string, 1: string, 2: string}>  $changes
     * @return array<array-key, mixed>
     */
    private function walk(array $values, string $path, array &$changes): array
    {
        foreach ($values as $key => $value) {
            $here = $path === '' ? (string) $key : $path.'.'.$key;
            if (is_array($value)) {
                $values[$key] = $this->walk($value, $here, $changes);
            } elseif (is_string($value) && $value !== '' && CompanyWebsite::isOwnDomain($value) && $value !== CompanyWebsite::url()) {
                $changes[] = [$here, $value, CompanyWebsite::url()];
                $values[$key] = CompanyWebsite::url();
            }
        }

        return $values;
    }
}
