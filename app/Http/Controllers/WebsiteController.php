<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveWebsiteRequest;
use App\Models\AuditEvent;
use App\Services\WebsiteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class WebsiteController extends WorkspaceController
{
    public function edit(Request $request, WebsiteService $website): Response
    {
        $this->admin($request);
        $website->initialize();
        $row = DB::table('website_settings')->find(1);

        return Inertia::render('WebsiteCustomize', ['draft' => $website->decode($row->draft), 'defaults' => $website->defaults(), 'revision' => $row->revision, 'publishedRevision' => $row->published_revision, 'scheduledAt' => $row->publish_at, 'versions' => DB::table('website_versions')->latest('id')->limit(50)->get(['id', 'summary', 'created_at']), 'media' => $this->mediaRows()]);
    }

    public function save(SaveWebsiteRequest $request, WebsiteService $website): JsonResponse
    {
        $website->initialize();

        return response()->json(['revision' => $website->save($request->validated('document'), $request->integer('revision'))]);
    }

    public function publish(Request $request, WebsiteService $website): RedirectResponse
    {
        $this->admin($request);
        $website->initialize();
        $request->validate(['revision' => 'required|integer', 'summary' => 'required|string|max:250', 'publish_at' => 'nullable|date|after:now']);
        $website->publish($request->integer('revision'), $request->string('summary')->toString(), $request->input('publish_at'));

        return back()->with('success', $request->filled('publish_at') ? 'Publication scheduled.' : 'Website published.');
    }

    public function restore(Request $request, int $version, WebsiteService $website): RedirectResponse
    {
        $this->admin($request);
        $request->validate(['revision' => 'required|integer']);
        $record = DB::table('website_versions')->find($version);
        abort_unless($record, 404);
        $website->save($website->decode($record->document), $request->integer('revision'));
        AuditEvent::record('website.version_restored', ['version' => $version]);

        return back()->with('success', 'Version restored to your draft. Preview it before publishing.');
    }

    public function cancelSchedule(Request $request): RedirectResponse
    {
        $this->admin($request);
        DB::table('website_settings')->where('id', 1)->update(['scheduled_document' => null, 'publish_at' => null]);
        AuditEvent::record('website.schedule_cancelled');

        return back()->with('success', 'Scheduled publication cancelled.');
    }

    public function preview(Request $request, WebsiteService $website): Response
    {
        $this->admin($request);
        $row = DB::table('website_settings')->find(1);
        abort_unless($row, 404);

        $request->validate(['page' => 'nullable|string|max:80']);

        return Inertia::render('WebsitePage', ['website' => $website->decode($row->draft), 'preview' => true, 'slug' => $request->query('page', '')]);
    }

    public function home(Request $request): Response|RedirectResponse
    {
        return $request->user() ? redirect('/dashboard') : Inertia::render('Welcome');
    }

    public function page(string $slug, WebsiteService $website): Response|RedirectResponse
    {
        $document = $website->published();
        $page = collect($document['pages'])->first(fn (array $page): bool => $page['slug'] === $slug && $page['visible']);
        if (! $page) {
            $redirect = collect($document['redirects'])->firstWhere('from', $slug);
            if ($redirect) {
                return redirect($redirect['to'], 301);
            }
            abort(404);
        }

        return Inertia::render('WebsitePage', ['slug' => $slug]);
    }

    public function upload(Request $request): JsonResponse
    {
        $this->admin($request);
        $request->validate(['file' => 'required|image|mimes:jpg,jpeg,png,webp|max:4096|dimensions:max_width=5000,max_height=5000', 'alt' => 'nullable|string|max:250', 'folder' => 'nullable|string|max:80']);
        $file = $request->file('file');
        $key = (string) Str::uuid();
        $path = $file->store('website-media', 'local');
        abort_unless($path, 500);
        try {
            DB::table('website_media')->insert(['key' => $key, 'path' => $path, 'name' => Str::limit(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), 150, ''), 'alt' => $request->input('alt') ?? '', 'folder' => $request->input('folder') ?: 'General', 'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'created_at' => now()]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
        AuditEvent::record('website.media_uploaded', ['key' => $key]);

        return response()->json(['media' => $this->mediaRows()], 201);
    }

    public function updateMedia(Request $request, string $key): JsonResponse
    {
        $this->admin($request);
        $validated = $request->validate(['alt' => 'nullable|string|max:250', 'folder' => 'required|string|max:80', 'name' => 'required|string|max:160']);
        abort_unless(DB::table('website_media')->where('key', $key)->exists(), 404);
        DB::table('website_media')->where('key', $key)->update(['alt' => $validated['alt'] ?? '', 'folder' => $validated['folder'], 'name' => $validated['name']]);

        return response()->json(['media' => $this->mediaRows()]);
    }

    public function image(string $key): BinaryFileResponse
    {
        $media = DB::table('website_media')->where('key', $key)->first();
        abort_unless($media && Storage::disk('local')->exists($media->path), 404);

        return response()->file(Storage::disk('local')->path($media->path), ['Content-Type' => $media->mime, 'Content-Disposition' => 'inline']);
    }

    public function sitemap(WebsiteService $website): \Illuminate\Http\Response
    {
        $document = $website->published();
        $urls = $document['seo']['index'] ? [url('/')] : [];
        foreach ($document['pages'] as $page) {
            if ($page['visible'] && $page['index']) {
                $urls[] = url('/pages/'.$page['slug']);
            }
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($urls as $url) {
            $xml .= '<url><loc>'.htmlspecialchars($url, ENT_XML1).'</loc></url>';
        }

        return response($xml.'</urlset>', 200, ['Content-Type' => 'application/xml']);
    }

    private function mediaRows(): Collection
    {
        return DB::table('website_media')->latest('id')->get(['key', 'name', 'alt', 'folder', 'size'])->map(fn (object $media): array => [...(array) $media, 'url' => '/website/media/'.$media->key]);
    }
}
