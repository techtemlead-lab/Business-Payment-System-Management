<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Content\TestimonialLabels;
use App\Models\SiteFeedback;
use App\Models\User;
use App\Support\MoneyTable;
use App\Support\MoneyTableFilter;
use App\Support\MoneyTableSpec;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Messages from people: public feedback, a partner's own feedback, and contact enquiries.
 */
final class FeedbackSection implements WorkspaceSection
{
    /** Statuses the enquiry list can be filtered by. */
    public const EnquiryStatuses = ['new', 'contacted', 'closed'];

    /** Statuses the feedback list can be filtered by. */
    private const FeedbackStatuses = ['pending', 'approved', 'rejected'];

    /** Waiting feedback first, then approved, then rejected. */
    private const FeedbackQueueOrder = "CASE site_feedback.status WHEN 'pending' THEN 2 WHEN 'approved' THEN 1 ELSE 0 END";

    public function data(SectionContext $context, array $data): array
    {
        return match ($context->section) {
            'feedback' => $this->feedback($context->request),
            'share-feedback' => $this->ownFeedback($context->user),
            'enquiries' => $this->enquiries($context->request),
            default => [],
        };
    }

    /**
     * Public feedback for moderation: one searchable list, waiting feedback first.
     *
     * @return array<string, mixed>
     */
    private function feedback(Request $request): array
    {
        $counts = $this->countsByStatus('site_feedback', self::FeedbackStatuses);
        $query = DB::table('site_feedback')
            ->leftJoin('products', 'products.id', '=', 'site_feedback.product_id')
            ->select(['site_feedback.id', 'site_feedback.name', 'site_feedback.role', 'site_feedback.location', 'site_feedback.rating', 'site_feedback.message', 'site_feedback.source', 'site_feedback.status', 'site_feedback.created_at', 'site_feedback.moderated_at', 'products.name as product_name']);
        $table = MoneyTable::make($request, $query, new MoneyTableSpec(
            dateColumn: null,
            search: ['site_feedback.name', 'site_feedback.location', 'site_feedback.message', 'products.name'],
            filters: [new MoneyTableFilter('status', (string) __('portal.enquiry_status_label'), 'site_feedback.status', $this->statusOptions('feedback_', self::FeedbackStatuses, $counts))],
            sorts: ['queue' => self::FeedbackQueueOrder, 'created_at' => 'site_feedback.created_at', 'name' => 'site_feedback.name', 'rating' => 'site_feedback.rating'],
            defaultSort: 'queue',
            tieBreaker: 'site_feedback.id',
            defaultPeriod: null,
        ));
        $table['rows']->through(fn (object $row): array => [
            'id' => (int) $row->id,
            'name' => $row->name,
            'role' => TestimonialLabels::role($row->role, $row->source),
            'location' => TestimonialLabels::location($row->location),
            'rating' => (int) $row->rating,
            'message' => $row->message,
            'source' => $row->source,
            'product_name' => $row->product_name,
            'status' => $row->status,
            'created_at' => $this->isoDate($row->created_at),
            'moderated_at' => $this->isoDate($row->moderated_at),
        ]);

        return [
            'feedback_table' => $table,
            'feedback_items' => $table['rows']->items(),
            'feedback_counts' => [...$counts, 'total' => array_sum($counts)],
        ];
    }

    /**
     * Feedback the signed-in partner has sent.
     *
     * @return array<string, mixed>
     */
    private function ownFeedback(User $user): array
    {
        return [
            'my_feedback' => SiteFeedback::query()
                ->where('user_id', $user->id)
                ->orderByDesc('created_at')
                ->limit(20)
                ->get()
                ->map(fn (SiteFeedback $row): array => [
                    'id' => $row->id,
                    'rating' => $row->rating,
                    'message' => $row->message,
                    'status' => $row->status,
                    'created_at' => optional($row->created_at)?->toIso8601String(),
                ])
                ->all(),
        ];
    }

    /**
     * Contact enquiries: one searchable list, newest first.
     *
     * @return array<string, mixed>
     */
    private function enquiries(Request $request): array
    {
        $counts = $this->countsByStatus('enquiries', self::EnquiryStatuses);
        $query = DB::table('enquiries')->select(['id', 'name', 'phone', 'topic', 'message', 'status', 'tracking_number', 'product_name', 'batch_number', 'created_at', 'updated_at']);
        $table = MoneyTable::make($request, $query, new MoneyTableSpec(
            dateColumn: null,
            search: ['name', 'phone', 'topic', 'message', 'tracking_number', 'batch_number'],
            filters: [new MoneyTableFilter('status', (string) __('portal.enquiry_status_label'), 'status', $this->statusOptions('enquiry_', self::EnquiryStatuses, $counts))],
            sorts: ['created_at' => 'created_at', 'name' => 'name', 'status' => 'status'],
            defaultSort: 'created_at',
            tieBreaker: 'id',
            defaultPeriod: null,
            perPage: 20,
        ));

        return [
            'enquiries_table' => $table,
            'enquiries' => $table['rows'],
            'enquiry_counts' => [...$counts, 'total' => array_sum($counts)],
        ];
    }

    /**
     * Filter choices for a status column, each with how many rows have it.
     *
     * @param  list<string>  $statuses
     * @param  array<string, int>  $counts
     * @return list<array{value: string, label: string, count: int}>
     */
    private function statusOptions(string $labelPrefix, array $statuses, array $counts): array
    {
        return array_map(
            fn (string $status): array => ['value' => $status, 'label' => (string) __('portal.'.$labelPrefix.$status), 'count' => $counts[$status]],
            $statuses,
        );
    }

    /**
     * How many rows have each status, in one read.
     *
     * @param  list<string>  $statuses
     * @return array<string, int>
     */
    private function countsByStatus(string $table, array $statuses): array
    {
        $found = DB::table($table)->selectRaw('status, COUNT(*) AS rows_count')->groupBy('status')->pluck('rows_count', 'status');

        return array_combine($statuses, array_map(fn (string $status): int => (int) ($found[$status] ?? 0), $statuses));
    }

    private function isoDate(?string $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value)->toIso8601String();
    }
}
