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
        'd2d_multitier' => ['Team share (2nd + 3rd generation)'],
        'dealer_monthly_salary' => ['Dealer monthly salary pool', 'Dealer Acquisition Target Bonus'],
        'additional_rank' => ['Sub-dealer additional rank reserve'],
        'sub_dealer_salary' => ['Sub-dealer monthly salary'],
        'sub_dealer_tour' => ['Sub-dealer tour reserve'],
    ];

    /**
     * Old stored text => new text, for the notices already sent to partners. The client asked on 2026-11-28 for
     * "Marketing Officer", "Area Manager" and "Sales Manager" in full. Only the one-time rank prize names are
     * replaced; the ledger lines are worded again from their key, so they need no change.
     *
     * @var array<string, string>
     */
    public const OldNoticeNames = [
        'MO Rank Reward (One time)' => 'Marketing Officer Rank Reward (One time)',
        'AM Rank Reward (One time)' => 'Area Manager Rank Reward (One time)',
        'SM Rank Reward (One time)' => 'Sales Manager Rank Reward (One time)',
        'MO র‍্যাঙ্ক পুরস্কার (ওয়ান টাইম)' => 'মার্কেটিং অফিসার র‍্যাঙ্ক পুরস্কার (ওয়ান টাইম)',
        'AM র‍্যাঙ্ক পুরস্কার (ওয়ান টাইম)' => 'এরিয়া ম্যানেজার র‍্যাঙ্ক পুরস্কার (ওয়ান টাইম)',
        'SM র‍্যাঙ্ক পুরস্কার (ওয়ান টাইম)' => 'সেলস ম্যানেজার র‍্যাঙ্ক পুরস্কার (ওয়ান টাইম)',
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

        $noticeChanges = [];
        foreach (self::OldNoticeNames as $old => $new) {
            $count = DB::table('account_notices')->where('body', 'like', '%'.$old.'%')->count();
            if ($count > 0) {
                $noticeChanges[] = ['old' => $old, 'new' => $new, 'count' => $count];
            }
        }

        if ($changes === [] && $noticeChanges === []) {
            $this->info('No stored commission name holds an old default. Nothing to change.');

            return self::SUCCESS;
        }
        if ($changes !== []) {
            $this->table(['Row', 'Rule', 'Stored name', 'New name'], array_map(fn (array $change): array => array_values($change), $changes));
        }
        if ($noticeChanges !== []) {
            $this->table(['Old notice text', 'New text', 'Notices'], array_map(fn (array $change): array => array_values($change), $noticeChanges));
        }
        if ($this->option('dry-run')) {
            $this->warn(count($changes).' name(s) and '.array_sum(array_column($noticeChanges, 'count')).' notice(s) would change. Run again without --dry-run to write them.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($changes, $noticeChanges): void {
            foreach ($changes as $change) {
                DB::table('commission_rule_parameters')->where('id', $change['id'])->where('name', $change['before'])->update(['name' => $change['after'], 'updated_at' => now()]);
            }
            foreach ($noticeChanges as $change) {
                DB::table('account_notices')->where('body', 'like', '%'.$change['old'].'%')
                    ->update(['body' => DB::raw('replace(body, '.DB::getPdo()->quote($change['old']).', '.DB::getPdo()->quote($change['new']).')')]);
            }
            Audit::record(null, 'commission.stored_names_renamed', 'commission_rule_parameters', ['changed' => count($changes), 'rules' => array_column($changes, 'rule_key'), 'notices' => array_sum(array_column($noticeChanges, 'count'))]);
        });
        $this->info(count($changes).' stored name(s) and '.array_sum(array_column($noticeChanges, 'count')).' notice(s) renamed.');

        return self::SUCCESS;
    }
}
