<?php

namespace App\Http\Controllers;

use App\Domain\Accounts\DealerVerification;
use App\Domain\Content\GrievanceInbox;
use App\Domain\Content\PublicTrustContent;
use App\Models\HealthArticle;
use App\Models\QualityDocument;
use App\Support\SiteCustomization;
use App\Support\SocialShareMeta;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Public pages that help a visitor trust the company: check a dealer, read the policies,
 * see the quality documents and health articles, and raise a complaint.
 */
class PublicTrustController extends Controller
{
    public function verifyDealer(Request $request, DealerVerification $verification): Response
    {
        abort_unless(PublicTrustContent::isVisible('verify-dealer'), 404);

        $submitted = $request->query('code');
        $code = is_string($submitted) ? mb_substr(trim($submitted), 0, 40) : '';
        $searched = $code !== '';

        return response()->view('reference.trust.verify-dealer', [
            'code' => $code,
            'searched' => $searched,
            'result' => $searched ? $verification->lookup($code) : null,
            'social' => SocialShareMeta::page('verify-dealer', $this->pageTitle('verify_title'), PublicTrustContent::text('verify_lead')),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function textPage(string $trustPage): View
    {
        abort_unless(PublicTrustContent::isVisible($trustPage), 404);
        $key = PublicTrustContent::TextPages[$trustPage];

        return view('reference.trust.text-page', [
            'page' => $trustPage,
            'title' => PublicTrustContent::text($key.'_title'),
            'lead' => PublicTrustContent::text($key.'_lead'),
            'body' => PublicTrustContent::text($key.'_body'),
            'social' => SocialShareMeta::page($trustPage, $this->pageTitle($key.'_title'), PublicTrustContent::text($key.'_lead')),
        ]);
    }

    public function grievance(Request $request, GrievanceInbox $inbox): Response
    {
        abort_unless(PublicTrustContent::isVisible('grievance'), 404);

        $track = $request->query('track');
        $trackingNumber = is_string($track) ? mb_substr(trim($track), 0, 24) : '';

        return response()->view('reference.trust.grievance', [
            'trackingNumber' => $trackingNumber,
            'trackedStatus' => $trackingNumber !== '' ? $inbox->statusOf($trackingNumber) : null,
            'social' => SocialShareMeta::page('grievance', $this->pageTitle('grievance_title'), PublicTrustContent::text('grievance_lead')),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function storeGrievance(Request $request, GrievanceInbox $inbox): RedirectResponse
    {
        abort_unless(PublicTrustContent::isVisible('grievance'), 404);

        $data = $request->validate([
            'name' => 'required|string|max:150',
            'phone' => 'required|string|max:30',
            'topic' => ['required', Rule::in(GrievanceInbox::Topics)],
            'product_name' => ['nullable', 'string', 'max:150', Rule::requiredIf($request->input('topic') === GrievanceInbox::TopicProductProblem)],
            'batch_number' => 'nullable|string|max:60',
            'message' => 'required|string|min:10|max:2000',
            'website' => 'nullable|max:0',
        ]);
        unset($data['website']);

        $trackingNumber = $inbox->submit($data);

        return redirect('/grievance')
            ->with('grievance_tracking', $trackingNumber)
            ->with('success', __('public_trust.grievance_received'));
    }

    public function qualityDocuments(): View
    {
        abort_unless(PublicTrustContent::isVisible('quality-documents'), 404);

        return view('reference.trust.quality-documents', [
            'documents' => QualityDocument::query()->published()->orderByDesc('created_at')->orderByDesc('id')->get(),
            'social' => SocialShareMeta::page('quality-documents', $this->pageTitle('quality_documents_title'), PublicTrustContent::text('quality_documents_lead')),
        ]);
    }

    public function qualityDocumentFile(QualityDocument $document): StreamedResponse
    {
        abort_unless(PublicTrustContent::isEnabled('quality-documents') && $document->is_published, 404);
        abort_unless(Storage::disk('local')->exists($document->file_path), 404);

        return Storage::disk('local')->response($document->file_path, $document->original_name, [
            'Content-Type' => $document->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=300',
        ], 'inline');
    }

    public function articles(): View
    {
        abort_unless(PublicTrustContent::isVisible('articles'), 404);

        return view('reference.trust.articles', [
            'articles' => HealthArticle::query()->published()->orderByDesc('published_at')->orderByDesc('id')->paginate(12),
            'social' => SocialShareMeta::page('articles', $this->pageTitle('articles_title'), PublicTrustContent::text('articles_lead')),
        ]);
    }

    public function article(HealthArticle $article): View
    {
        abort_unless(PublicTrustContent::isEnabled('articles') && $article->isPublished(), 404);

        return view('reference.trust.article', [
            'article' => $article,
            'social' => SocialShareMeta::page('articles/'.$article->id, $article->title.' · '.SiteCustomization::brandName(), $article->excerpt()),
        ]);
    }

    private function pageTitle(string $key): string
    {
        return PublicTrustContent::text($key).' · '.SiteCustomization::brandName();
    }
}
