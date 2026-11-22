<?php

namespace Tests\Feature;

use App\Domain\Payouts\ManualPayoutService;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\Support\ReceiptImage;
use Tests\TestCase;

/**
 * Owner, 2026-11-21: money sent to bKash, Nagad, Rocket or a bank is sent by hand, and the partner is shown
 * a screenshot of the transfer. The screenshot is attached before the payout is marked paid; only the partner
 * and the office can open it.
 */
class WithdrawalTransferProofTest extends TestCase
{
    use RefreshDatabase;

    private User $approver;

    private User $payer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
        config(['madina.sms.driver' => 'array', 'madina.sms.sent' => []]);
        $this->approver = User::factory()->staff('finance')->create();
        $this->payer = User::factory()->staff('finance')->create();
    }

    public function test_an_online_payout_needs_the_screenshot_before_it_is_marked_paid(): void
    {
        [, $id] = $this->approvedBkashWithdrawal();

        $this->record($id, [])->assertSessionHasErrors('receipt');
        $this->record($id, ['receipt_later' => '1'])->assertSessionHasErrors(['receipt' => __('office_withdrawal.err_proof_first')]);

        $this->assertSame('approved', DB::table('withdrawals')->where('id', $id)->value('status'));
        $this->assertSame(0, DB::table('journal_entries')->where('event_key', 'manual-payout:'.$id)->count());
    }

    public function test_a_screenshot_marks_it_paid_once_and_the_partner_sees_it_with_the_date(): void
    {
        [$dealer, $id] = $this->approvedBkashWithdrawal();
        $screenshot = $this->screenshot();

        $this->record($id, ['receipt' => $screenshot])->assertSessionHasNoErrors();
        // Pressing again does not pay twice.
        $this->record($id, ['receipt' => ReceiptImage::make('again.png'), 'payment_reference' => 'BKX7Q2M9TB'])->assertSessionHasErrors('withdrawal');

        $withdrawal = DB::table('withdrawals')->where('id', $id)->sole();
        $this->assertSame('paid', $withdrawal->status);
        $this->assertSame($screenshot->getMimeType(), $withdrawal->receipt_mime);
        $this->assertSame(1, DB::table('journal_entries')->where('event_key', 'manual-payout:'.$id)->count());
        $this->assertCount(1, array_filter((array) config('madina.sms.sent', []), fn (array $sms): bool => ($sms['event'] ?? null) === 'withdrawal.paid'));

        $this->actingAs($dealer)->onPortal('dealer')->get('/withdrawals/'.$id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('withdrawal.proof_is_image', true)->where('withdrawal.has_receipt', true)->where('withdrawal.paid_at', $withdrawal->paid_at));
        $picture = $this->actingAs($dealer)->onPortal('dealer')->get('/withdrawals/'.$id.'/receipt?view=1')->assertOk();
        $this->assertSame($screenshot->getMimeType(), $picture->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', (string) $picture->headers->get('Content-Disposition'));
        $this->assertDatabaseHas('audit_events', ['action' => 'receipt.viewed', 'subject' => $id, 'actor_id' => $dealer->id]);
    }

    public function test_another_partner_cannot_open_the_proof(): void
    {
        [, $id] = $this->approvedBkashWithdrawal();
        $this->record($id, ['receipt' => ReceiptImage::make('bkash.png')])->assertSessionHasNoErrors();
        $stranger = User::factory()->dealer()->create(['status' => 'active']);

        $this->actingAs($stranger)->onPortal('dealer')->get('/withdrawals/'.$id.'/receipt?view=1')->assertNotFound();
        $this->actingAs($stranger)->onPortal('dealer')->get('/withdrawals/'.$id)->assertNotFound();
        $this->actingAs($this->approver)->onPortal('manage')->get('/withdrawals/'.$id.'/receipt?view=1')->assertOk();
    }

    /** @param array<string, mixed> $extra */
    private function record(string $id, array $extra): TestResponse
    {
        return $this->actingAs($this->payer)->onPortal('manage')->post('/commands/withdrawals/'.$id.'/record-payment', [
            'method' => 'bkash', 'wallet_number' => '01700000000', 'payment_reference' => 'BKX7Q2M9TA',
            'paid_at' => now()->subHour()->format('Y-m-d H:i:s'), 'note' => 'Sent from the company bKash', ...$extra,
        ]);
    }

    /** @return array{0: User, 1: string} */
    private function approvedBkashWithdrawal(): array
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active', 'phone' => '0173'.random_int(1000000, 9999999)]);
        DB::table('wallets')->updateOrInsert(['user_id' => $dealer->id], ['available' => '5000.00', 'held' => '0.00', 'created_at' => now(), 'updated_at' => now()]);
        $id = app(ManualPayoutService::class)->request($dealer, ['amount' => '1000.00', 'channel' => 'bKash', 'destination' => '01700000000', 'idempotency_key' => (string) Str::uuid()]);
        app(ManualPayoutService::class)->review($this->approver, $id, 'approved', 'Checked');

        return [$dealer, $id];
    }

    /** A WebP screenshot when this PHP can write WebP, otherwise the usual PNG one. */
    private function screenshot(): UploadedFile
    {
        $png = ReceiptImage::make('screenshot.png');
        if (! function_exists('imagewebp')) {
            return $png;
        }
        $image = imagecreatefrompng($png->getRealPath());
        if ($image === false) {
            throw new RuntimeException('GD could not read the screenshot.');
        }
        $path = tempnam(sys_get_temp_dir(), 'proof');
        imagewebp($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, 'screenshot.webp', 'image/webp', null, true);
    }
}
