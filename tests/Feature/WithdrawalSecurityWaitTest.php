<?php

namespace Tests\Feature;

use App\Domain\Finance\WithdrawalSecurityWait;
use App\Domain\Payouts\ManualPayoutService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WithdrawalSecurityWaitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->enableFinancialPosting();
        $this->travelTo(now()->setDate(2026, 9, 15)->setTime(12, 0));
    }

    public function test_the_wait_is_two_hours_unless_the_owner_changes_it(): void
    {
        $this->assertSame(['hours' => 2, 'version' => 0], app(WithdrawalSecurityWait::class)->settings());
    }

    public function test_a_new_partner_who_never_changed_anything_can_withdraw(): void
    {
        $this->assertNotEmpty($this->withdraw($this->partnerWithWallet()));
    }

    public function test_changing_the_password_or_the_phone_starts_the_wait(): void
    {
        $byPassword = $this->partnerWithWallet();
        $byPassword->forceFill(['password' => 'AnotherPassword123'])->save();
        $byPhone = $this->partnerWithWallet();
        $byPhone->update(['phone' => '01712345678']);
        $other = $this->partnerWithWallet();
        $other->update(['name' => 'New Name']);

        $this->assertNotNull($byPassword->fresh()->credentials_changed_at);
        $this->assertNotNull($byPhone->fresh()->credentials_changed_at);
        $this->assertNull($other->fresh()->credentials_changed_at);
    }

    public function test_a_withdrawal_inside_the_wait_is_refused_with_the_time_and_audited(): void
    {
        $partner = $this->partnerWithWallet();
        $partner->forceFill(['password' => 'AnotherPassword123'])->save();

        $this->travelTo(now()->addMinutes(30));
        try {
            $this->withdraw($partner);
            $this->fail('A withdrawal inside the wait must be refused.');
        } catch (ValidationException $exception) {
            $opens = $partner->fresh()->credentials_changed_at->copy()->addHours(2)->timezone('Asia/Dhaka');
            $this->assertStringContainsString($opens->format('g:i'), $exception->errors()['amount'][0]);
        }

        $this->assertSame(0, DB::table('withdrawals')->count());
        $this->assertSame('500.00', DB::table('wallets')->where('user_id', $partner->id)->value('available'));
        $this->assertDatabaseHas('audit_events', ['actor_id' => $partner->id, 'action' => 'withdrawal.refused_security_wait']);
    }

    public function test_the_refusal_is_shown_to_the_partner_on_the_website_in_both_languages(): void
    {
        $partner = $this->partnerWithWallet();
        $account = $this->savePayoutAccount($partner);
        $partner->update(['phone' => '01712345678']);

        foreach (['en', 'bn'] as $locale) {
            $partner->forceFill(['locale' => $locale])->save();
            $response = $this->actingAs($partner->fresh())->onPortal('dealer')->post('/commands/withdrawals', [
                'amount' => '300.00', 'payout_account_id' => $account, 'idempotency_key' => (string) Str::uuid(),
            ]);
            $response->assertSessionHasErrors('amount');
            $this->assertStringContainsString(Str::before(__('withdrawal_wait.wd_wait_reason', [], $locale), ':time'), (string) session('errors')->first('amount'));
        }
    }

    public function test_the_withdrawal_opens_when_the_wait_is_over(): void
    {
        $partner = $this->partnerWithWallet();
        $partner->forceFill(['password' => 'AnotherPassword123'])->save();

        $this->travelTo(now()->addHours(2)->addMinute());

        $this->assertNotEmpty($this->withdraw($partner));
    }

    public function test_the_owner_can_set_the_hours_and_zero_switches_the_wait_off(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $partner = $this->partnerWithWallet();
        $partner->forceFill(['password' => 'AnotherPassword123'])->save();

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/operations/settings/withdrawal-security-wait', ['hours' => 24, 'version' => 0])
            ->assertSessionHasNoErrors();
        $this->assertSame(24, app(WithdrawalSecurityWait::class)->settings()['hours']);
        $this->assertDatabaseHas('audit_events', ['actor_id' => $owner->id, 'action' => 'withdrawal.security_wait_changed']);

        $this->travelTo(now()->addHours(5));
        $this->assertRefused($partner);

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/operations/settings/withdrawal-security-wait', ['hours' => 0, 'version' => 1])
            ->assertSessionHasNoErrors();
        $this->assertNotEmpty($this->withdraw($partner));
    }

    public function test_only_owners_can_change_the_hours_and_the_range_is_checked(): void
    {
        $finance = User::factory()->staff('finance')->create();
        $status = $this->actingAs($finance)->onPortal('manage')->post('/commands/operations/settings/withdrawal-security-wait', ['hours' => 0, 'version' => 0])->getStatusCode();
        $this->assertContains($status, [403, 404]);
        $this->assertSame(2, app(WithdrawalSecurityWait::class)->settings()['hours']);

        $owner = User::factory()->staff('super_admin')->create();
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/operations/settings/withdrawal-security-wait', ['hours' => 100000, 'version' => 0])
            ->assertSessionHasErrors('hours');
    }

    public function test_a_replayed_request_is_not_refused_by_a_wait_that_started_later(): void
    {
        $partner = $this->partnerWithWallet();
        $key = (string) Str::uuid();
        $first = app(ManualPayoutService::class)->submit($partner, ['amount' => '300.00', 'channel' => 'bKash', 'destination' => '01700000000', 'idempotency_key' => $key]);
        $partner->forceFill(['password' => 'AnotherPassword123'])->save();

        $again = app(ManualPayoutService::class)->submit($partner->fresh(), ['amount' => '300.00', 'channel' => 'bKash', 'destination' => '01700000000', 'idempotency_key' => $key]);

        $this->assertSame($first['id'], $again['id']);
        $this->assertFalse($again['created']);
    }

    private function assertRefused(User $partner): void
    {
        try {
            $this->withdraw($partner);
            $this->fail('A withdrawal inside the wait must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount', $exception->errors());
        }
    }

    private function partnerWithWallet(): User
    {
        $partner = User::factory()->dealer()->create(['status' => 'active']);
        DB::table('wallets')->updateOrInsert(['user_id' => $partner->id], [
            'available' => '500.00', 'held' => '0.00', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $partner;
    }

    private function withdraw(User $partner): string
    {
        return app(ManualPayoutService::class)->request($partner->fresh(), [
            'amount' => '300.00', 'channel' => 'bKash', 'destination' => '01700000000', 'idempotency_key' => (string) Str::uuid(),
        ]);
    }
}
