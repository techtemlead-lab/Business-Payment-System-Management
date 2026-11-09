<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerAccountProvisioning;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sub-dealer codes count up inside a union. A code in another shape, such as one brought
 * in from the old register, must not make the count start again and hit a code already used.
 */
class NextSubDealerCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_code_in_another_shape_does_not_restart_the_serial(): void
    {
        User::factory()->subDealer()->create(['dealer_code' => 'MNHC-100001-000001']);
        User::factory()->subDealer()->create(['dealer_code' => 'MNHC-100001-OLD7']);

        $this->assertSame('MNHC-100001-000002', app(PartnerAccountProvisioning::class)->nextSubDealerCode('MNHC-100001'));
    }

    public function test_the_serial_continues_from_the_highest_regular_code(): void
    {
        User::factory()->subDealer()->create(['dealer_code' => 'MNHC-100001-000009']);
        User::factory()->subDealer()->create(['dealer_code' => 'MNHC-100001-000010']);

        $this->assertSame('MNHC-100001-000011', app(PartnerAccountProvisioning::class)->nextSubDealerCode('MNHC-100001'));
    }
}
