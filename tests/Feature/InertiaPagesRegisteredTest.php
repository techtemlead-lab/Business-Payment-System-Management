<?php

namespace Tests\Feature;

use App\Support\PortalCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;
use Tests\TestCase;

/**
 * A page the server renders but resources/js/app.tsx doesn't list opens as a blank screen
 * with "pages[name] is not a function", and server-side tests can't see it. This keeps the
 * server's page names, that list and the page files in step.
 */
class InertiaPagesRegisteredTest extends TestCase
{
    public function test_every_page_the_server_renders_is_in_the_browser_page_list(): void
    {
        $unregistered = array_values(array_diff($this->renderedPageNames(), array_keys($this->registeredPages())));

        $this->assertSame([], $unregistered, 'Add these pages to the pages list in resources/js/app.tsx.');
    }

    public function test_every_page_the_server_renders_has_a_page_file(): void
    {
        $withoutFile = array_values(array_filter(
            $this->renderedPageNames(),
            fn (string $name): bool => ! File::exists(resource_path("js/pages/{$name}.tsx")),
        ));

        $this->assertSame([], $withoutFile, 'These pages have no file in resources/js/pages.');
    }

    public function test_every_listed_page_loads_the_file_with_its_own_name(): void
    {
        foreach ($this->registeredPages() as $name => $importPath) {
            $this->assertSame("./pages/{$name}", $importPath, "The {$name} entry in resources/js/app.tsx loads the wrong file.");
        }
    }

    public function test_every_render_call_gives_a_page_name_this_guard_can_check(): void
    {
        $uncheckable = $this->renderCallPageArguments()
            ->reject(fn (string $argument): bool => $this->quotedPageName($argument) !== null || str_starts_with($argument, 'PortalCatalog::page('))
            ->values()
            ->all();

        $this->assertSame([], $uncheckable, 'Pass the page name as a quoted string so this test can check it.');
    }

    /**
     * Every name the server can render: quoted names in app/ plus each portal's dashboard,
     * which WorkspaceController gets from PortalCatalog::page().
     *
     * @return list<string>
     */
    private function renderedPageNames(): array
    {
        $quotedNames = $this->renderCallPageArguments()
            ->map(fn (string $argument): ?string => $this->quotedPageName($argument))
            ->filter();
        $portalPages = collect(array_keys(config('portals')))
            ->map(fn (string $portal): string => PortalCatalog::page($portal));

        return $quotedNames->merge($portalPages)->unique()->sort()->values()->all();
    }

    /**
     * The first argument of every Inertia::render call in app/.
     *
     * @return Collection<int, string>
     */
    private function renderCallPageArguments(): Collection
    {
        $arguments = collect(File::allFiles(app_path()))
            ->flatMap(function (SplFileInfo $file): array {
                preg_match_all('/Inertia::render\(\s*([^,)]+)/', $file->getContents(), $matches);

                return $matches[1];
            })
            ->map(fn (string $argument): string => trim($argument));

        $this->assertNotEmpty($arguments, 'No Inertia::render calls were found in app/, so this test has stopped checking anything.');

        return $arguments;
    }

    private function quotedPageName(string $argument): ?string
    {
        return preg_match('/^([\'"])(.+)\1$/', $argument, $match) === 1 ? $match[2] : null;
    }

    /**
     * The pages list in resources/js/app.tsx.
     *
     * @return array<string, string> Page name => the path it imports.
     */
    private function registeredPages(): array
    {
        preg_match_all(
            '~^\s*[\'"]?([\w/-]+)[\'"]?\s*:\s*\(\)\s*=>\s*import\(\s*[\'"]([^\'"]+)[\'"]\s*\)~m',
            File::get(resource_path('js/app.tsx')),
            $matches,
        );

        $this->assertNotEmpty($matches[1], 'Could not read the pages list in resources/js/app.tsx.');

        return array_combine($matches[1], $matches[2]);
    }
}
