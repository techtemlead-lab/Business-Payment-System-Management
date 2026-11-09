<?php

namespace Tests\Feature;

use App\Domain\Support\ChatRouting;
use App\Models\User;
use App\Support\PortalCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Opens every page of every portal, in Bangla and English, as the kind of user who uses it,
 * so a page that crashes on the server is caught before it reaches a dealer or the office.
 */
class EveryPageSmokeTest extends TestCase
{
    use RefreshDatabase;

    private int $visited = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seedClassicPlaceCodes();
    }

    /** @return array<string, array{string}> */
    public static function locales(): array
    {
        return ['bangla' => ['bn'], 'english' => ['en']];
    }

    /** @return list<string> */
    private function visit(User $user, string $portal, string $locale): array
    {
        $failures = [];
        foreach (PortalCatalog::sections($portal) as $section) {
            $path = $section === 'overview' ? '/' : '/'.$section;
            $response = $this->actingAs($user)->withSession(['locale' => $locale])->onPortal($portal)->get($path);
            $toLogin = $response->isRedirection() && str_contains((string) $response->headers->get('Location'), 'login');
            if (($response->getStatusCode() !== 200 && ! $response->isRedirection()) || $toLogin) {
                $failures[] = $portal.$path.' → '.$response->getStatusCode().($toLogin ? ' (sent to login)' : '');
            }
            $this->visited++;
        }

        return $failures;
    }

    #[DataProvider('locales')]
    public function test_every_owner_page_opens(string $locale): void
    {
        $this->assertSame([], $this->visit(User::factory()->staff()->create(), 'sadmin', $locale));
        $this->assertGreaterThan(40, $this->visited);
    }

    #[DataProvider('locales')]
    public function test_every_office_staff_page_opens_for_staff_with_every_permission(string $locale): void
    {
        // Prescriptions open only for staff switched on for company stock.
        $staff = User::factory()->staff('employee')->create(['profile' => ['permissions' => config('madina.roles.super_admin')], 'can_prescribe' => true]);
        // Partner chat opens only for staff the owner nominated to answer it.
        DB::table('business_settings')->insert([
            'key' => ChatRouting::SettingKey,
            'value' => json_encode(['answerers' => [['user_id' => $staff->id, 'scope' => ChatRouting::ScopeCompany]]]),
            'version' => 1,
            'updated_by' => User::factory()->staff('md')->create()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame([], array_values($this->visit($staff, 'manage', $locale)));
    }

    #[DataProvider('locales')]
    public function test_every_dealer_page_opens(string $locale): void
    {
        // Prescribing is turned on so its page is opened too; it stays off by default (PrescriptionModuleTest).
        $this->assertSame([], $this->visit(User::factory()->dealer()->create(['status' => 'active', 'can_prescribe' => true]), 'dealer', $locale));
    }

    #[DataProvider('locales')]
    public function test_every_sub_dealer_page_opens(string $locale): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $subDealer = User::factory()->subDealer()->create(['status' => 'active', 'sponsor_id' => $dealer->id, 'can_prescribe' => true]);

        $this->assertSame([], $this->visit($subDealer, 'subdealer', $locale));
    }

    #[DataProvider('locales')]
    public function test_every_public_page_opens(string $locale): void
    {
        $failures = [];
        foreach (['/', '/products', '/dealers', '/about', '/contact', '/partnership', '/services', '/quality', '/faq', '/privacy', '/terms', '/notice', '/login', '/partner-login', '/outlets', '/feedback'] as $path) {
            $status = $this->withSession(['locale' => $locale])->get($path)->getStatusCode();
            if (! in_array($status, [200, 301, 302], true)) {
                $failures[] = $path.' → '.$status;
            }
        }
        $this->assertSame([], $failures);
    }
}
