<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Owner decision (2026-11-17): place codes lose the hyphen after the brand prefix, so MNHC-100
 * becomes MNHC100 and a sub-dealer serial MNHC-100001-000001 becomes MNHC100001-000001 (the
 * hyphen before the serial stays). Every place the code is saved as text is rewritten here.
 *
 * Self-contained on purpose: it does not call PlaceCode helpers, so later changes there cannot
 * change what this migration did. A row whose new spelling is already used by another row is
 * left as it is and written to the log; lookups still accept the older spelling, so it keeps
 * working until someone settles it by hand. down() writes the hyphen back the same way.
 */
return new class extends Migration
{
    private const Prefix = 'MNHC';

    /** Keys in users.profile that hold a place code or a partner code. */
    private const ProfileKeys = ['place_code', 'union_place_code', 'area_code', 'management_refer_code'];

    public function up(): void
    {
        $this->rewrite(toLegacy: false);
    }

    public function down(): void
    {
        $this->rewrite(toLegacy: true);
    }

    private function rewrite(bool $toLegacy): void
    {
        /** @var list<array{table: string, column: string, id: int, from: string, to: string}> $skipped */
        $skipped = [];

        DB::transaction(function () use ($toLegacy, &$skipped): void {
            $this->rewritePlaceCodes($toLegacy, $skipped);
            $this->rewriteUniqueColumn('users', 'dealer_code', $toLegacy, $skipped);
            $this->rewriteUniqueColumn('users', 'refer_code', $toLegacy, $skipped);
            $this->rewriteUniqueColumn('partner_former_codes', 'code', $toLegacy, $skipped);
            $this->rewriteProfiles($toLegacy);
            $this->rewritePlainColumn('money_events', 'partner_code', $toLegacy);
        });

        if ($skipped !== []) {
            Log::warning('Place-code spelling change skipped rows whose new spelling is already taken; they keep the old spelling.', [
                'direction' => $toLegacy ? 'MNHC100 to MNHC-100' : 'MNHC-100 to MNHC100',
                'rows' => $skipped,
            ]);
        }
    }

    /**
     * Place codes, and the territory key of each dealer seat that was built from the old code.
     *
     * @param  list<array{table: string, column: string, id: int, from: string, to: string}>  $skipped
     */
    private function rewritePlaceCodes(bool $toLegacy, array &$skipped): void
    {
        $this->candidates('place_codes', 'code', $toLegacy)
            ->select(['id', 'level', 'code'])
            ->chunkById(200, function ($rows) use ($toLegacy, &$skipped): void {
                foreach ($rows as $row) {
                    $from = (string) $row->code;
                    $to = $this->convert($from, $toLegacy);
                    if ($to === null) {
                        continue;
                    }
                    if (DB::table('place_codes')->where('code', $to)->where('id', '!=', $row->id)->exists()) {
                        $skipped[] = ['table' => 'place_codes', 'column' => 'code', 'id' => (int) $row->id, 'from' => $from, 'to' => $to];

                        continue;
                    }
                    DB::table('place_codes')->where('id', $row->id)->update(['code' => $to]);
                    $this->rewriteSeatKeys((int) $row->id, (string) $row->level, $from, $to);
                }
            });
    }

    private function rewriteSeatKeys(int $placeId, string $placeLevel, string $from, string $to): void
    {
        $seats = DB::table('area_dealer_assignments')
            ->where('place_code_id', $placeId)
            ->whereNotNull('territory_key')
            ->get(['id', 'level', 'territory_key']);
        foreach ($seats as $seat) {
            foreach (array_unique([(string) $seat->level, $placeLevel]) as $level) {
                if ($seat->territory_key !== hash('sha256', $level.'|code:'.$from)) {
                    continue;
                }
                $newKey = hash('sha256', $level.'|code:'.$to);
                if (! DB::table('area_dealer_assignments')->where('territory_key', $newKey)->exists()) {
                    DB::table('area_dealer_assignments')->where('id', $seat->id)->update(['territory_key' => $newKey]);
                }
                break;
            }
        }
    }

    /**
     * A column with a unique index: a row is only changed when no other row holds the new spelling.
     *
     * @param  list<array{table: string, column: string, id: int, from: string, to: string}>  $skipped
     */
    private function rewriteUniqueColumn(string $table, string $column, bool $toLegacy, array &$skipped): void
    {
        $this->candidates($table, $column, $toLegacy)
            ->select(['id', $column])
            ->chunkById(200, function ($rows) use ($table, $column, $toLegacy, &$skipped): void {
                foreach ($rows as $row) {
                    $from = (string) $row->{$column};
                    $to = $this->convert($from, $toLegacy);
                    if ($to === null) {
                        continue;
                    }
                    if (DB::table($table)->where($column, $to)->where('id', '!=', $row->id)->exists()) {
                        $skipped[] = ['table' => $table, 'column' => $column, 'id' => (int) $row->id, 'from' => $from, 'to' => $to];

                        continue;
                    }
                    DB::table($table)->where('id', $row->id)->update([$column => $to]);
                }
            });
    }

    private function rewritePlainColumn(string $table, string $column, bool $toLegacy): void
    {
        $this->candidates($table, $column, $toLegacy)
            ->select(['id', $column])
            ->chunkById(500, function ($rows) use ($table, $column, $toLegacy): void {
                foreach ($rows as $row) {
                    $to = $this->convert((string) $row->{$column}, $toLegacy);
                    if ($to !== null) {
                        DB::table($table)->where('id', $row->id)->update([$column => $to]);
                    }
                }
            });
    }

    /**
     * The code keys inside users.profile. Decoded as objects so the rest of the profile,
     * empty objects included, is written back exactly as it was.
     */
    private function rewriteProfiles(bool $toLegacy): void
    {
        DB::table('users')
            ->whereNotNull('profile')
            ->whereRaw('CAST(profile AS TEXT) LIKE ?', ['%'.self::Prefix.'%'])
            ->select(['id', 'profile'])
            ->chunkById(200, function ($rows) use ($toLegacy): void {
                foreach ($rows as $row) {
                    $profile = json_decode((string) $row->profile, false);
                    if (! $profile instanceof stdClass) {
                        continue;
                    }
                    $changed = false;
                    foreach (self::ProfileKeys as $key) {
                        $to = isset($profile->{$key}) && is_string($profile->{$key}) ? $this->convert($profile->{$key}, $toLegacy) : null;
                        if ($to !== null) {
                            $profile->{$key} = $to;
                            $changed = true;
                        }
                    }
                    if ($changed) {
                        DB::table('users')->where('id', $row->id)->update(['profile' => json_encode($profile, JSON_THROW_ON_ERROR)]);
                    }
                }
            });
    }

    /** Rows whose column may hold the spelling being replaced; convert() makes the final call. */
    private function candidates(string $table, string $column, bool $toLegacy): Builder
    {
        $query = DB::table($table)->whereNotNull($column);

        return $toLegacy
            ? $query->where($column, 'like', self::Prefix.'%')->where($column, 'not like', self::Prefix.'-%')
            : $query->where($column, 'like', self::Prefix.'-%');
    }

    /**
     * MNHC-100 → MNHC100 and MNHC-100001-000001 → MNHC100001-000001 (or back when $toLegacy).
     * Null when the value is not in the spelling being replaced.
     */
    private function convert(string $value, bool $toLegacy): ?string
    {
        $pattern = $toLegacy
            ? '/^'.self::Prefix.'(\d{2,8})((?:-[A-Z0-9]{1,16})?)$/D'
            : '/^'.self::Prefix.'-(\d{2,8})((?:-[A-Z0-9]{1,16})?)$/D';
        if (preg_match($pattern, $value, $matches) !== 1) {
            return null;
        }

        return self::Prefix.($toLegacy ? '-' : '').$matches[1].$matches[2];
    }
};
