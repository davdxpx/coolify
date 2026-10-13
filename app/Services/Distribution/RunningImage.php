<?php

namespace App\Services\Distribution;

use JsonException;

final class RunningImage
{
    /**
     * @param  list<string>  $repoDigests
     */
    public function __construct(
        public readonly string $imageId,
        public readonly string $reference,
        public readonly array $repoDigests,
        public readonly string $inspectedAt,
    ) {}

    public static function parse(string $output): ?self
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", trim($output))), fn ($line) => $line !== ''));
        if (count($lines) < 2) {
            return null;
        }

        $header = explode('|', $lines[0], 2);
        if (count($header) !== 2 || preg_match('/^sha256:[0-9a-f]{64}$/', $header[0]) !== 1 || $header[1] === '' || strlen($header[1]) > 512) {
            return null;
        }

        try {
            $digests = json_decode($lines[1], true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $repoDigests = [];
        foreach (is_array($digests) ? $digests : [] as $digest) {
            if (is_string($digest) && preg_match('/^[a-z0-9._:\/-]+@sha256:[0-9a-f]{64}$/', $digest) === 1) {
                $repoDigests[] = $digest;
            }
        }

        return new self($header[0], $header[1], $repoDigests, now()->toIso8601String());
    }

    public function digestFor(string $repository): ?string
    {
        $wanted = self::normalizeRepository($repository);
        foreach ($this->repoDigests as $repoDigest) {
            [$name, $digest] = explode('@', $repoDigest, 2);
            if (self::normalizeRepository($name) === $wanted) {
                return $digest;
            }
        }

        return null;
    }

    public function runs(string $repository, string $digest): bool
    {
        $actual = $this->digestFor($repository);

        return $actual !== null && hash_equals($digest, $actual);
    }

    public static function normalizeRepository(string $repository): string
    {
        $repository = strtolower($repository);
        $repository = preg_replace('/^(?:docker\.io|index\.docker\.io|registry-1\.docker\.io)\//', '', $repository);
        if (! str_contains(explode('/', $repository)[0], '.') && ! str_contains($repository, '/')) {
            $repository = "library/{$repository}";
        }

        return $repository;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'image_id' => $this->imageId,
            'reference' => $this->reference,
            'repo_digests' => $this->repoDigests,
            'inspected_at' => $this->inspectedAt,
        ];
    }
}
