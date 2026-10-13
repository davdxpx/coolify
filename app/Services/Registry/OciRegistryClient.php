<?php

namespace App\Services\Registry;

use App\Services\Distribution\BuildIdentity;
use App\Services\Distribution\DistributionRelease;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class OciRegistryClient
{
    public const TAG_PATTERN = '/^[A-Za-z0-9_][A-Za-z0-9._-]{0,127}$/';

    private const ACCEPT = [
        'application/vnd.oci.image.index.v1+json',
        'application/vnd.docker.distribution.manifest.list.v2+json',
        'application/vnd.oci.image.manifest.v1+json',
        'application/vnd.docker.distribution.manifest.v2+json',
    ];

    public function manifestStatus(string $repository, string $reference): ManifestLookup
    {
        $location = self::parseRepository($repository);
        if ($location === null || (preg_match(DistributionRelease::DIGEST_PATTERN, $reference) !== 1 && preg_match(self::TAG_PATTERN, $reference) !== 1)) {
            return ManifestLookup::INVALID_REFERENCE;
        }

        [$host, $path] = $location;
        $url = "https://{$host}/v2/{$path}/manifests/{$reference}";

        try {
            $response = $this->head($url);
            if ($response->status() === 401) {
                $token = $this->anonymousToken($host, $path, (string) $response->header('WWW-Authenticate'));
                if ($token === null) {
                    return ManifestLookup::AUTH_REQUIRED;
                }
                $response = $this->head($url, $token);
            }
        } catch (Throwable) {
            return ManifestLookup::UNREACHABLE;
        }

        return match (true) {
            $response->successful() => ManifestLookup::FOUND,
            $response->status() === 404 => ManifestLookup::NOT_FOUND,
            in_array($response->status(), [401, 403], true) => ManifestLookup::AUTH_REQUIRED,
            default => ManifestLookup::UNREACHABLE,
        };
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    public static function parseRepository(string $repository): ?array
    {
        $repository = strtolower(trim($repository));
        if (preg_match(BuildIdentity::IMAGE_REPOSITORY_PATTERN, $repository) !== 1 && preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)*(?:\/[a-z0-9]+(?:[._-][a-z0-9]+)*)?$/', $repository) !== 1) {
            return null;
        }

        $parts = explode('/', $repository);
        $first = $parts[0];
        if (count($parts) > 1 && (str_contains($first, '.') || str_contains($first, ':') || $first === 'localhost')) {
            $host = $first;
            $path = implode('/', array_slice($parts, 1));
        } else {
            $host = 'docker.io';
            $path = count($parts) === 1 ? "library/{$repository}" : $repository;
        }

        if (in_array($host, ['docker.io', 'index.docker.io'], true)) {
            $host = 'registry-1.docker.io';
        }

        return [$host, $path];
    }

    private function head(string $url, ?string $token = null): Response
    {
        $request = Http::timeout(10)
            ->connectTimeout(5)
            ->withHeaders(['Accept' => implode(', ', self::ACCEPT)])
            ->withOptions(['allow_redirects' => ['max' => 2, 'protocols' => ['https'], 'strict' => true]]);

        if ($token !== null) {
            $request = $request->withToken($token);
        }

        return $request->head($url);
    }

    private function anonymousToken(string $host, string $path, string $challenge): ?string
    {
        if (! preg_match('/^Bearer\s+(.+)$/i', trim($challenge), $matches)) {
            return null;
        }

        preg_match_all('/(\w+)="([^"]*)"/', $matches[1], $pairs, PREG_SET_ORDER);
        $params = [];
        foreach ($pairs as $pair) {
            $params[strtolower($pair[1])] = $pair[2];
        }

        $realm = $params['realm'] ?? null;
        $realmHost = is_string($realm) ? parse_url($realm, PHP_URL_HOST) : null;
        $trustedRealmHosts = $host === 'registry-1.docker.io' ? ['auth.docker.io'] : [$host];
        if (! is_string($realm) || parse_url($realm, PHP_URL_SCHEME) !== 'https' || ! in_array($realmHost, $trustedRealmHosts, true)) {
            return null;
        }

        $response = Http::timeout(10)->connectTimeout(5)->get($realm, array_filter([
            'service' => $params['service'] ?? null,
            'scope' => "repository:{$path}:pull",
        ]));

        if (! $response->successful()) {
            return null;
        }

        $token = $response->json('token') ?? $response->json('access_token');

        return is_string($token) && $token !== '' ? $token : null;
    }
}
