<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Owner decision (2026-11-09): a partner's dealer or sub-dealer code IS their refer code. Older
 * accounts may hold a different refer code, so it becomes the code, and the one they had goes on
 * the former-codes list, because cards, posters and QR codes already printed still carry it.
 */
return new class extends Migration
{
    private const RetiredCodeMark = '-CLOSED-';

    public function up(): void
    {
        DB::transaction(function (): void {
            $partners = $this->partnersToCorrect();
            if ($partners->isEmpty()) {
                return;
            }
            // Cleared first: two partners may hold each other's codes, and the column must stay unique at every step.
            DB::table('users')->whereIn('id', $partners->pluck('id'))->update(['refer_code' => null]);
            foreach ($partners as $partner) {
                DB::table('users')->where('id', $partner->id)->update(['refer_code' => $partner->dealer_code]);
            }
            $this->rememberOldCodes($partners);
        });
    }

    /** The old values stay on the former-codes list, and which were hand-picked is not recorded anywhere else. */
    public function down(): void {}

    /**
     * Partners whose refer code differs from their code. Anyone who cannot be corrected safely is
     * left as they were and logged, so one odd row does not stop a deploy.
     *
     * @return Collection<int, object{id: int, dealer_code: string, refer_code: ?string}>
     */
    private function partnersToCorrect(): Collection
    {
        $partners = DB::table('users')
            ->whereIn('role', ['dealer', 'sub_dealer'])
            ->whereNotNull('dealer_code')
            ->where('dealer_code', '<>', '')
            ->whereRaw('refer_code IS DISTINCT FROM dealer_code')
            ->orderBy('id')
            ->get(['id', 'dealer_code', 'refer_code']);

        $codeHeldElsewhere = DB::table('users')
            ->whereNotIn('id', $partners->pluck('id'))
            ->whereIn('refer_code', $partners->pluck('dealer_code'))
            ->pluck('refer_code')
            ->flip();

        [$safe, $skipped] = $partners->partition(fn (object $partner): bool => strlen((string) $partner->dealer_code) <= 40 && ! $codeHeldElsewhere->has($partner->dealer_code));
        if ($skipped->isNotEmpty()) {
            Log::warning('migration.refer_code_not_corrected', ['user_ids' => $skipped->pluck('id')->all()]);
        }

        return $safe->values();
    }

    /**
     * @param  Collection<int, object{id: int, dealer_code: string, refer_code: ?string}>  $partners
     */
    private function rememberOldCodes(Collection $partners): void
    {
        $currentCodes = DB::table('users')
            ->whereNotNull('dealer_code')
            ->pluck('dealer_code')
            ->map(fn (string $code): string => strtoupper($code))
            ->flip();

        foreach ($partners as $partner) {
            $oldCode = strtoupper(trim((string) $partner->refer_code));
            if ($oldCode === '' || str_contains($oldCode, self::RetiredCodeMark) || $currentCodes->has($oldCode)) {
                continue;
            }
            DB::table('partner_former_codes')->upsert(
                [['user_id' => $partner->id, 'code' => $oldCode, 'created_at' => now(), 'updated_at' => now()]],
                ['code'],
                ['user_id', 'updated_at'],
            );
        }
    }
};
