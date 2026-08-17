<?php

namespace App\Support\Marketing;

use App\Models\LandingPage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class LandingPageService
{
    /**
     * Create a campaign page from validated input.
     *
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): LandingPage
    {
        $input = $this->validate($input);

        $page = LandingPage::create([
            'slug' => $input['slug'],
            'type' => $input['type'],
            'status' => $input['status'],
            'content_en' => $input['content_en'],
            'content_fr' => $input['content_fr'],
            'published_at' => $input['status'] === LandingPage::STATUS_PUBLISHED ? now() : null,
        ]);

        return $page;
    }

    /**
     * Update a campaign page, re-validating the mutable fields.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(LandingPage $page, array $input): LandingPage
    {
        $input = $this->validate($input, $page);

        $page->update([
            'slug' => $input['slug'],
            'type' => $input['type'],
            'status' => $input['status'],
            'content_en' => $input['content_en'],
            'content_fr' => $input['content_fr'],
            // Republish (or clear) the stamp whenever the status changes.
            'published_at' => $input['status'] === LandingPage::STATUS_PUBLISHED
                ? ($page->status === LandingPage::STATUS_PUBLISHED ? $page->published_at : now())
                : null,
        ]);

        return $page;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validate(array $input, ?LandingPage $ignore = null): array
    {
        $rules = [
            'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:landing_pages,slug'],
            'type' => ['required', 'string', 'in:'.implode(',', LandingPage::TYPES)],
            'status' => ['required', 'string', 'in:'.implode(',', LandingPage::STATUSES)],
            'content_en' => ['required', 'array', 'min:1'],
            'content_fr' => ['required', 'array', 'min:1'],
        ];

        if ($ignore !== null) {
            // Allow keeping the same slug on update.
            $rules['slug'][4] = 'unique:landing_pages,slug,'.$ignore->id;
        }

        $validated = validator($input, $rules)->validate();

        foreach (['content_en', 'content_fr'] as $locale) {
            $this->validateSections($validated[$locale], $locale);
        }

        return $validated;
    }

    /**
     * Lightweight structural check on the section list: every section must
     * carry a known `kind` and required base fields. Unknown sections are
     * rejected so a typo cannot silently break the public page.
     *
     * @param  array<int, array<string, mixed>>  $sections
     */
    private function validateSections(array $sections, string $locale): void
    {
        $knownKinds = ['hero', 'offer', 'promo', 'comparison', 'features', 'cta', 'faq'];
        $requiredByKind = [
            'hero' => ['title', 'subtitle', 'cta_label'],
            'offer' => ['title', 'description', 'price', 'features', 'cta_label'],
            'promo' => ['title', 'code', 'discount', 'description', 'cta_label'],
            'comparison' => ['title', 'columns', 'rows'],
            'features' => ['title', 'items'],
            'cta' => ['title', 'label'],
            'faq' => ['title', 'items'],
        ];

        foreach ($sections as $index => $section) {
            $kind = $section['kind'] ?? null;
            if (! in_array($kind, $knownKinds, true)) {
                throw ValidationException::withMessages([
                    "{$locale}.{$index}.kind" => ["Unsupported section kind '{$kind}'."],
                ]);
            }

            $missing = array_values(array_diff($requiredByKind[$kind], array_keys($section)));
            if ($missing !== []) {
                throw ValidationException::withMessages([
                    "{$locale}.{$index}" => [
                        'Missing required fields: '.implode(', ', $missing),
                    ],
                ]);
            }
        }
    }

    /** Best-effort slug suggestion for the editor ("20% off — launch" → "20-off-launch"). */
    public function suggestSlug(string $name): string
    {
        $slug = Str::slug($name);
        if ($slug === '') {
            $slug = 'campaign-'.Str::lower(Str::random(6));
        }

        return Str::limit($slug, 120, '');
    }
}
