<?php

namespace App\Services\Distribution;

final class UpstreamStatus
{
    public const SYNC_STATES = ['clean', 'conflicts', 'merged', 'pending_review', 'unknown'];

    /**
     * @param  list<string>  $conflictingFiles
     * @param  list<array{id: string, severity: string, summary: string, first_patched_version: ?string, url: ?string}>  $advisories
     */
    public function __construct(
        public readonly ?string $repository,
        public readonly ?string $latestRelease,
        public readonly ?string $latestReleasePublishedAt,
        public readonly ?string $baseVersion,
        public readonly ?int $commitsBehind,
        public readonly string $syncState,
        public readonly ?string $syncBranch,
        public readonly ?string $syncPullRequestUrl,
        public readonly array $conflictingFiles,
        public readonly array $advisories,
        public readonly ?string $lastSyncedAt,
        public readonly ?string $checkedAt,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $sync = is_array($data['sync'] ?? null) ? $data['sync'] : [];
        $syncState = in_array($sync['state'] ?? null, self::SYNC_STATES, true) ? $sync['state'] : 'unknown';

        $advisories = [];
        foreach (array_slice(is_array($data['advisories'] ?? null) ? $data['advisories'] : [], 0, 50) as $advisory) {
            if (! is_array($advisory) || ! is_string($advisory['id'] ?? null)) {
                continue;
            }
            $patched = $advisory['first_patched_version'] ?? null;
            $advisories[] = [
                'id' => mb_substr($advisory['id'], 0, 64),
                'severity' => in_array($advisory['severity'] ?? null, ['low', 'medium', 'high', 'critical'], true) ? $advisory['severity'] : 'unknown',
                'summary' => is_string($advisory['summary'] ?? null) ? mb_substr($advisory['summary'], 0, 300) : '',
                'first_patched_version' => OfficialVersion::isValid($patched) ? $patched : null,
                'url' => is_string($advisory['url'] ?? null) && str_starts_with($advisory['url'], 'https://') ? mb_substr($advisory['url'], 0, 500) : null,
            ];
        }

        $files = [];
        foreach (array_slice(is_array($sync['conflicting_files'] ?? null) ? $sync['conflicting_files'] : [], 0, 200) as $file) {
            if (is_string($file) && $file !== '') {
                $files[] = mb_substr($file, 0, 300);
            }
        }

        return new self(
            repository: is_string($data['repository'] ?? null) && preg_match(BuildIdentity::DISTRIBUTION_PATTERN, $data['repository']) === 1 ? $data['repository'] : null,
            latestRelease: OfficialVersion::isValid($data['latest_release'] ?? null) ? $data['latest_release'] : null,
            latestReleasePublishedAt: is_string($data['latest_release_published_at'] ?? null) ? mb_substr($data['latest_release_published_at'], 0, 40) : null,
            baseVersion: OfficialVersion::isValid($data['base_version'] ?? null) ? $data['base_version'] : null,
            commitsBehind: is_int($data['commits_behind'] ?? null) && $data['commits_behind'] >= 0 ? $data['commits_behind'] : null,
            syncState: $syncState,
            syncBranch: is_string($sync['branch'] ?? null) ? mb_substr($sync['branch'], 0, 200) : null,
            syncPullRequestUrl: is_string($sync['pull_request'] ?? null) && str_starts_with($sync['pull_request'], 'https://') ? mb_substr($sync['pull_request'], 0, 500) : null,
            conflictingFiles: $files,
            advisories: $advisories,
            lastSyncedAt: is_string($data['last_synced_at'] ?? null) ? mb_substr($data['last_synced_at'], 0, 40) : null,
            checkedAt: is_string($data['checked_at'] ?? null) ? mb_substr($data['checked_at'], 0, 40) : null,
        );
    }

    /**
     * Advisories fixed upstream in a version newer than the given base version.
     *
     * @return list<array{id: string, severity: string, summary: string, first_patched_version: ?string, url: ?string}>
     */
    public function advisoriesPendingFor(?string $baseVersion): array
    {
        if (! OfficialVersion::isValid($baseVersion)) {
            return [];
        }

        return array_values(array_filter(
            $this->advisories,
            fn (array $advisory) => $advisory['first_patched_version'] !== null
                && OfficialVersion::isOlder($baseVersion, $advisory['first_patched_version']),
        ));
    }

    public function isBehind(?string $baseVersion): ?bool
    {
        if ($this->latestRelease === null || ! OfficialVersion::isValid($baseVersion)) {
            return null;
        }

        return OfficialVersion::isOlder($baseVersion, $this->latestRelease);
    }
}
