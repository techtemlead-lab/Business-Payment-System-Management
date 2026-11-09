<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Content\PublicTrustContent;
use App\Models\HealthArticle;
use App\Models\QualityDocument;
use App\Support\SiteCustomization;
use Illuminate\Support\Facades\Lang;

/**
 * The owner's editor for the public trust pages, quality documents and health articles.
 */
final class PublicTrustSection implements WorkspaceSection
{
    public function data(SectionContext $context, array $data): array
    {
        if ($context->section !== 'public-trust') {
            return [];
        }

        return [
            'public_trust' => [
                ...$this->textEditorData(),
                'documents' => QualityDocument::query()->orderByDesc('created_at')->orderByDesc('id')->get()
                    ->map(fn (QualityDocument $document): array => [
                        'id' => $document->id,
                        'title' => $document->title,
                        'original_name' => $document->original_name,
                        'is_published' => $document->is_published,
                        'created_at' => $document->created_at?->toIso8601String(),
                    ])->all(),
                'articles' => HealthArticle::query()->orderByDesc('created_at')->orderByDesc('id')->limit(200)->get()
                    ->map(fn (HealthArticle $article): array => [
                        'id' => $article->id,
                        'title' => $article->title,
                        'body' => $article->body,
                        'title_bn' => $article->title_bn,
                        'body_bn' => $article->body_bn,
                        'image_url' => $article->image_url,
                        'published' => $article->isPublished(),
                        'published_at' => $article->published_at?->toIso8601String(),
                    ])->all(),
            ],
        ];
    }

    /**
     * Saved wording, built-in drafts and labels for the trust pages. The website
     * settings page uses this too, so every page has its boxes in one place.
     *
     * @return array<string, mixed>
     */
    public function textEditorData(): array
    {
        $all = SiteCustomization::all();
        $values = [];
        foreach (array_keys(PublicTrustContent::defaults()) as $key) {
            $values[$key] = (string) ($all[$key] ?? '');
        }

        return [
            'values' => $values,
            'text_keys' => PublicTrustContent::TextKeys,
            'identity_keys' => PublicTrustContent::IdentityKeys,
            'contact_keys' => PublicTrustContent::ContactKeys,
            'pages' => PublicTrustContent::Pages,
            'drafts' => [
                'en' => $this->drafts('en'),
                'bn' => $this->drafts('bn'),
            ],
            'labels' => $this->labels(),
        ];
    }

    /**
     * Built-in draft wording, so the editor can show it for the owner to change.
     *
     * @return array<string, string>
     */
    private function drafts(string $locale): array
    {
        $drafts = [];
        foreach (PublicTrustContent::TextKeys as $key) {
            $drafts[$key] = (string) trans('public_trust.'.$key, [], $locale);
        }

        return $drafts;
    }

    /**
     * Screen text from this feature's own language file.
     *
     * @return array<string, string>
     */
    private function labels(): array
    {
        $labels = [];
        foreach ((array) Lang::get('public_trust') as $key => $text) {
            if (is_string($text) && (str_starts_with($key, 'admin_') || str_starts_with($key, 'footer_') || str_starts_with($key, 'identity_'))) {
                $labels[$key] = $text;
            }
        }

        return $labels;
    }
}
