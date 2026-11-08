<?php

namespace App\Http\Controllers;

use App\Domain\Content\TestimonialLabels;
use App\Models\Product;
use App\Models\SiteFeedback;
use App\Models\User;
use App\Support\Audit;
use App\Support\SiteCustomization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A visitor's review of one product. It waits as site feedback until the owner
 * approves it, through the same moderation screen as all other feedback.
 */
class ProductReviewController extends Controller
{
    public function store(Request $request, Product $product): RedirectResponse
    {
        abort_unless(SiteCustomization::isPageEnabled('products'), 404);
        abort_unless($product->active, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150', TestimonialLabels::NoHtmlRule],
            'location' => ['nullable', 'string', 'max:120', TestimonialLabels::NoHtmlRule],
            'rating' => 'required|integer|min:1|max:5',
            'message' => ['required', 'string', 'min:20', 'max:2000', TestimonialLabels::NoHtmlRule],
            'website' => 'nullable|max:0',
        ], [
            '*.not_regex' => __('site.feedback_no_html'),
        ]);

        $user = $request->user();
        $source = 'public';
        if ($user instanceof User && in_array($user->role, ['dealer', 'sub_dealer'], true)) {
            $source = $user->role === 'sub_dealer' ? 'sub_dealer' : 'dealer';
        }

        $review = SiteFeedback::query()->create([
            'product_id' => $product->id,
            'name' => $data['name'],
            'role' => '',
            'location' => trim((string) ($data['location'] ?? '')),
            'rating' => (int) $data['rating'],
            'message' => $data['message'],
            'source' => $source,
            'user_id' => $user?->id,
            'status' => 'pending',
        ]);

        Audit::notifyAdminsAndStaff(
            ['content'],
            __('product_facts.notice_review_title'),
            __('product_facts.notice_review_body', [
                'name' => $review->name,
                'product' => $product->name,
            ])
        );

        return redirect()->to(route('products.show', $product, false).'#product-reviews')
            ->with('product_review_received', true);
    }
}
