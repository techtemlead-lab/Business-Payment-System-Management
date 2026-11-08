<?php

namespace App\Http\Controllers;

use App\Domain\Integrations\CourierAreaMapping;
use App\Domain\Integrations\IntegrationSettings;
use App\Domain\Orders\CourierOptions;
use App\Jobs\FetchCourierAreas;
use App\Models\PlaceCode;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The owner's page for matching our place codes to Pathao's and RedX's own area numbers.
 */
class CourierAreaController extends Controller
{
    public const Views = ['unmapped', 'in_use', 'all'];

    public function index(Request $request, IntegrationSettings $settings): Response
    {
        abort_unless($request->user()->owner(), 403);
        $filters = $request->validate([
            'provider' => ['nullable', Rule::in(CourierAreaMapping::providers())],
            'view' => ['nullable', Rule::in(self::Views)],
            'level' => ['nullable', Rule::in(PlaceCode::AssignmentLevels)],
            'q' => 'nullable|string|max:60',
        ]);
        $provider = $filters['provider'] ?? CourierAreaMapping::providers()[0];
        $view = $filters['view'] ?? 'unmapped';

        $partnerCounts = DB::table('users')->whereIn('role', ['dealer', 'sub_dealer'])->where('status', 'active')
            ->whereRaw("COALESCE(profile->>'place_code', '') <> ''")
            ->selectRaw("profile->>'place_code' AS code, COUNT(*) AS partners")
            ->groupByRaw("profile->>'place_code'")->pluck('partners', 'code');
        $parents = DB::table('place_codes')->pluck('parent_id', 'id');
        $mappings = DB::table('courier_area_mappings')->where('provider', $provider)->get()->keyBy('place_code_id');
        $usedPlaces = DB::table('place_codes')->whereIn('code', $partnerCounts->keys())->pluck('id');
        $unmappedIds = $usedPlaces->filter(fn ($id): bool => $this->coveringPlace((int) $id, $parents, $mappings) === null)->values();

        $places = PlaceCode::query()
            ->when($view === 'unmapped', fn ($query) => $query->whereIn('id', $unmappedIds))
            ->when($view === 'in_use', fn ($query) => $query->whereIn('id', $usedPlaces))
            ->when($view === 'all', fn ($query) => $query->whereIn('level', PlaceCode::AssignmentLevels))
            ->when($filters['level'] ?? null, fn ($query, string $level) => $query->where('level', $level))
            ->when($filters['q'] ?? null, fn ($query, string $search) => $query->where(fn ($match) => $match->where('code', 'ilike', '%'.$search.'%')->orWhere('name', 'ilike', '%'.$search.'%')))
            ->orderBy('code')
            ->paginate(30)
            ->withQueryString();
        $codes = DB::table('place_codes')->whereIn('id', $mappings->keys()->merge($places->pluck('parent_id')->filter()))->pluck('code', 'id');

        return Inertia::render('CourierAreas', [
            'provider' => $provider,
            'providers' => array_map(fn (string $key): array => $this->providerRow($key, $settings), CourierAreaMapping::providers()),
            'filters' => ['view' => $view, 'level' => $filters['level'] ?? '', 'q' => $filters['q'] ?? ''],
            'summary' => ['in_use' => $usedPlaces->count(), 'unmapped' => $unmappedIds->count()],
            'firstLevelOptions' => $this->optionRows($provider, CourierAreaMapping::Levels[$provider][0], null),
            'places' => $places->through(function (PlaceCode $place) use ($partnerCounts, $parents, $mappings, $codes): array {
                $covering = $this->coveringPlace((int) $place->id, $parents, $mappings);

                return [
                    'id' => $place->id,
                    'code' => $place->code,
                    'name' => $place->name,
                    'level' => $place->level,
                    'partners' => (int) ($partnerCounts[$place->code] ?? 0),
                    'mapping' => $mappings->has($place->id) ? (array) $mappings[$place->id] : null,
                    'inherited_from' => $covering !== null && $covering !== (int) $place->id ? ($codes[$covering] ?? null) : null,
                ];
            }),
        ]);
    }

    /**
     * The courier's zones under a city, or areas under a zone, for the owner's picker.
     */
    public function options(Request $request): JsonResponse
    {
        abort_unless($request->user()->owner(), 403);
        $data = $request->validate([
            'provider' => ['required', Rule::in(CourierAreaMapping::providers())],
            'level' => ['required', Rule::in(['city', 'zone', 'area'])],
            'parent' => 'nullable|string|max:40',
        ]);

        return response()->json(['data' => $this->optionRows($data['provider'], $data['level'], $data['parent'] ?? null)]);
    }

    public function fetch(Request $request, IntegrationSettings $settings): RedirectResponse
    {
        abort_unless($request->user()->owner(), 403);
        $data = $request->validate(['provider' => ['required', Rule::in(CourierAreaMapping::providers())]]);
        $config = $settings->provider('couriers', $data['provider']);
        if (! ($config['enabled'] ?? false) || blank($config['api_key'] ?? null)) {
            throw ValidationException::withMessages(['provider' => __('courier_areas.err_keys_first', ['courier' => CourierOptions::label($data['provider'])])]);
        }
        Audit::record($request->user()->id, 'courier_area.fetch_started', $data['provider']);
        FetchCourierAreas::dispatch($data['provider'], (int) $request->user()->id, now()->toDateTimeString());

        return back()->with('success', __('courier_areas.flash_fetch_started'));
    }

    public function save(Request $request, CourierAreaMapping $areas): RedirectResponse
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in(CourierAreaMapping::providers())],
            'place_code_id' => 'required|integer|exists:place_codes,id',
            ...collect(['city', 'zone', 'area'])->flatMap(fn (string $level): array => [
                $level.'_id' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
                $level.'_name' => 'nullable|string|max:150',
            ])->all(),
        ]);
        $areas->save($request->user(), $data);

        return back()->with('success', __('courier_areas.flash_saved'));
    }

    public function remove(Request $request, CourierAreaMapping $areas): RedirectResponse
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in(CourierAreaMapping::providers())],
            'place_code_id' => 'required|integer|exists:place_codes,id',
        ]);
        $areas->remove($request->user(), $data['provider'], (int) $data['place_code_id']);

        return back()->with('success', __('courier_areas.flash_removed'));
    }

    /**
     * @return array<string, mixed>
     */
    private function providerRow(string $key, IntegrationSettings $settings): array
    {
        $config = $settings->provider('couriers', $key);

        return [
            'key' => $key,
            'label' => CourierOptions::label($key),
            'levels' => CourierAreaMapping::Levels[$key],
            'required' => CourierAreaMapping::Required[$key],
            'can_fetch' => ($config['enabled'] ?? false) && filled($config['api_key'] ?? null),
            'option_count' => DB::table('courier_area_options')->where('provider', $key)->count(),
            'fetched_at' => DB::table('courier_area_options')->where('provider', $key)->max('fetched_at'),
        ];
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function optionRows(string $provider, string $level, ?string $parent): array
    {
        return DB::table('courier_area_options')->where('provider', $provider)->where('level', $level)
            ->when($parent !== null, fn ($query) => $query->where('parent_external_id', $parent))
            ->orderBy('name')->limit(5000)
            ->get(['external_id', 'name'])
            ->map(fn (object $row): array => ['id' => (string) $row->external_id, 'name' => (string) $row->name])
            ->all();
    }

    /**
     * The place whose mapping applies here: this one, or the nearest one above it.
     *
     * @param  Collection<int|string, mixed>  $parents
     * @param  Collection<int|string, mixed>  $mappings
     */
    private function coveringPlace(int $placeId, $parents, $mappings): ?int
    {
        $cursor = $placeId;
        for ($depth = 0; $cursor !== 0 && $depth < 8; $depth++) {
            if ($mappings->has($cursor)) {
                return $cursor;
            }
            $cursor = (int) ($parents[$cursor] ?? 0);
        }

        return null;
    }
}
