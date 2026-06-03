<?php

namespace App\Modules\ContactFinder\Providers;

use RuntimeException;

/**
 * Loads the canned provider fixtures once and serves each provider's slice for
 * a company. A missing company key or a missing provider key is a legitimate
 * "not found" (returns null), never an error.
 */
class MockDataset
{
    /** @param  array<string,array<string,mixed>>  $data */
    public function __construct(private array $data) {}

    public static function fromFile(string $path): self
    {
        if (! is_file($path)) {
            throw new RuntimeException("Mock dataset not found at: {$path}");
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException("Mock dataset is not valid JSON: {$path}");
        }

        return new self($decoded);
    }

    /** @return array<string,mixed>|null */
    public function for(string $company, string $provider): ?array
    {
        $entry = $this->data[$company][$provider] ?? null;

        return is_array($entry) ? $entry : null;
    }
}
