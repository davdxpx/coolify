<?php

$target = $argv[1] ?? '/var/www/html/build.json';

$value = static function (string $name): ?string {
    $value = getenv($name);

    return is_string($value) && trim($value) !== '' ? trim($value) : null;
};

$fail = static function (string $message): never {
    fwrite(STDERR, "Build identity: {$message}\n");
    exit(1);
};

$distribution = $value('COOLIFY_DISTRIBUTION');
if ($distribution === null) {
    exit(0);
}

$identity = [
    'schema_version' => 1,
    'type' => 'custom',
    'distribution' => $distribution,
    'base_version' => $value('COOLIFY_VERSION'),
    'build_number' => ctype_digit((string) $value('COOLIFY_DISTRIBUTION_BUILD_NUMBER')) ? (int) $value('COOLIFY_DISTRIBUTION_BUILD_NUMBER') : null,
    'source_repository' => $value('COOLIFY_SOURCE_REPOSITORY'),
    'source_commit' => $value('COOLIFY_SOURCE_COMMIT'),
    'image_repository' => $value('COOLIFY_DISTRIBUTION_IMAGE'),
    'manifest_url' => $value('COOLIFY_DISTRIBUTION_MANIFEST_URL'),
    'built_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'build_url' => $value('COOLIFY_BUILD_URL'),
];

$issuer = $value('COOLIFY_DISTRIBUTION_SIGNATURE_ISSUER');
$identityPattern = $value('COOLIFY_DISTRIBUTION_SIGNATURE_IDENTITY');
if ($issuer !== null || $identityPattern !== null) {
    $identity['signature'] = ['issuer' => $issuer, 'identity_regexp' => $identityPattern];
}

if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}\/[A-Za-z0-9._-]{1,100}$/', $distribution) !== 1) {
    $fail('COOLIFY_DISTRIBUTION must look like owner/repository.');
}
if (preg_match('/^\d+\.\d+(?:\.\d+)?(?:-(?:alpha|beta|rc)\.\d+(?:\.\d+)*)?$/', (string) $identity['base_version']) !== 1) {
    $fail('COOLIFY_VERSION must be an official Coolify version.');
}
if (! is_int($identity['build_number']) || $identity['build_number'] < 1) {
    $fail('COOLIFY_DISTRIBUTION_BUILD_NUMBER must be a positive integer.');
}
if (preg_match('/^[0-9a-f]{40}$/', (string) $identity['source_commit']) !== 1) {
    $fail('COOLIFY_SOURCE_COMMIT must be a full commit SHA.');
}
if (preg_match('/^[a-z0-9]+(?:[.-][a-z0-9]+)*(?::\d{1,5})?(?:\/[a-z0-9]+(?:(?:[._]|__|-+)[a-z0-9]+)*)+$/', (string) $identity['image_repository']) !== 1) {
    $fail('COOLIFY_DISTRIBUTION_IMAGE must be a lowercase image repository with a registry host.');
}
foreach (['manifest_url', 'source_repository', 'build_url'] as $key) {
    if ($identity[$key] !== null && ! str_starts_with($identity[$key], 'https://')) {
        $fail("{$key} must be an HTTPS URL.");
    }
}
if ($identity['manifest_url'] === null) {
    $fail('COOLIFY_DISTRIBUTION_MANIFEST_URL is required.');
}
if (isset($identity['signature']) && ($issuer === null || $identityPattern === null || ! str_starts_with($issuer, 'https://'))) {
    $fail('The signature issuer and identity pattern must be set together.');
}

if (file_put_contents($target, json_encode($identity, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n") === false) {
    $fail("Cannot write {$target}.");
}
chmod($target, 0644);
