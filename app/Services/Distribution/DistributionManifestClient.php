<?php

namespace App\Services\Distribution;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

class DistributionManifestClient
{
    public const CACHE_KEY = 'coolify:distribution:manifest';

    public const CACHE_SECONDS = 604800;

    public const MAX_BYTES = 524288;

    public function fetch(BuildIdentity $identity): DistributionManifest
    {
        if (! $identity->isCustom() || ! $identity->isValid() || $identity->manifestUrl === null) {
            throw new InvalidDistributionManifest('This installation does not run a valid custom distribution.');
        }

        $response = Http::timeout(10)
            ->connectTimeout(5)
            ->retry(2, 500, throw: false)
            ->accept('application/json')
            ->withOptions(['allow_redirects' => ['max' => 3, 'protocols' => ['https'], 'strict' => true]])
            ->get($identity->manifestUrl);

        if (! $response->successful()) {
            throw new InvalidDistributionManifest("The distribution manifest could not be downloaded (HTTP {$response->status()}).");
        }

        $body = $response->body();
        if (strlen($body) > self::MAX_BYTES) {
            throw new InvalidDistributionManifest('The distribution manifest is larger than allowed.');
        }

        try {
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidDistributionManifest('The distribution manifest is not valid JSON.');
        }

        if (! is_array($data)) {
            throw new InvalidDistributionManifest('The distribution manifest is not a JSON object.');
        }

        $manifest = DistributionManifest::fromArray($data, $identity);
        Cache::put(self::CACHE_KEY, [
            'distribution' => $identity->distribution,
            'manifest' => $data,
            'checked_at' => now()->toIso8601String(),
            'error' => null,
        ], self::CACHE_SECONDS);

        return $manifest;
    }

    public function check(BuildIdentity $identity): DistributionCheck
    {
        try {
            $manifest = $this->fetch($identity);

            return new DistributionCheck($manifest, now()->toIso8601String(), null);
        } catch (Throwable $e) {
            $message = $e instanceof InvalidDistributionManifest ? $e->getMessage() : 'The distribution manifest could not be downloaded.';
            $previous = $this->lastCheck($identity);
            $cached = Cache::get(self::CACHE_KEY);
            Cache::put(self::CACHE_KEY, [
                'distribution' => $identity->distribution,
                'manifest' => $previous?->manifest !== null && is_array($cached) ? ($cached['manifest'] ?? null) : null,
                'checked_at' => now()->toIso8601String(),
                'error' => $message,
            ], self::CACHE_SECONDS);

            return new DistributionCheck($previous?->manifest, now()->toIso8601String(), $message, stale: $previous?->manifest !== null);
        }
    }

    public function lastCheck(BuildIdentity $identity): ?DistributionCheck
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (! is_array($cached) || ($cached['distribution'] ?? null) !== $identity->distribution) {
            return null;
        }

        $manifest = null;
        $error = is_string($cached['error'] ?? null) ? $cached['error'] : null;
        if (is_array($cached['manifest'] ?? null)) {
            try {
                $manifest = DistributionManifest::fromArray($cached['manifest'], $identity);
            } catch (InvalidDistributionManifest $e) {
                $error ??= $e->getMessage();
            }
        }

        return new DistributionCheck(
            manifest: $manifest,
            checkedAt: is_string($cached['checked_at'] ?? null) ? $cached['checked_at'] : null,
            error: $error,
            stale: $error !== null && $manifest !== null,
        );
    }
}
