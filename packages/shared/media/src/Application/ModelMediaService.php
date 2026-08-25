<?php

declare(strict_types=1);

namespace Shared\Media\Application;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Shared\Media\Support\MediaModelDefinition;

final class ModelMediaService
{
    public function storeUpload(
        string $baseUrl,
        object $model,
        string $kind,
        UploadedFile $file,
        MediaModelDefinition $definition,
    ): string {
        $this->deleteExisting($model, $kind, $definition);

        $extension = strtolower(trim((string) ($file->getClientOriginalExtension() ?: 'png')));
        if (! in_array($extension, $definition->allowedExtensions, true)) {
            $extension = $definition->allowedExtensions[0] ?? 'png';
        }

        $path = $this->storagePath($model, $kind, $definition, $extension);
        Storage::disk('public')->putFileAs(
            dirname($path),
            $file,
            basename($path)
        );

        return $this->buildPublicUrl($baseUrl, $model, $kind, $definition);
    }

    public function buildPublicUrl(
        string $baseUrl,
        object $model,
        string $kind,
        MediaModelDefinition $definition,
        string|int|null $version = null,
    ): string {
        $resolvedVersion = $version
            ?? $this->resolveCurrentMediaVersion($model, $kind, $definition)
            ?? (string) time();

        return rtrim($baseUrl, '/').$this->buildPublicPath($model, $kind, $definition, $resolvedVersion);
    }

    public function normalizePublicUrl(
        string $baseUrl,
        object $model,
        string $kind,
        MediaModelDefinition $definition,
        ?string $rawUrl,
    ): ?string {
        $value = is_string($rawUrl) ? trim($rawUrl) : '';
        if ($value === '') {
            return null;
        }

        $path = parse_url($value, PHP_URL_PATH);
        if (! is_string($path) || trim($path) === '') {
            return $value;
        }

        $legacyPrefix = $this->normalizePrefix($definition->legacyPublicPathPrefix);
        $canonicalPrefix = $this->normalizePrefix($definition->canonicalPublicPathPrefix);
        $storageMatches = [];
        foreach ($definition->allowedExtensions as $extension) {
            $storageMatches[] = '/storage/'.$this->storagePath($model, $kind, $definition, $extension);
            $storageMatches[] = '/storage/'.$this->legacyStoragePath($model, $extension);
        }

        if (
            ! str_starts_with($path, $legacyPrefix)
            && ! str_starts_with($path, $canonicalPrefix)
            && ! in_array($path, $storageMatches, true)
        ) {
            return $value;
        }

        $version = $this->extractVersionFromUri($value)
            ?? $this->resolveCurrentMediaVersion($model, $kind, $definition);

        return $this->buildPublicUrl($baseUrl, $model, $kind, $definition, $version);
    }

    public function resolveMediaPathForBaseUrl(
        object $model,
        string $kind,
        MediaModelDefinition $definition,
        ?string $baseUrl = null,
    ): ?string {
        foreach ($definition->allowedExtensions as $extension) {
            $path = $this->storagePath($model, $kind, $definition, $extension);
            if (Storage::disk('public')->exists($path)) {
                return $path;
            }
        }

        foreach ($definition->allowedExtensions as $extension) {
            $legacyPath = $this->legacyStoragePath($model, $extension);
            if (Storage::disk('public')->exists($legacyPath)) {
                return $legacyPath;
            }
        }

        return null;
    }

    private function deleteExisting(object $model, string $kind, MediaModelDefinition $definition): void
    {
        $paths = [];
        foreach ($definition->allowedExtensions as $extension) {
            $paths[] = $this->storagePath($model, $kind, $definition, $extension);
            $paths[] = $this->legacyStoragePath($model, $extension);
        }

        foreach (array_unique($paths) as $path) {
            if (Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }
        }
    }

    private function buildPublicPath(
        object $model,
        string $kind,
        MediaModelDefinition $definition,
        string|int|null $version = null,
    ): string {
        return sprintf(
            '%s/%s/%s?v=%s',
            $this->normalizePrefix($definition->canonicalPublicPathPrefix),
            $this->resolveModelIdentifier($model),
            $kind,
            (string) ($version ?? time()),
        );
    }

    private function resolveCurrentMediaVersion(
        object $model,
        string $kind,
        MediaModelDefinition $definition,
    ): ?string {
        $path = $this->resolveMediaPathForBaseUrl($model, $kind, $definition);
        if ($path === null || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        $absolutePath = Storage::disk('public')->path($path);
        if (is_file($absolutePath)) {
            $fingerprint = @md5_file($absolutePath);
            if (is_string($fingerprint) && $fingerprint !== '') {
                return substr($fingerprint, 0, 16);
            }
        }

        return (string) Storage::disk('public')->lastModified($path);
    }

    private function extractVersionFromUri(string $value): ?string
    {
        $query = parse_url($value, PHP_URL_QUERY);
        if (! is_string($query) || trim($query) === '') {
            return null;
        }

        parse_str($query, $parameters);
        $version = $parameters['v'] ?? null;
        if (! is_scalar($version)) {
            return null;
        }

        $normalized = trim((string) $version);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizePrefix(string $prefix): string
    {
        return '/'.trim($prefix, '/');
    }

    private function resolveModelIdentifier(object $model): string
    {
        $identifier = data_get($model, '_id') ?? data_get($model, 'id') ?? null;
        if (is_scalar($identifier)) {
            $normalized = trim((string) $identifier);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        if (is_object($identifier) && method_exists($identifier, '__toString')) {
            $normalized = trim((string) $identifier);

            if ($normalized !== '') {
                return $normalized;
            }
        }

        return spl_object_hash($model);
    }

    private function resolveStorageScope(object $model): string
    {
        $slug = data_get($model, 'slug');
        if (is_string($slug) && trim($slug) !== '') {
            return strtolower(trim($slug));
        }

        return 'landlord';
    }

    private function storagePath(
        object $model,
        string $kind,
        MediaModelDefinition $definition,
        string $extension,
    ): string {
        return sprintf(
            '%s/%s/%s.%s',
            trim($definition->storageDirectory, '/'),
            $this->resolveStorageScope($model),
            $kind,
            $extension,
        );
    }

    private function legacyStoragePath(object $model, string $extension): string
    {
        return sprintf(
            'tenants/%s/public-web/default-image.%s',
            $this->resolveStorageScope($model),
            $extension,
        );
    }
}
