<?php

namespace App\Services\Distribution;

use InvalidArgumentException;

final class OfficialVersion
{
    public const PATTERN = '/^\d+\.\d+(?:\.\d+)?(?:-(?:alpha|beta|rc)\.\d+(?:\.\d+)*)?$/';

    public static function isValid(?string $version): bool
    {
        return is_string($version) && preg_match(self::PATTERN, $version) === 1;
    }

    public static function compare(string $left, string $right): int
    {
        if (! self::isValid($left) || ! self::isValid($right)) {
            throw new InvalidArgumentException("Cannot compare non-official versions [{$left}] and [{$right}].");
        }

        return version_compare($left, $right);
    }

    public static function isOlder(string $candidate, string $reference): bool
    {
        return self::compare($candidate, $reference) < 0;
    }
}
