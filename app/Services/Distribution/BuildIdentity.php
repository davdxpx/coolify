<?php

namespace App\Services\Distribution;

use JsonException;

final class BuildIdentity
{
    public const FILE = 'build.json';

    public const SCHEMA_VERSION = 1;

    public const TYPE_OFFICIAL = 'official';

    public const TYPE_CUSTOM = 'custom';

    public const DISTRIBUTION_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}\/[A-Za-z0-9._-]{1,100}$/';

    public const IMAGE_REPOSITORY_PATTERN = '/^[a-z0-9]+(?:[.-][a-z0-9]+)*(?::\d{1,5})?(?:\/[a-z0-9]+(?:(?:[._]|__|-+)[a-z0-9]+)*)+$/';

    public const COMMIT_PATTERN = '/^[0-9a-f]{40}$/';

    private static ?self $current = null;

    public function __construct(
        public readonly string $type,
        public readonly ?string $distribution = null,
        public readonly ?string $baseVersion = null,
        public readonly ?int $buildNumber = null,
        public readonly ?string $sourceRepository = null,
        public readonly ?string $sourceCommit = null,
        public readonly ?string $imageRepository = null,
        public readonly ?string $manifestUrl = null,
        public readonly ?string $signatureIssuer = null,
        public readonly ?string $signatureIdentityPattern = null,
        public readonly ?string $builtAt = null,
        public readonly ?string $buildUrl = null,
        public readonly ?string $invalidReason = null,
    ) {}

    public static function current(): self
    {
        return self::$current ??= self::fromFile(base_path(self::FILE));
    }

    public static function setCurrent(?self $identity): void
    {
        self::$current = $identity;
    }

    public static function official(): self
    {
        return new self(type: self::TYPE_OFFICIAL);
    }

    public static function invalid(string $reason): self
    {
        return new self(type: self::TYPE_CUSTOM, invalidReason: $reason);
    }

    public static function fromFile(string $path): self
    {
        if (! is_file($path)) {
            return self::official();
        }

        $contents = @file_get_contents($path);
        if ($contents === false || strlen($contents) > 65536) {
            return self::invalid('The build identity file cannot be read.');
        }

        try {
            $data = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return self::invalid('The build identity file is not valid JSON.');
        }

        return is_array($data) ? self::fromArray($data) : self::invalid('The build identity file is not a JSON object.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        if (($data['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            return self::invalid('The build identity schema version is not supported.');
        }

        $type = $data['type'] ?? null;
        $commit = $data['source_commit'] ?? null;
        if (! is_string($commit) || preg_match(self::COMMIT_PATTERN, $commit) !== 1) {
            return $type === self::TYPE_OFFICIAL
                ? self::official()
                : self::invalid('The build identity has no valid source commit.');
        }

        if ($type === self::TYPE_OFFICIAL) {
            return new self(
                type: self::TYPE_OFFICIAL,
                baseVersion: OfficialVersion::isValid($data['base_version'] ?? null) ? $data['base_version'] : null,
                sourceCommit: $commit,
                builtAt: self::optionalString($data['built_at'] ?? null, 64),
            );
        }

        if ($type !== self::TYPE_CUSTOM) {
            return self::invalid('The build identity type is not supported.');
        }

        $distribution = $data['distribution'] ?? null;
        if (! is_string($distribution) || preg_match(self::DISTRIBUTION_PATTERN, $distribution) !== 1) {
            return self::invalid('The build identity has no valid distribution name.');
        }

        $baseVersion = $data['base_version'] ?? null;
        if (! OfficialVersion::isValid($baseVersion)) {
            return self::invalid('The build identity has no valid base version.');
        }

        $buildNumber = $data['build_number'] ?? null;
        if (! is_int($buildNumber) || $buildNumber < 1) {
            return self::invalid('The build identity has no valid build number.');
        }

        $imageRepository = $data['image_repository'] ?? null;
        if (! is_string($imageRepository) || preg_match(self::IMAGE_REPOSITORY_PATTERN, $imageRepository) !== 1 || ! self::hasRegistryHost($imageRepository)) {
            return self::invalid('The build identity has no valid image repository.');
        }

        $manifestUrl = $data['manifest_url'] ?? null;
        if (! self::isHttpsUrl($manifestUrl)) {
            return self::invalid('The build identity has no valid HTTPS manifest URL.');
        }

        $signature = $data['signature'] ?? null;
        $signatureIssuer = null;
        $signatureIdentityPattern = null;
        if ($signature !== null) {
            $signatureIssuer = is_array($signature) ? ($signature['issuer'] ?? null) : null;
            $signatureIdentityPattern = is_array($signature) ? ($signature['identity_regexp'] ?? null) : null;
            if (! self::isHttpsUrl($signatureIssuer) || ! is_string($signatureIdentityPattern) || $signatureIdentityPattern === '' || strlen($signatureIdentityPattern) > 512 || @preg_match('#'.str_replace('#', '\#', $signatureIdentityPattern).'#', '') === false) {
                return self::invalid('The build identity has an invalid signature policy.');
            }
        }

        return new self(
            type: self::TYPE_CUSTOM,
            distribution: $distribution,
            baseVersion: $baseVersion,
            buildNumber: $buildNumber,
            sourceRepository: self::isHttpsUrl($data['source_repository'] ?? null) ? $data['source_repository'] : null,
            sourceCommit: $commit,
            imageRepository: $imageRepository,
            manifestUrl: $manifestUrl,
            signatureIssuer: $signatureIssuer,
            signatureIdentityPattern: $signatureIdentityPattern,
            builtAt: self::optionalString($data['built_at'] ?? null, 64),
            buildUrl: self::isHttpsUrl($data['build_url'] ?? null) ? $data['build_url'] : null,
        );
    }

    public function isCustom(): bool
    {
        return $this->type === self::TYPE_CUSTOM;
    }

    public function isValid(): bool
    {
        return $this->invalidReason === null;
    }

    public function requiresSignature(): bool
    {
        return $this->signatureIssuer !== null && $this->signatureIdentityPattern !== null;
    }

    public function shortCommit(): ?string
    {
        return $this->sourceCommit !== null ? substr($this->sourceCommit, 0, 12) : null;
    }

    public function applyToConfiguration(): void
    {
        if (! $this->isCustom() || $this->baseVersion === null) {
            return;
        }

        config([
            'constants.coolify.configured_version' => config('constants.coolify.version'),
            'constants.coolify.version' => $this->baseVersion,
        ]);
    }

    public function environmentVersionMismatch(?string $environmentVersion): bool
    {
        return $this->isCustom()
            && $this->baseVersion !== null
            && filled($environmentVersion)
            && $environmentVersion !== $this->baseVersion;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'distribution' => $this->distribution,
            'base_version' => $this->baseVersion,
            'build_number' => $this->buildNumber,
            'source_repository' => $this->sourceRepository,
            'source_commit' => $this->sourceCommit,
            'image_repository' => $this->imageRepository,
            'manifest_url' => $this->manifestUrl,
            'signature_required' => $this->requiresSignature(),
            'built_at' => $this->builtAt,
            'build_url' => $this->buildUrl,
            'valid' => $this->isValid(),
            'invalid_reason' => $this->invalidReason,
        ];
    }

    public static function hasRegistryHost(string $repository): bool
    {
        $host = explode('/', $repository)[0];

        return str_contains($repository, '/') && (str_contains($host, '.') || str_contains($host, ':') || $host === 'localhost');
    }

    private static function isHttpsUrl(mixed $value): bool
    {
        return is_string($value)
            && strlen($value) <= 2048
            && filter_var($value, FILTER_VALIDATE_URL) !== false
            && parse_url($value, PHP_URL_SCHEME) === 'https'
            && filled(parse_url($value, PHP_URL_HOST))
            && parse_url($value, PHP_URL_USER) === null;
    }

    private static function optionalString(mixed $value, int $maxLength): ?string
    {
        return is_string($value) && $value !== '' && strlen($value) <= $maxLength ? $value : null;
    }
}
