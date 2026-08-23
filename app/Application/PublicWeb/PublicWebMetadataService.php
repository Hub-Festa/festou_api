<?php

declare(strict_types=1);

namespace App\Application\PublicWeb;

use App\Application\AccountProfiles\AccountProfileTypeSetProvider;
use App\Application\Branding\BrandingManifestService;
use App\Application\Branding\BrandingPublicWebMediaService;
use App\Models\Landlord\Landlord;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\EventOccurrence;

class PublicWebMetadataService
{
    public function __construct(
        private readonly BrandingManifestService $brandingManifestService,
        private readonly AccountProfileTypeSetProvider $accountProfileTypeSetProvider,
        private readonly BrandingPublicWebMediaService $brandingPublicWebMediaService,
    ) {}

    /**
     * @return array<string, string>
     */
    public function defaultMetadata(?string $path = null): array
    {
        $tenant = Tenant::current();
        $landlord = $tenant === null ? Landlord::singleton() : null;
        $brandable = $tenant ?? $landlord;

        $siteName = trim((string) (
            $tenant?->name
            ?? $landlord?->name
            ?? config('app.name', 'Boilerplate')
        ));
        if ($siteName === '') {
            $siteName = 'Boilerplate';
        }

        $title = $siteName;
        $description = trim((string) (
            $tenant?->description
            ?? $landlord?->description
            ?? "Explore {$siteName}."
        ));
        if ($description === '') {
            $description = "Explore {$siteName}.";
        }

        return [
            'title' => $title,
            'description' => $description,
            'canonical_url' => $this->canonicalUrlForPath($path),
            'site_name' => $siteName,
            'type' => 'website',
            'image' => $this->resolveImageUrl([
                $brandable instanceof Tenant || $brandable instanceof Landlord
                    ? $this->resolvePublicWebImage($brandable)
                    : null,
            ]),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function accountProfileMetadata(string $slug, ?string $requestedPath = null): array
    {
        $canonicalPath = $requestedPath ?? '/parceiro/'.$slug;
        $metadata = $this->defaultMetadata($canonicalPath);
        $profile = AccountProfile::query()
            ->where('slug', $slug)
            ->first();

        if (! $profile instanceof AccountProfile) {
            return $metadata;
        }

        if ((bool) ($profile->getAttribute('is_active') ?? true) === false) {
            return $metadata;
        }

        $profileType = trim((string) ($profile->getAttribute('profile_type') ?? ''));
        if (! $this->accountProfileTypeSetProvider->isPubliclyNavigable($profileType)) {
            return $metadata;
        }

        $displayName = $this->firstNonEmpty(
            $this->sanitizeText($profile->getAttribute('display_name')),
            $this->slugToLabel($slug),
        );
        $metadata['title'] = $displayName.' | '.$metadata['site_name'];

        $metadata['description'] = $this->firstNonEmpty(
            $this->excerpt($this->sanitizeText($profile->getAttribute('description'))),
            $this->excerpt($this->sanitizeText($profile->getAttribute('bio'))),
            $this->excerpt($this->sanitizeText($profile->getAttribute('content'))),
            $metadata['description'],
        );
        $metadata['image'] = $this->resolveImageUrl([
            $profile->getAttribute('cover_url'),
            $profile->getAttribute('avatar_url'),
        ]);
        $metadata['canonical_url'] = $this->canonicalUrlForPath($canonicalPath);

        return $metadata;
    }

    /**
     * @return array<string, string>
     */
    public function eventMetadata(string $slug, ?string $requestedPath = null): array
    {
        $canonicalPath = $requestedPath ?? '/agenda/evento/'.$slug;
        $metadata = $this->defaultMetadata($canonicalPath);
        $event = EventOccurrence::query()
            ->where('slug', $slug)
            ->where('is_event_published', true)
            ->first();

        if (! $event instanceof EventOccurrence) {
            return $metadata;
        }

        $title = $this->firstNonEmpty(
            $this->sanitizeText($event->getAttribute('title')),
            $this->sanitizeText($event->getAttribute('name')),
            $this->sanitizeText($event->getAttribute('display_name')),
            $this->slugToLabel($slug),
        );
        $metadata['title'] = $title.' | '.$metadata['site_name'];

        $metadata['description'] = $this->firstNonEmpty(
            $this->excerpt($this->sanitizeText($event->getAttribute('description'))),
            $this->excerpt($this->sanitizeText($event->getAttribute('content'))),
            $this->excerpt($this->sanitizeText($event->getAttribute('summary'))),
            $this->excerpt($this->sanitizeText($event->getAttribute('excerpt'))),
            $metadata['description'],
        );
        $metadata['image'] = $this->resolveImageUrl([
            $event->getAttribute('cover_url'),
            $event->getAttribute('avatar_url'),
            $event->getAttribute('image_url'),
            $event->getAttribute('poster_url'),
            $event->getAttribute('banner_url'),
            $event->getAttribute('thumbnail_url'),
            $this->eventPartyImageCandidate($event),
        ]);
        $metadata['canonical_url'] = $this->canonicalUrlForPath($canonicalPath);

        return $metadata;
    }

    private function canonicalUrlForPath(?string $path = null): string
    {
        $base = request()->getSchemeAndHttpHost();
        $normalizedPath = $this->normalizeCanonicalPath($path ?? request()->getPathInfo() ?? '/');

        return $base.$normalizedPath;
    }

    private function normalizeCanonicalPath(?string $path): string
    {
        $candidate = trim((string) $path);
        if ($candidate === '') {
            return '/';
        }

        $parsedPath = parse_url($candidate, PHP_URL_PATH);
        if (is_string($parsedPath) && $parsedPath !== '') {
            $candidate = $parsedPath;
        }

        if (! str_starts_with($candidate, '/')) {
            $candidate = '/'.$candidate;
        }

        return $candidate === '' ? '/' : $candidate;
    }

    /**
     * @param  array<int, mixed>  $candidates
     */
    private function resolveImageUrl(array $candidates = []): string
    {
        $fallbacks = [
            $this->brandingManifestService->resolveLogoSetting('dark_logo_uri'),
            $this->brandingManifestService->resolveLogoSetting('light_logo_uri'),
            $this->brandingManifestService->resolvePwaIcon('icon512_uri'),
            '/logo-dark.png',
        ];

        foreach (array_merge($candidates, $fallbacks) as $candidate) {
            $normalized = trim((string) $candidate);
            if ($normalized !== '') {
                return $this->absoluteUrlForCandidate($normalized);
            }
        }

        return '/logo-dark.png';
    }

    private function resolvePublicWebImage(Tenant|Landlord $brandable): ?string
    {
        $branding = $brandable->branding_data ?? [];
        if (! is_array($branding)) {
            return null;
        }

        $defaultImage = trim((string) data_get($branding, 'public_web_metadata.default_image', ''));
        if ($defaultImage === '') {
            return null;
        }

        return $this->brandingPublicWebMediaService->normalizePublicUrl(
            request()->getSchemeAndHttpHost(),
            $brandable,
            $defaultImage,
        ) ?? $defaultImage;
    }

    private function absoluteUrlForCandidate(string $candidate): string
    {
        if (preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $candidate) === 1) {
            return $candidate;
        }

        if (str_starts_with($candidate, '/')) {
            return request()->getSchemeAndHttpHost().$candidate;
        }

        return request()->getSchemeAndHttpHost().'/'.$candidate;
    }

    private function sanitizeText(mixed $value): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return '';
        }

        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function excerpt(string $value, int $limit = 180): string
    {
        $text = trim($value);
        if ($text === '') {
            return '';
        }

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $snippet = mb_substr($text, 0, $limit);
        $breakAt = mb_strrpos($snippet, ' ');
        if ($breakAt !== false && $breakAt > 0) {
            $snippet = mb_substr($snippet, 0, $breakAt);
        }

        return rtrim($snippet).'...';
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private function firstNonEmpty(mixed ...$values): string
    {
        foreach ($values as $value) {
            $normalized = trim((string) $value);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }

    private function slugToLabel(string $slug): string
    {
        $label = trim(str_replace(['-', '_'], ' ', $slug));
        if ($label === '') {
            return 'Boilerplate';
        }

        return ucwords($label);
    }

    /**
     * @param  EventOccurrence  $event
     */
    private function eventPartyImageCandidate(EventOccurrence $event): mixed
    {
        $eventParties = $event->getAttribute('event_parties');
        if (! is_array($eventParties)) {
            return null;
        }

        foreach ($eventParties as $eventParty) {
            if (! is_array($eventParty)) {
                continue;
            }

            $metadata = $eventParty['metadata'] ?? null;
            if (! is_array($metadata)) {
                continue;
            }

            foreach (['cover_url', 'avatar_url', 'image_url', 'poster_url'] as $field) {
                $candidate = $metadata[$field] ?? null;
                if (is_string($candidate) && trim($candidate) !== '') {
                    return $candidate;
                }
            }
        }

        return null;
    }
}
