<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Content\TestimonialLabels;
use App\Models\SiteFeedback;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Messages from people: public feedback, a partner's own feedback, and contact enquiries.
 */
final class FeedbackSection implements WorkspaceSection
{
    /** Statuses the enquiry list can be filtered by. */
    public const EnquiryStatuses = ['new', 'contacted', 'closed'];

    public function data(SectionContext $context, array $data): array
    {
        return match ($context->section) {
            'feedback' => $this->feedback(),
            'share-feedback' => $this->ownFeedback($context->user),
            'enquiries' => $this->enquiries($context->request),
            default => [],
        };
    }

    /**
     * Public feedback for moderation, pending first, with counts by status.
     *
     * @return array<string, mixed>
     */
    private function feedback(): array
    {
        $props = [];
        $props['feedback_items'] = SiteFeedback::query()
            ->with('product:id,name')
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (SiteFeedback $row): array => [
                'id' => $row->id,
                'name' => $row->name,
                'role' => TestimonialLabels::role($row->role, $row->source),
                'location' => TestimonialLabels::location($row->location),
                'rating' => $row->rating,
                'message' => $row->message,
                'source' => $row->source,
                'product_name' => $row->product?->name,
                'status' => $row->status,
                'created_at' => optional($row->created_at)?->toIso8601String(),
                'moderated_at' => optional($row->moderated_at)?->toIso8601String(),
            ])
            ->all();
        $props['feedback_counts'] = [
            'total' => (int) SiteFeedback::query()->count(),
            'pending' => (int) SiteFeedback::query()->where('status', 'pending')->count(),
            'approved' => (int) SiteFeedback::query()->where('status', 'approved')->count(),
            'rejected' => (int) SiteFeedback::query()->where('status', 'rejected')->count(),
        ];

        return $props;
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
     * Contact enquiries with counts by status.
     *
     * @return array<string, mixed>
     */
    private function enquiries(Request $request): array
    {
        $props = [];
        $search = trim($request->string('q')->toString());
        $status = $request->string('status')->toString();
        $props['enquiries'] = DB::table('enquiries')->select(['id', 'name', 'phone', 'topic', 'message', 'status', 'created_at', 'updated_at'])
            ->when($search !== '', function ($query) use ($search): void {
                $term = '%'.addcslashes($search, '%_\\').'%';
                $query->where(fn ($match) => $match->where('name', 'ilike', $term)
                    ->orWhere('phone', 'ilike', $term)
                    ->orWhere('topic', 'ilike', $term)
                    ->orWhere('message', 'ilike', $term));
            })
            ->when(in_array($status, self::EnquiryStatuses, true), fn ($query) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();
        $props['enquiry_counts'] = [
            'total' => (int) DB::table('enquiries')->count(),
            'new' => (int) DB::table('enquiries')->where('status', 'new')->count(),
            'contacted' => (int) DB::table('enquiries')->where('status', 'contacted')->count(),
            'closed' => (int) DB::table('enquiries')->where('status', 'closed')->count(),
        ];

        return $props;
    }
}
