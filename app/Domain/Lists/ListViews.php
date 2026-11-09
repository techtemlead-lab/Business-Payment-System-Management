<?php

namespace App\Domain\Lists;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Each office worker's own saved filters and hidden columns on the main lists.
 * Nothing here changes what a person may see: a saved filter only fills in the
 * same filter bar, and the list still applies their permissions and branch.
 */
final class ListViews
{
    /** @var list<string> */
    public const Lists = ['orders', 'deliveries', 'accounts', 'sub-dealers'];

    public const MaxSavedPerList = 20;

    private const OrderFilters = ['q', 'status', 'period', 'from', 'to', 'buyer_role', 'sort', 'dir'];

    private const PartnerFilters = ['q', 'status', 'division', 'district', 'rank', 'no_order_days', 'sort', 'dir'];

    public static function assertMayUse(User $user, string $list): void
    {
        abort_unless($user->staff() || $user->usingStaffWorkspace(), 403);
        abort_unless(in_array($list, ['orders', 'deliveries'], true)
            ? $user->allows('orders') || $user->allows('finance')
            : $user->owner() || $user->allows('accounts'), 403);
    }

    /**
     * @return array{views: list<array{id: int, name: string, filters: array<string, string>}>, hidden: list<string>}
     */
    public function forUser(User $user, string $list): array
    {
        $views = DB::table('saved_list_filters')->where('user_id', $user->id)->where('list_key', $list)->orderBy('name')->get();
        $hidden = DB::table('list_column_choices')->where('user_id', $user->id)->where('list_key', $list)->value('hidden_columns');

        return [
            'views' => $views->map(fn (\stdClass $view): array => [
                'id' => (int) $view->id,
                'name' => (string) $view->name,
                'filters' => (array) json_decode((string) $view->filters, true, flags: JSON_THROW_ON_ERROR),
            ])->all(),
            'hidden' => $hidden === null ? [] : array_values((array) json_decode((string) $hidden, true, flags: JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * Saving under a name already used on this list replaces that saved filter.
     *
     * @param  array<string, mixed>  $filters
     */
    public function save(User $user, string $list, string $name, array $filters): void
    {
        $clean = $this->allowedFilters($list, $filters);
        if ($clean === []) {
            throw ValidationException::withMessages(['filters' => __('productivity.views_nothing_to_save')]);
        }
        $name = trim($name);
        $exists = DB::table('saved_list_filters')->where('user_id', $user->id)->where('list_key', $list)->where('name', $name)->exists();
        if (! $exists && DB::table('saved_list_filters')->where('user_id', $user->id)->where('list_key', $list)->count() >= self::MaxSavedPerList) {
            throw ValidationException::withMessages(['name' => __('productivity.views_too_many', ['count' => self::MaxSavedPerList])]);
        }
        DB::table('saved_list_filters')->updateOrInsert(
            ['user_id' => $user->id, 'list_key' => $list, 'name' => $name],
            ['filters' => json_encode($clean, JSON_THROW_ON_ERROR), 'updated_at' => now(), 'created_at' => now()],
        );
    }

    /**
     * Only the person's own filter on this list can be renamed. Unlike saving, a rename never
     * replaces another saved filter, because nobody chose to lose that one.
     */
    public function rename(User $user, string $list, int $viewId, string $name): void
    {
        $name = trim($name);
        $own = DB::table('saved_list_filters')->where('user_id', $user->id)->where('list_key', $list);
        abort_unless((clone $own)->where('id', $viewId)->exists(), 404);
        $nameTaken = ValidationException::withMessages(['name' => __('productivity.views_name_taken')]);
        if ((clone $own)->where('name', $name)->where('id', '!=', $viewId)->exists()) {
            throw $nameTaken;
        }
        try {
            (clone $own)->where('id', $viewId)->update(['name' => $name, 'updated_at' => now()]);
        } catch (UniqueConstraintViolationException) {
            throw $nameTaken;
        }
    }

    /** Only the owner of a saved filter can delete it; anyone else's id simply matches nothing. */
    public function delete(User $user, string $list, int $viewId): void
    {
        DB::table('saved_list_filters')->where('id', $viewId)->where('user_id', $user->id)->where('list_key', $list)->delete();
    }

    /**
     * @param  list<string>  $hidden
     */
    public function saveHiddenColumns(User $user, string $list, array $hidden): void
    {
        DB::table('list_column_choices')->updateOrInsert(
            ['user_id' => $user->id, 'list_key' => $list],
            ['hidden_columns' => json_encode(array_values(array_unique($hidden)), JSON_THROW_ON_ERROR), 'updated_at' => now(), 'created_at' => now()],
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, string>
     */
    private function allowedFilters(string $list, array $filters): array
    {
        $allowed = in_array($list, ['orders', 'deliveries'], true) ? self::OrderFilters : self::PartnerFilters;
        $clean = [];
        foreach ($allowed as $key) {
            $value = $filters[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                $clean[$key] = mb_substr(trim((string) $value), 0, 120);
            }
        }

        return $clean;
    }
}
