<?php

namespace App\Domain\Diagnostics;

use App\Models\User;
use App\Support\Audit;
use App\Support\DatabaseConnectionFailure;
use App\Support\PortalHost;
use App\Support\RedactedError;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The site's own error log. One row for each kind of problem (exception class, file and line),
 * with how often and when it was seen, the portal, the route name, the HTTP status and the
 * signed-in user's number. It never keeps what was sent to the site: no form fields, passwords,
 * tokens, cookies, headers or web addresses with their query strings.
 */
final class ErrorTracker
{
    public const Sorts = ['last_seen', 'first_seen', 'count'];

    public const Statuses = ['open', 'fixed', 'all'];

    /** The same problem touches the database at most once in this many seconds. */
    public const RepeatSeconds = 30;

    /** A loop of different problems adds at most this many new rows a minute. */
    public const NewRowsPerMinute = 30;

    public const PerPage = 25;

    public function __construct(private ErrorTrackingSettings $settings) {}

    /**
     * Note an exception. Never throws and never waits on anything slow: a failure in here must
     * not make the request that already failed worse.
     */
    public function capture(Throwable $exception, ?Request $request = null): void
    {
        try {
            if (DatabaseConnectionFailure::matches($exception) || ! $this->settings->enabled()) {
                return;
            }
            $file = $this->relativePath($exception->getFile());
            $fingerprint = sha1($exception::class.'|'.$file.'|'.$exception->getLine());
            // Cache::add is true only for the first caller in the window, so a loop writes once.
            if (! Cache::add('error-tracker:seen:'.$fingerprint, 1, self::RepeatSeconds)) {
                return;
            }
            $this->store($exception, $fingerprint, $file, $request);
        } catch (Throwable) {
            // The tracker is a helper; losing one report is better than hiding the real error.
        }
    }

    private function store(Throwable $exception, string $fingerprint, string $file, ?Request $request): void
    {
        $now = now();
        $existing = DB::table('error_reports')->where('fingerprint', $fingerprint)->first(['id']);
        $context = $this->context($exception, $request);
        if ($existing !== null) {
            // A problem marked fixed that comes back is open again.
            DB::table('error_reports')->where('id', $existing->id)->update([
                ...$context,
                'occurrences' => DB::raw('occurrences + 1'),
                'last_seen_at' => $now,
                'fixed_at' => null,
                'fixed_by' => null,
                'updated_at' => $now,
            ]);

            return;
        }
        $minute = 'error-tracker:new:'.$now->format('YmdHi');
        Cache::add($minute, 0, 120);
        if ((int) Cache::increment($minute) > self::NewRowsPerMinute) {
            return;
        }
        DB::table('error_reports')->insertOrIgnore([
            'fingerprint' => $fingerprint,
            'exception_class' => mb_substr($exception::class, 0, 200),
            'message' => $this->safeMessage($exception),
            'file' => mb_substr($file, 0, 300),
            'line' => $exception->getLine(),
            ...$context,
            'occurrences' => 1,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @return array{portal: ?string, route_name: ?string, http_status: int, user_id: ?int} */
    private function context(Throwable $exception, ?Request $request): array
    {
        $request ??= app()->bound('request') ? app('request') : null;
        $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
        $userId = null;
        if ($request !== null) {
            try {
                $userId = $request->user()?->getAuthIdentifier();
            } catch (Throwable) {
                $userId = null;
            }
        }

        return [
            'portal' => $request === null ? null : PortalHost::current($request),
            'route_name' => $request === null ? null : (mb_substr((string) $request->route()?->getName(), 0, 120) ?: null),
            'http_status' => $status,
            'user_id' => $userId === null ? null : (int) $userId,
        ];
    }

    /**
     * A short message with web address queries, phone numbers and emails masked. A database error
     * repeats the whole query with the values that were sent, so everything from "(Connection:" on is dropped.
     */
    private function safeMessage(Throwable $exception): string
    {
        $message = (string) preg_replace('/\s*\(Connection:.*$/s', '', $exception->getMessage());
        $message = (string) preg_replace('/[\w.+-]+@[\w-]+\.[\w.-]+/', '[email]', $message);

        return RedactedError::text($message);
    }

    private function relativePath(string $path): string
    {
        $base = str_replace('\\', '/', base_path()).'/';
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    /**
     * One page of problems for the owner's list.
     *
     * @return LengthAwarePaginator<int, object>
     */
    public function listing(?string $search, string $status, string $sort, int $page): LengthAwarePaginator
    {
        $query = DB::table('error_reports')->select([
            'id', 'exception_class', 'message', 'file', 'line', 'portal', 'route_name', 'http_status',
            'occurrences', 'first_seen_at', 'last_seen_at', 'fixed_at',
        ]);
        if ($status === 'open') {
            $query->whereNull('fixed_at');
        } elseif ($status === 'fixed') {
            $query->whereNotNull('fixed_at');
        }
        $needle = trim((string) $search);
        if ($needle !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($needle)).'%';
            $query->where(function ($q) use ($like): void {
                foreach (['exception_class', 'message', 'file', 'route_name'] as $column) {
                    $q->orWhereRaw('lower('.$column.') like ?', [$like]);
                }
            });
        }
        match ($sort) {
            'first_seen' => $query->orderByDesc('first_seen_at'),
            'count' => $query->orderByDesc('occurrences')->orderByDesc('last_seen_at'),
            default => $query->orderByDesc('last_seen_at'),
        };

        return $query->orderByDesc('id')->paginate(self::PerPage, ['*'], 'page', $page);
    }

    /** @return array{open: int, fixed: int, week: int} */
    public function counts(): array
    {
        return [
            'open' => DB::table('error_reports')->whereNull('fixed_at')->count(),
            'fixed' => DB::table('error_reports')->whereNotNull('fixed_at')->count(),
            'week' => DB::table('error_reports')->where('last_seen_at', '>=', now()->subDays(7))->count(),
        ];
    }

    public function find(int $id): ?object
    {
        $row = DB::table('error_reports')->where('id', $id)->first();
        if ($row === null) {
            return null;
        }
        $row->user_name = $row->user_id === null ? null : DB::table('users')->where('id', $row->user_id)->value('name');
        $row->fixed_by_name = $row->fixed_by === null ? null : DB::table('users')->where('id', $row->fixed_by)->value('name');

        return $row;
    }

    public function markFixed(User $actor, int $id): void
    {
        abort_unless($actor->owner(), 403);
        DB::transaction(function () use ($actor, $id): void {
            $row = DB::table('error_reports')->where('id', $id)->lockForUpdate()->first();
            abort_if($row === null, 404);
            if ($row->fixed_at !== null) {
                return;
            }
            DB::table('error_reports')->where('id', $id)->update(['fixed_at' => now(), 'fixed_by' => $actor->id, 'updated_at' => now()]);
            Audit::record($actor->id, 'errors.marked_fixed', 'error_report:'.$id, [
                'exception_class' => $row->exception_class,
                'file' => $row->file,
                'line' => $row->line,
            ]);
        });
    }

    /** Delete problems not seen for the number of days the owner chose. Returns how many went. */
    public function prune(): int
    {
        return DB::table('error_reports')
            ->where('last_seen_at', '<', now()->subDays($this->settings->retentionDays()))
            ->delete();
    }
}
