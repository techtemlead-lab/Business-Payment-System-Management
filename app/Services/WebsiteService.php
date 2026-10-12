<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\BusinessConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class WebsiteService
{
    /** @return array<string, mixed> */
    public function defaults(): array
    {
        $document = config('website');
        $company = BusinessConfig::find(1);
        $document['brand']['name'] = $company?->company_name ?? 'Nexora';
        $document['contact']['email'] = $company?->contact_email ?? '';
        $document['contact']['phone'] = $company?->contact_phone ?? '';

        return $document;
    }

    /** @return array<string, mixed> */
    public function decode(string $json): array
    {
        $document = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        foreach (config('website') as $group => $defaults) {
            if (! array_key_exists($group, $document)) {
                $document[$group] = $defaults;
            } elseif (is_array($defaults) && ! array_is_list($defaults)) {
                $document[$group] = array_replace($defaults, $document[$group]);
            }
        }

        return $document;
    }

    /** @return array<string, mixed> */
    public function published(): array
    {
        if (! Schema::hasTable('website_settings')) {
            return $this->defaults();
        }
        $row = DB::table('website_settings')->find(1);
        if ($row?->publish_at && now()->gte($row->publish_at)) {
            $this->publishDue();
            $row = DB::table('website_settings')->find(1);
        }

        if (! $row) {
            return $this->defaults();
        }

        return $this->decode(json_encode(Cache::remember('website.published.'.$row->published_revision, 300, fn (): array => json_decode($row->published, true)), JSON_THROW_ON_ERROR));
    }

    public function initialize(): void
    {
        DB::transaction(function (): void {
            $defaults = json_encode($this->defaults(), JSON_THROW_ON_ERROR);
            DB::table('website_settings')->insertOrIgnore(['id' => 1, 'draft' => $defaults, 'published' => $defaults, 'revision' => 1, 'published_revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('website_settings')->lockForUpdate()->find(1);
            if (! DB::table('website_versions')->exists()) {
                DB::table('website_versions')->insert(['document' => $defaults, 'summary' => 'Original website', 'created_at' => now()]);
            }
        });
    }

    /** @return array<string, mixed> */
    public function forVisitor(): array
    {
        $document = $this->published();
        $filter = function (array $items, string $prefix) use (&$document): array {
            $mapping = [];
            $visible = [];
            foreach ($items as $index => $item) {
                if ($item['visible']) {
                    $mapping[$index] = count($visible);
                    $visible[] = $item;
                }
            }
            $translations = [];
            foreach ($document['translations'] as $key => $value) {
                if (preg_match('~^'.preg_quote($prefix, '~').'\.(\d+)\.(.+)$~', $key, $matches)) {
                    if (isset($mapping[(int) $matches[1]])) {
                        $translations[$prefix.'.'.$mapping[(int) $matches[1]].'.'.$matches[2]] = $value;
                    }
                } else {
                    $translations[$key] = $value;
                }
            }
            $document['translations'] = $translations;

            return $visible;
        };
        $document['sections'] = $filter($document['sections'], 'sections');
        foreach ($document['pages'] as $index => $page) {
            $document['pages'][$index]['sections'] = $filter($page['sections'], 'pages.'.$index.'.sections');
        }
        $document['pages'] = $filter($document['pages'], 'pages');

        return $document;
    }

    /** @param array<string, mixed> $document */
    public function save(array $document, int $revision): int
    {
        $normalize = function (array $values) use (&$normalize): array {
            foreach ($values as $key => $value) {
                $values[$key] = is_array($value) ? $normalize($value) : ($value ?? '');
            }

            return $values;
        };
        $document = $normalize($document);

        return DB::transaction(function () use ($document, $revision): int {
            $row = DB::table('website_settings')->lockForUpdate()->find(1);
            $this->assertRevision($row->revision, $revision);
            DB::table('website_settings')->where('id', 1)->update(['draft' => json_encode($document, JSON_THROW_ON_ERROR), 'revision' => $revision + 1, 'updated_at' => now()]);
            AuditEvent::record('website.draft_saved', ['revision' => $revision + 1]);

            return $revision + 1;
        });
    }

    public function publish(int $revision, string $summary, ?string $at = null): void
    {
        DB::transaction(function () use ($revision, $summary, $at): void {
            $row = DB::table('website_settings')->lockForUpdate()->find(1);
            $this->assertRevision($row->revision, $revision);
            if ($at) {
                DB::table('website_settings')->where('id', 1)->update(['scheduled_document' => $row->draft, 'publish_at' => $at, 'updated_at' => now()]);
                AuditEvent::record('website.publication_scheduled', ['publish_at' => $at]);
            } else {
                $this->applyPublication($row->draft, $summary);
            }
        });
    }

    public function publishDue(): void
    {
        DB::transaction(function (): void {
            $row = DB::table('website_settings')->lockForUpdate()->find(1);
            if ($row?->publish_at && now()->gte($row->publish_at)) {
                $this->applyPublication($row->scheduled_document, 'Scheduled publication');
            }
        });
    }

    private function applyPublication(string $document, string $summary): void
    {
        DB::table('website_versions')->insert(['document' => $document, 'summary' => $summary, 'actor_id' => auth()->id(), 'created_at' => now()]);
        DB::table('website_settings')->where('id', 1)->update(['published' => $document, 'published_revision' => DB::raw('published_revision + 1'), 'scheduled_document' => null, 'publish_at' => null, 'updated_at' => now()]);
        AuditEvent::record('website.published', ['summary' => $summary]);
        Cache::forget('website.published');
    }

    private function assertRevision(int $current, int $expected): void
    {
        if ($current !== $expected) {
            throw ValidationException::withMessages(['revision' => 'Another administrator changed this draft. Reload before saving.']);
        }
    }
}
