<?php

namespace Tests\Feature;

use App\Support\ReasonFields;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Every place on the owner's list must be asked about by the server rules and by a page,
 * and no rule or page may name a place that is not on the list. A reason box added later
 * without its line in ReasonFields::Places fails here.
 */
class ReasonFieldPlacesAreWiredTest extends TestCase
{
    /**
     * @param  list<string>  $extensions
     * @return array<string, string> file path => contents
     */
    private function sources(string $directory, array $extensions): array
    {
        $files = [];
        foreach ((new Finder)->files()->in($directory)->name(array_map(fn (string $extension): string => '*.'.$extension, $extensions)) as $file) {
            $files[$file->getRealPath()] = $file->getContents();
        }

        return $files;
    }

    /**
     * The quoted place keys inside every match of a pattern whose first group holds one
     * quoted key or a bracketed list of them.
     *
     * @param  array<string, string>  $sources
     * @return array<string, string> place => the file that names it
     */
    private function namedPlaces(array $sources, string $pattern): array
    {
        $named = [];
        foreach ($sources as $path => $code) {
            preg_match_all($pattern, $code, $matches);
            foreach ($matches[1] as $argument) {
                preg_match_all("/['\"]([a-z_]+\\.[a-z_]+)['\"]/", $argument, $keys);
                foreach ($keys[1] as $key) {
                    $named[$key] = $path;
                }
            }
        }

        return $named;
    }

    public function test_every_place_is_asked_about_by_the_server(): void
    {
        $sources = $this->sources(app_path(), ['php']);
        unset($sources[realpath(app_path('Support/ReasonFields.php'))]);
        $code = implode("\n", $sources);

        foreach (array_keys(ReasonFields::Places) as $place) {
            $this->assertStringContainsString("'".$place."'", $code, $place.' is on the list but no server code asks about it.');
        }
    }

    public function test_the_server_asks_only_about_places_on_the_list(): void
    {
        $named = $this->namedPlaces(
            $this->sources(app_path(), ['php']),
            '/ReasonFields::(?:rule|required|mode|label)\(\s*((?:[^()]|\([^()]*\))*?)[,)]/',
        );

        $this->assertNotSame([], $named);
        foreach ($named as $place => $path) {
            $this->assertArrayHasKey($place, ReasonFields::Places, $place.' in '.$path.' is not on the list.');
        }
    }

    public function test_every_place_has_a_box_on_a_page(): void
    {
        $code = implode("\n", $this->sources(resource_path('js'), ['ts', 'tsx']));

        foreach (array_keys(ReasonFields::Places) as $place) {
            $this->assertStringContainsString("'".$place."'", $code, $place.' is on the list but no page asks about it.');
        }
    }

    public function test_pages_name_only_places_on_the_list(): void
    {
        $sources = $this->sources(resource_path('js'), ['ts', 'tsx']);
        $named = [
            ...$this->namedPlaces($sources, '/\bplace\s*[:=]\s*\{?\s*(\[[^\]]*\]|[\'"][a-z_.]+[\'"])/'),
            ...$this->namedPlaces($sources, '/\b(?:useReasonField|reasonOf|reasonFor)\(\s*(\[[^\]]*\]|[\'"][a-z_.]+[\'"])/'),
        ];

        $this->assertNotSame([], $named);
        foreach ($named as $place => $path) {
            $this->assertArrayHasKey($place, ReasonFields::Places, $place.' in '.$path.' is not on the list.');
        }
    }
}
