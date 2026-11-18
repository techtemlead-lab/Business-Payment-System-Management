<?php

use App\Domain\Finance\UnownedShareSweeps;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Owner decision D1 (MA-04): referral money with no upline is company profit. Rows the
 * management fund collected under the old default move once, each with a company profit
 * row that names its source. Running it again moves nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('commission_allocations')->whereNull('user_id')->whereIn('category', ['direct_referral', 'd2d_gen2', 'd2d_gen3'])
            ->where('status', 'pending')->exists()) {
            return;
        }
        app(UnownedShareSweeps::class)->moveNoUplineReferrals();
    }

    /**
     * The moved rows keep their source id and the management fund label they had, so a person can
     * put them back by hand if the owner reverses D1. Nothing is undone automatically: undoing
     * would remove company profit rows that later refunds may already have touched.
     */
    public function down(): void {}
};
