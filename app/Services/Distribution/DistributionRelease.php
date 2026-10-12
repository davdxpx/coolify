<?php

namespace App\Services\Distribution;

final class DistributionRelease
{
    public const DIGEST_PATTERN = '/^sha256:[0-9a-f]{64}$/';

    public const VERSION_PATTERN = '/^[0-9A-Za-z][0-9A-Za-z.+_-]{0,63}$/';

    public const MIGRATION_PATTERN = '/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]{1,200}$/';

    public const VERIFICATION_PASSED = 'passed';

    /**
     * @param  list<string>  $channels
     * @param  list<array{name: string, conclusion: string}>  $verificationChecks
     */
    public function __construct(
        public readonly string $version,
        public readonly string $baseVersion,
        public readonly int $build,
        public readonly string $commit,
        public readonly string $image,
        public readonly string $digest,
        public readonly array $channels,
        public readonly string $verificationStatus,
        public readonly array $verificationChecks = [],
        public readonly ?string $verificationUrl = null,
        public readonly ?string $latestMigration = null,
        public readonly ?int $migrationCount = null,
        public readonly ?string $publishedAt = null,
        public readonly ?string $notesUrl = null,
        public readonly ?string $summary = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $expectedImage): self
    {
        $version = $data['version'] ?? null;
        if (! is_string($version) || preg_match(self::VERSION_PATTERN, $version) !== 1) {
            throw new InvalidDistributionManifest('A release has an invalid version label.');
        }

        $baseVersion = $data['base_version'] ?? null;
        if (! OfficialVersion::isValid($baseVersion)) {
            throw new InvalidDistributionManifest("Release {$version} has an invalid base version.");
        }

        $build = $data['build'] ?? null;
        if (! is_int($build) || $build < 1) {
            throw new InvalidDistributionManifest("Release {$version} has an invalid build number.");
        }

        $commit = $data['commit'] ?? null;
        if (! is_string($commit) || preg_match(BuildIdentity::COMMIT_PATTERN, $commit) !== 1) {
            throw new InvalidDistributionManifest("Release {$version} has an invalid commit.");
        }

        $image = $data['image'] ?? null;
        if ($image !== $expectedImage) {
            throw new InvalidDistributionManifest("Release {$version} points to an image outside the trusted repository.");
        }

        $digest = $data['digest'] ?? null;
        if (! is_string($digest) || preg_match(self::DIGEST_PATTERN, $digest) !== 1) {
            throw new InvalidDistributionManifest("Release {$version} has an invalid image digest.");
        }

        $verification = is_array($data['verification'] ?? null) ? $data['verification'] : [];
        $status = $verification['status'] ?? 'unknown';
        $status = in_array($status, ['passed', 'failed', 'pending', 'unknown'], true) ? $status : 'unknown';

        $checks = [];
        foreach (array_slice(is_array($verification['checks'] ?? null) ? $verification['checks'] : [], 0, 50) as $check) {
            if (is_array($check) && is_string($check['name'] ?? null) && is_string($check['conclusion'] ?? null)) {
                $checks[] = [
                    'name' => mb_substr($check['name'], 0, 120),
                    'conclusion' => mb_substr($check['conclusion'], 0, 40),
                ];
            }
        }
        if ($status === self::VERIFICATION_PASSED && collect($checks)->contains(fn ($check) => $check['conclusion'] !== 'success')) {
            $status = 'failed';
        }

        $migrations = is_array($data['migrations'] ?? null) ? $data['migrations'] : [];
        $latestMigration = $migrations['latest'] ?? null;
        if ($latestMigration !== null && (! is_string($latestMigration) || preg_match(self::MIGRATION_PATTERN, $latestMigration) !== 1)) {
            throw new InvalidDistributionManifest("Release {$version} has an invalid migration marker.");
        }

        $channels = array_values(array_filter(
            is_array($data['channels'] ?? null) ? $data['channels'] : [],
            fn ($channel) => in_array($channel, DistributionManifest::CHANNELS, true),
        ));

        return new self(
            version: $version,
            baseVersion: $baseVersion,
            build: $build,
            commit: $commit,
            image: $image,
            digest: $digest,
            channels: $channels,
            verificationStatus: $status,
            verificationChecks: $checks,
            verificationUrl: self::httpsUrl($verification['url'] ?? null),
            latestMigration: $latestMigration,
            migrationCount: is_int($migrations['count'] ?? null) ? $migrations['count'] : null,
            publishedAt: is_string($data['published_at'] ?? null) ? mb_substr($data['published_at'], 0, 40) : null,
            notesUrl: self::httpsUrl($data['notes_url'] ?? null),
            summary: is_string($data['summary'] ?? null) ? mb_substr($data['summary'], 0, 500) : null,
        );
    }

    public function reference(): string
    {
        return "{$this->image}@{$this->digest}";
    }

    public function isVerified(): bool
    {
        return $this->verificationStatus === self::VERIFICATION_PASSED;
    }

    public function shortCommit(): string
    {
        return substr($this->commit, 0, 12);
    }

    public function shortDigest(): string
    {
        return substr($this->digest, 0, 19);
    }

    public function isNewerThanBuild(BuildIdentity $identity): bool
    {
        return $identity->buildNumber !== null && $this->build > $identity->buildNumber;
    }

    public function hasOlderBaseThan(BuildIdentity $identity): bool
    {
        return $identity->baseVersion !== null && OfficialVersion::isOlder($this->baseVersion, $identity->baseVersion);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'base_version' => $this->baseVersion,
            'build' => $this->build,
            'commit' => $this->commit,
            'image' => $this->image,
            'digest' => $this->digest,
            'channels' => $this->channels,
            'verification' => [
                'status' => $this->verificationStatus,
                'checks' => $this->verificationChecks,
                'url' => $this->verificationUrl,
            ],
            'migrations' => [
                'latest' => $this->latestMigration,
                'count' => $this->migrationCount,
            ],
            'published_at' => $this->publishedAt,
            'notes_url' => $this->notesUrl,
            'summary' => $this->summary,
        ];
    }

    private static function httpsUrl(mixed $value): ?string
    {
        return is_string($value) && strlen($value) <= 2048 && str_starts_with($value, 'https://') && filter_var($value, FILTER_VALIDATE_URL) !== false
            ? $value
            : null;
    }
}
