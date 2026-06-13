<?php

declare(strict_types=1);

namespace App\Modules\ContactFinder\Support;

use RuntimeException;

/**
 * Loads the mocked provider fixtures from disk. The only I/O boundary in the
 * module — everything downstream operates on the decoded array, which keeps the
 * resolver and scorer pure and unit-testable.
 */
final class MockDataLoader
{
    /** @return array<string,mixed> decoded fixture keyed by company_name */
    public static function load(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Mock fixture not found: {$path}");
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException("Mock fixture is not valid JSON: {$path}");
        }

        /** @var array<string,mixed> $decoded */
        return $decoded;
    }
}
