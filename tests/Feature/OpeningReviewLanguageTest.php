<?php

namespace Tests\Feature;

use App\Domain\Finance\FinancialReadiness;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OpeningReviewLanguageTest extends TestCase
{
    use RefreshDatabase;

    public function test_opening_balances_entered_in_english_can_be_verified_by_someone_reading_bangla(): void
    {
        $this->seed(CommissionRuleSeeder::class);
        Storage::fake('local');
        $owner = User::factory()->staff('super_admin')->create();
        $staff = User::factory()->enterOnly()->create();
        $readiness = app(FinancialReadiness::class);
        $totals = ['wallet_available' => '0.00', 'wallet_held' => '0.00', 'order_paid' => '0.00', 'withdrawal_paid' => '0.00'];

        app()->setLocale('en');
        $id = $readiness->submit($staff, $totals, 'Closing bank statement', UploadedFile::fake()->create('statement.pdf', 5, 'application/pdf'));

        app()->setLocale('bn');
        $readiness->verify($owner, $id);

        $this->assertDatabaseHas('financial_verifications', ['id' => $id, 'verified_by' => $owner->id]);
    }
}
