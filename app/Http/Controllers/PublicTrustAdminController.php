<?php

namespace App\Http\Controllers;

use App\Domain\Content\PublicTrustContent;
use App\Models\HealthArticle;
use App\Models\QualityDocument;
use App\Support\Audit;
use App\Support\SiteCustomization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Lets website staff edit the public trust pages, upload quality documents and write health articles.
 */
class PublicTrustAdminController extends Controller
{
    public function saveText(Request $request): RedirectResponse
    {
        abort_unless($request->user()->allows('content'), 403);

        $allowedKeys = array_keys(PublicTrustContent::defaults());
        $rules = ['values' => 'required|array'];
        foreach ($allowedKeys as $key) {
            $rules['values.'.$this->escapeRuleKey($key)] = 'nullable|string|max:'.(str_ends_with(preg_replace('/_bn$/', '', $key), '_body') ? 20000 : 2000);
        }
        $validated = $request->validate($rules);

        SiteCustomization::save(Arr::only($validated['values'], $allowedKeys));
        Audit::record($request->user()->id, 'public_trust.text_updated', 'frontend');

        return back()->with('success', __('public_trust.admin_saved'));
    }

    public function storeDocument(Request $request): RedirectResponse
    {
        abort_unless($request->user()->allows('content'), 403);

        $data = $request->validate([
            'title' => 'required|string|max:200',
            'document' => 'required|file|mimes:pdf,jpg,jpeg,png,webp|max:10240',
        ]);

        /** @var UploadedFile $file */
        $file = $data['document'];
        $extension = strtolower((string) ($file->guessExtension() ?: $file->getClientOriginalExtension()));
        $path = 'quality-documents/'.Str::uuid()->toString().'.'.$extension;
        Storage::disk('local')->put($path, (string) file_get_contents($file->getRealPath()));

        $document = QualityDocument::query()->create([
            'title' => $data['title'],
            'file_path' => $path,
            'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 200),
            'mime_type' => (string) $file->getMimeType(),
            'size_bytes' => (int) $file->getSize(),
            'is_published' => true,
            'created_by' => $request->user()->id,
        ]);
        Audit::record($request->user()->id, 'public_trust.document_added', (string) $document->id);

        return back()->with('success', __('public_trust.admin_saved'));
    }

    public function updateDocument(Request $request, QualityDocument $document): RedirectResponse
    {
        abort_unless($request->user()->allows('content'), 403);

        $data = $request->validate([
            'title' => 'required|string|max:200',
            'is_published' => 'required|boolean',
        ]);
        $document->update($data);
        Audit::record($request->user()->id, 'public_trust.document_updated', (string) $document->id);

        return back()->with('success', __('public_trust.admin_saved'));
    }

    public function destroyDocument(Request $request, QualityDocument $document): RedirectResponse
    {
        abort_unless($request->user()->allows('content'), 403);

        Storage::disk('local')->delete($document->file_path);
        $documentId = (string) $document->id;
        $document->delete();
        Audit::record($request->user()->id, 'public_trust.document_deleted', $documentId);

        return back()->with('success', __('public_trust.admin_deleted'));
    }

    public function storeArticle(Request $request): RedirectResponse
    {
        abort_unless($request->user()->allows('content'), 403);

        $data = $this->validateArticle($request);
        $article = HealthArticle::query()->create([
            'title' => $data['title'],
            'body' => $data['body'],
            'image_url' => isset($data['image']) ? $this->storeArticleImage($data['image']) : null,
            'published_at' => $data['published'] ? now() : null,
            'created_by' => $request->user()->id,
        ]);
        Audit::record($request->user()->id, 'public_trust.article_saved', (string) $article->id);

        return back()->with('success', __('public_trust.admin_saved'));
    }

    public function updateArticle(Request $request, HealthArticle $article): RedirectResponse
    {
        abort_unless($request->user()->allows('content'), 403);

        $data = $this->validateArticle($request);
        $article->update([
            'title' => $data['title'],
            'body' => $data['body'],
            'image_url' => isset($data['image']) ? $this->storeArticleImage($data['image']) : $article->image_url,
            'published_at' => $data['published'] ? ($article->published_at ?? now()) : null,
        ]);
        Audit::record($request->user()->id, 'public_trust.article_saved', (string) $article->id);

        return back()->with('success', __('public_trust.admin_saved'));
    }

    public function destroyArticle(Request $request, HealthArticle $article): RedirectResponse
    {
        abort_unless($request->user()->allows('content'), 403);

        $articleId = (string) $article->id;
        $article->delete();
        Audit::record($request->user()->id, 'public_trust.article_deleted', $articleId);

        return back()->with('success', __('public_trust.admin_deleted'));
    }

    /** @return array{title: string, body: string, published: bool, image?: UploadedFile} */
    private function validateArticle(Request $request): array
    {
        return $request->validate([
            'title' => 'required|string|max:200',
            'body' => 'required|string|max:20000',
            'published' => 'required|boolean',
            'image' => 'nullable|file|image|mimes:jpg,jpeg,png,webp|max:2048|dimensions:max_width=4096,max_height=4096',
        ]);
    }

    /**
     * Article photos go with the other website photos, in the folder that survives a deploy.
     */
    private function storeArticleImage(UploadedFile $file): string
    {
        $extension = strtolower((string) ($file->guessExtension() ?: 'png'));
        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $extension = 'png';
        }

        $directory = public_path('branding/site-photos');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $filename = 'article-'.Str::uuid()->toString().'.'.$extension;
        $file->move($directory, $filename);

        return '/branding/site-photos/'.$filename;
    }

    /** Dots in a settings key would be read as nesting by the validator; hyphens and underscores are fine. */
    private function escapeRuleKey(string $key): string
    {
        return str_replace('.', '\.', $key);
    }
}
