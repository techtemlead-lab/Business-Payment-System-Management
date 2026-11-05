<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PortalCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $staff = User::factory()->staff('employee')->create(['profile' => ['permissions' => config('madina.roles.super_admin')]]);

        $failures = array_filter(
            $this->visit($staff, 'manage', $locale),
            // The owners' special fund is shown only to owners, on the owner panel.
            fn (string $failure): bool => ! str_starts_with($failure, 'manage/benefits-fund'),
        );
        $this->assertSame([], array_values($failures));
    }

    #[DataProvider('locales')]
    public function test_every_dealer_page_opens(string $locale): void
    {
        $this->assertSame([], $this->visit(User::factory()->dealer()->create(['status' => 'active']), 'dealer', $locale));
    }

    #[DataProvider('locales')]
    public function test_every_sub_dealer_page_opens(string $locale): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $subDealer = User::factory()->subDealer()->create(['status' => 'active', 'sponsor_id' => $dealer->id]);

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
