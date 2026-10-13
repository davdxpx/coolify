<?php

namespace App\Services\Distribution;

final class DistributionManifest
{
    public const SCHEMA_VERSION = 1;

    public const CHANNELS = ['stable', 'staging', 'development'];

    public const MAX_RELEASES = 100;

    /**
     * @param  list<DistributionRelease>  $releases
     * @param  array<string, int>  $channels
     */
    public function __construct(
        public readonly string $distribution,
        public readonly string $image,
        public readonly array $releases,
        public readonly array $channels,
        public readonly ?UpstreamStatus $upstream,
        public readonly ?string $generatedAt,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, BuildIdentity $identity): self
    {
        if (! $identity->isCustom() || ! $identity->isValid()) {
            throw new InvalidDistributionManifest('This installation does not run a valid custom distribution.');
        }

        if (($data['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new InvalidDistributionManifest('The distribution manifest schema version is not supported.');
        }

        if (($data['distribution'] ?? null) !== $identity->distribution) {
            throw new InvalidDistributionManifest('The distribution manifest belongs to another distribution.');
        }

        if (($data['image'] ?? null) !== $identity->imageRepository) {
            throw new InvalidDistributionManifest('The distribution manifest points to an untrusted image repository.');
        }

        $rawReleases = $data['releases'] ?? null;
        if (! is_array($rawReleases) || ! array_is_list($rawReleases) || count($rawReleases) > self::MAX_RELEASES) {
            throw new InvalidDistributionManifest('The distribution manifest has an invalid release list.');
        }

        $releases = [];
        foreach ($rawReleases as $rawRelease) {
            if (! is_array($rawRelease)) {
                throw new InvalidDistributionManifest('The distribution manifest contains an invalid release entry.');
            }
            $release = DistributionRelease::fromArray($rawRelease, $identity->imageRepository);
            if (isset($releases[$release->build])) {
                throw new InvalidDistributionManifest("The distribution manifest lists build {$release->build} twice.");
            }
            $releases[$release->build] = $release;
        }
        krsort($releases);

        $channels = [];
        foreach (is_array($data['channels'] ?? null) ? $data['channels'] : [] as $channel => $build) {
            if (! in_array($channel, self::CHANNELS, true)) {
                continue;
            }
            if (! is_int($build) || ! isset($releases[$build])) {
                throw new InvalidDistributionManifest("Channel {$channel} points to an unknown build.");
            }
            $channels[$channel] = $build;
        }

        return new self(
            distribution: $identity->distribution,
            image: $identity->imageRepository,
            releases: array_values($releases),
            channels: $channels,
            upstream: is_array($data['upstream'] ?? null) ? UpstreamStatus::fromArray($data['upstream']) : null,
            generatedAt: is_string($data['generated_at'] ?? null) ? mb_substr($data['generated_at'], 0, 40) : null,
        );
    }

    public function releaseForChannel(string $channel): ?DistributionRelease
    {
        $build = $this->channels[$channel] ?? null;

        return $build !== null ? $this->findByBuild($build) : null;
    }

    public function findByBuild(?int $build): ?DistributionRelease
    {
        if ($build === null) {
            return null;
        }

        foreach ($this->releases as $release) {
            if ($release->build === $build) {
                return $release;
            }
        }

        return null;
    }

    public function findByDigest(?string $digest): ?DistributionRelease
    {
        if ($digest === null) {
            return null;
        }

        foreach ($this->releases as $release) {
            if (hash_equals($release->digest, $digest)) {
                return $release;
            }
        }

        return null;
    }

    /**
     * @return list<DistributionRelease>
     */
    public function releasesNewerThan(int $build, string $channel): array
    {
        $target = $this->releaseForChannel($channel);
        if ($target === null) {
            return [];
        }

        return array_values(array_filter(
            $this->releases,
            fn (DistributionRelease $release) => $release->build > $build && $release->build <= $target->build,
        ));
    }
}
