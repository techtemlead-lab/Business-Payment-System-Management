<?php

namespace App\Domain\Integrations;

use App\Domain\Commissions\CommissionWallet;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class IntegrationSettings
{
    public const SettingsKey = 'service_integrations';

    public const StartupKey = 'finance_startup_mode';

    /** @return array{version: int, groups: array<string, mixed>} */
    public function snapshot(bool $revealSecrets = false): array
    {
        $stored = $this->stored(self::SettingsKey);
        $value = is_array($stored['value'] ?? null) ? $stored['value'] : [];
        $groups = [];
        foreach (IntegrationCatalog::groups() as $groupKey => $group) {
            $providers = [];
            foreach ($group['providers'] as $providerKey => $provider) {
                $row = array_merge($provider['defaults'], is_array($value[$groupKey][$providerKey] ?? null) ? $value[$groupKey][$providerKey] : []);
                $row['enabled'] = (bool) ($row['enabled'] ?? false);
                $providers[$providerKey] = $this->presentProvider($providerKey, $provider, $row, $revealSecrets);
            }
            $groups[$groupKey] = [
                'label' => $group['label'],
                'description' => $group['description'],
                'providers' => $providers,
            ];
        }

        return ['version' => $stored['version'], 'groups' => $groups];
    }

    /** @return array<string, mixed> */
    public function provider(string $group, string $provider): array
    {
        $defaults = IntegrationCatalog::groups()[$group]['providers'][$provider]['defaults']
            ?? throw ValidationException::withMessages(['provider' => __('portal.si_err_unknown_provider')]);
        $stored = $this->stored(self::SettingsKey)['value'][$group][$provider] ?? [];
        $row = array_merge($defaults, is_array($stored) ? $this->decryptSecrets($stored) : []);
        $row['enabled'] = (bool) ($row['enabled'] ?? false);

        return $row;
    }

    public function isEnabled(string $group, string $provider): bool
    {
        return (bool) ($this->provider($group, $provider)['enabled'] ?? false);
    }

    /** @return list<array{key: string, label: string}> */
    public function enabledCourierOptions(): array
    {
        $options = [];
        foreach (IntegrationCatalog::groups()['couriers']['providers'] as $key => $provider) {
            if ($this->isEnabled('couriers', $key)) {
                $options[] = ['key' => $key, 'label' => $provider['label']];
            }
        }

        return $options;
    }

    /** @return array{mode: ?string, version: int, label: string, description: string} */
    public function startupMode(): array
    {
        $stored = $this->stored(self::StartupKey);
        $mode = is_array($stored['value'] ?? null) ? ($stored['value']['mode'] ?? null) : null;
        $mode = in_array($mode, ['fresh', 'import'], true) ? $mode : null;

        return [
            'mode' => $mode,
            'version' => $stored['version'],
            'label' => match ($mode) {
                'fresh' => __('portal.startup_label_fresh'),
                'import' => __('portal.startup_label_import'),
                default => __('portal.startup_label_none'),
            },
            'description' => match ($mode) {
                'fresh' => __('portal.startup_desc_fresh'),
                'import' => __('portal.startup_desc_import'),
                default => __('portal.startup_desc_none'),
            },
        ];
    }

    public function saveStartupMode(User $actor, string $mode, int $version, string $reason): void
    {
        if (! in_array($mode, ['fresh', 'import'], true)) {
            throw ValidationException::withMessages(['mode' => __('portal.si_err_startup_mode')]);
        }
        DB::transaction(function () use ($actor, $mode, $version, $reason): void {
            CommissionWallet::lock();
            abort_unless($actor->fresh()->canAct('finance', 'edit') || $actor->fresh()->canAct('accounts', 'edit'), 403);
            $before = $this->startupMode();
            if ($version !== $before['version']) {
                throw ValidationException::withMessages(['version' => __('portal.si_err_startup_stale')]);
            }
            $this->persist(self::StartupKey, ['mode' => $mode], $version, $actor->id);
            Audit::record($actor->id, 'finance.startup_mode_changed', self::StartupKey, [
                'before' => $before['mode'], 'after' => $mode, 'reason' => $reason,
            ]);
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function saveProvider(User $actor, string $group, string $provider, array $input, int $version, string $reason): void
    {
        $catalog = IntegrationCatalog::groups()[$group]['providers'][$provider]
            ?? throw ValidationException::withMessages(['provider' => __('portal.si_err_unknown_provider')]);

        DB::transaction(function () use ($actor, $group, $provider, $input, $version, $reason, $catalog): void {
            CommissionWallet::lock();
            abort_unless($actor->fresh()->canAct('finance', 'edit') || $actor->fresh()->role === 'super_admin', 403);
            $snapshot = $this->snapshot(true);
            if ($version !== $snapshot['version']) {
                throw ValidationException::withMessages(['version' => __('portal.si_err_stale_version')]);
            }

            $current = $this->provider($group, $provider);
            $next = $catalog['defaults'];
            foreach ($catalog['fields'] as $field) {
                if ($field === 'enabled') {
                    $next['enabled'] = filter_var($input['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);

                    continue;
                }
                if (! array_key_exists($field, $input)) {
                    $next[$field] = $current[$field] ?? $next[$field] ?? '';

                    continue;
                }
                $value = $input[$field];
                if (in_array($field, IntegrationCatalog::SecretFields, true) && ($value === null || $value === '' || $value === '••••••••')) {
                    $next[$field] = $current[$field] ?? '';

                    continue;
                }
                $next[$field] = is_string($value) ? trim($value) : $value;
            }

            if ($next['enabled']) {
                if ($group === 'maps' && blank($next['api_key'] ?? null) && blank($next['map_embed_url'] ?? null)) {
                    throw ValidationException::withMessages(['api_key' => __('portal.si_err_maps_enable')]);
                }
                if ($group === 'bdris' && (blank($next['base_url'] ?? null) || blank($next['api_key'] ?? null))) {
                    throw ValidationException::withMessages(['base_url' => __('portal.si_err_bdris_enable')]);
                }
                if (in_array($group, ['sms', 'couriers', 'payments'], true) && blank($next['base_url'] ?? null)) {
                    throw ValidationException::withMessages(['base_url' => __('portal.si_err_base_url')]);
                }
                if (in_array($group, ['sms', 'couriers'], true) && blank($next['api_key'] ?? null)) {
                    throw ValidationException::withMessages(['api_key' => __('portal.si_err_api_key')]);
                }
                if ($group === 'payments' && blank($next['api_key'] ?? null) && blank($next['app_key'] ?? null) && blank($next['store_id'] ?? null)) {
                    throw ValidationException::withMessages(['api_key' => __('portal.si_err_credentials')]);
                }
            }

            $stored = $this->stored(self::SettingsKey);
            $all = is_array($stored['value'] ?? null) ? $stored['value'] : [];
            $all[$group][$provider] = $this->encryptSecrets($next);
            $this->persist(self::SettingsKey, $all, $version, $actor->id);
            Audit::record($actor->id, 'integrations.provider_saved', $group.'.'.$provider, [
                'enabled' => $next['enabled'],
                'base_url' => $next['base_url'] ?? null,
                'reason' => $reason,
            ]);
        }, 3);
    }

    /** @return array{enabled: bool, map_embed_url:?string} */
    public function mapsPublicConfig(): array
    {
        $maps = $this->provider('maps', 'google');

        // Never expose browser API keys in public HTML. Prefer a server-configured embed URL.
        return [
            'enabled' => (bool) $maps['enabled'],
            'map_embed_url' => $maps['enabled'] ? (string) ($maps['map_embed_url'] ?? '') : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $provider
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function presentProvider(string $providerKey, array $provider, array $row, bool $revealSecrets): array
    {
        $presented = [
            'key' => $providerKey,
            'label' => $provider['label'],
            'fields' => $provider['fields'],
            'enabled' => (bool) ($row['enabled'] ?? false),
        ];
        foreach ($provider['fields'] as $field) {
            if ($field === 'enabled') {
                continue;
            }
            $value = $row[$field] ?? '';
            if (in_array($field, IntegrationCatalog::SecretFields, true)) {
                $plain = $this->maybeDecrypt((string) $value);
                $presented[$field] = $revealSecrets ? $plain : ($plain !== '' ? '••••••••' : '');
                $presented[$field.'_set'] = $plain !== '';
            } else {
                $presented[$field] = $value;
            }
        }

        return $presented;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function encryptSecrets(array $row): array
    {
        foreach (IntegrationCatalog::SecretFields as $field) {
            if (! array_key_exists($field, $row)) {
                continue;
            }
            $value = (string) $row[$field];
            $row[$field] = $value === '' ? '' : encrypt($value);
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function decryptSecrets(array $row): array
    {
        foreach (IntegrationCatalog::SecretFields as $field) {
            if (! array_key_exists($field, $row)) {
                continue;
            }
            $row[$field] = $this->maybeDecrypt((string) $row[$field]);
        }

        return $row;
    }

    private function maybeDecrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }
        try {
            return (string) decrypt($value);
        } catch (\Throwable) {
            return $value;
        }
    }

    /** @return array{value: ?array, version: int} */
    private function stored(string $key): array
    {
        $row = DB::table('business_settings')->where('key', $key)->first();

        return [
            'value' => $row ? json_decode($row->value, true, 512, JSON_THROW_ON_ERROR) : null,
            'version' => $row?->version ?? 0,
        ];
    }

    /** @param  array<string, mixed>  $value */
    private function persist(string $key, array $value, int $version, int $actorId): void
    {
        $existing = DB::table('business_settings')->where('key', $key)->lockForUpdate()->first();
        if ($existing && (int) $existing->version !== $version) {
            throw ValidationException::withMessages(['version' => __('portal.si_err_stale_version')]);
        }
        if ($existing) {
            $updated = DB::table('business_settings')->where('key', $key)->where('version', $version)->update([
                'value' => json_encode($value, JSON_THROW_ON_ERROR),
                'version' => $version + 1,
                'updated_by' => $actorId,
                'updated_at' => now(),
            ]);
            if ($updated !== 1) {
                throw ValidationException::withMessages(['version' => __('portal.si_err_stale_version')]);
            }

            return;
        }
        DB::table('business_settings')->insert([
            'key' => $key,
            'value' => json_encode($value, JSON_THROW_ON_ERROR),
            'version' => $version + 1,
            'updated_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
