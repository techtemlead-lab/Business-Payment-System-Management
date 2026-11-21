<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The stored rule names are renamed only when they are exactly an old default; a name someone typed is kept,
 * --dry-run writes nothing, and a second run finds nothing to do.
 */
class RenameStoredCommissionNamesTest extends TestCase
{
    use RefreshDatabase;

    private function store(string $ruleKey, string $name): void
    {
        DB::table('commission_rule_parameters')->updateOrInsert(['rule_key' => $ruleKey], [
            'category' => 'sub_dealer', 'name' => $name, 'percentage' => '1.00', 'parameters' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function nameOf(string $ruleKey): string
    {
        return (string) DB::table('commission_rule_parameters')->where('rule_key', $ruleKey)->value('name');
    }

    public function test_old_defaults_are_renamed_and_typed_names_are_kept(): void
    {
        $this->store('direct_referral', 'Direct referral');
        $this->store('own_dealer_cashback', 'Own purchase cashback');
        $this->store('dealer_monthly_salary', 'Dealer monthly salary pool');
        $this->store('sub_dealer_tour', 'Our summer tour');
        $this->store('d2d_multitier', 'Team share (2nd + 3rd generation)');

        $this->artisan('madina:rename-stored-commission-names')->assertSuccessful();

        $this->assertSame('Team income (2nd and 3rd step)', $this->nameOf('d2d_multitier'));
        $this->assertSame('Staff commission', $this->nameOf('direct_referral'));
        $this->assertSame('Doctor Honorarium', $this->nameOf('own_dealer_cashback'));
        $this->assertSame('Dealer Acquisition Target Bonus', $this->nameOf('dealer_monthly_salary'));
        $this->assertSame('Our summer tour', $this->nameOf('sub_dealer_tour'), 'A name typed by a person is never touched.');
        $this->assertSame('1.00', (string) DB::table('commission_rule_parameters')->where('rule_key', 'direct_referral')->value('percentage'));
    }

    public function test_dry_run_writes_nothing_and_a_second_run_changes_nothing(): void
    {
        $this->store('sub_dealer_salary', 'Sub-dealer monthly salary');

        $this->artisan('madina:rename-stored-commission-names', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame('Sub-dealer monthly salary', $this->nameOf('sub_dealer_salary'));

        $this->artisan('madina:rename-stored-commission-names')->assertSuccessful();
        $this->assertSame('Sub-dealer salary', $this->nameOf('sub_dealer_salary'));

        $this->artisan('madina:rename-stored-commission-names')->expectsOutputToContain('Nothing to change')->assertSuccessful();
        $this->assertSame('Sub-dealer salary', $this->nameOf('sub_dealer_salary'));
    }
}
