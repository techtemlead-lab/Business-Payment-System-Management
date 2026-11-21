<?php

namespace App\Console\Commands;

use App\Domain\Commissions\CommissionPolicy;
use App\Support\Audit;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The client renamed several commissions (2026-11-20). Screens read the names from the language files, but the
 * rule table still holds the old default name of each rule from when it was first saved. This command renames a
 * stored rule name only when it is exactly one of the old default names; a name someone typed is never touched.
 * Rule keys, rates and posted money are not changed, and past policy snapshots stay as they were. Running it twice
 * is safe: the second run finds nothing to do. Use --dry-run to see the list without writing.
 */
#[Signature('madina:rename-stored-commission-names {--dry-run : Only list what would change}')]
#[Description('Replace the old default commission names stored in the rule table with the new names (exact old defaults only).')]
class RenameStoredCommissionNames extends Command
{
    /**
     * Rule key => every old default name the table may hold, in either the first seed or the later code default.
     *
     * @var array<string, list<string>>
     */
    public const OldNames = [
        'own_dealer_cashback' => ['Own purchase cashback'],
        'direct_referral' => ['Direct referral'],
        'dealer_monthly_salary' => ['Dealer monthly salary pool'],
        'additional_rank' => ['Sub-dealer additional rank reserve'],
        'sub_dealer_salary' => ['Sub-dealer monthly salary'],
        'sub_dealer_tour' => ['Sub-dealer tour reserve'],
    ];

    public function handle(): int
    {
        $defaults = CommissionPolicy::defaults();
        $changes = [];
        foreach (self::OldNames as $ruleKey => $oldNames) {
            $rows = DB::table('commission_rule_parameters')->where('rule_key', $ruleKey)->whereIn('name', $oldNames)->get(['id', 'name']);
            foreach ($rows as $row) {
                $changes[] = ['id' => $row->id, 'rule_key' => $ruleKey, 'before' => $row->name, 'after' => (string) $defaults[$ruleKey]['name']];
            }
        }

        if ($changes === []) {
            $this->info('No stored commission name holds an old default. Nothing to change.');

            return self::SUCCESS;
        }
        $this->table(['Row', 'Rule', 'Stored name', 'New name'], array_map(fn (array $change): array => array_values($change), $changes));
        if ($this->option('dry-run')) {
            $this->warn(count($changes).' name(s) would change. Run again without --dry-run to write them.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($changes): void {
            foreach ($changes as $change) {
                DB::table('commission_rule_parameters')->where('id', $change['id'])->where('name', $change['before'])->update(['name' => $change['after'], 'updated_at' => now()]);
            }
            Audit::record(null, 'commission.stored_names_renamed', 'commission_rule_parameters', ['changed' => count($changes), 'rules' => array_column($changes, 'rule_key')]);
        });
        $this->info(count($changes).' stored name(s) renamed.');

        return self::SUCCESS;
    }
}
