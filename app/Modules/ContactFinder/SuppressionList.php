<?php

namespace App\Modules\ContactFinder;

/**
 * Opt-out / do-not-contact support (a real compliance requirement). Company
 * names listed here are skipped and never enriched or contacted. Matching is
 * case-insensitive and whitespace-tolerant.
 */
class SuppressionList
{
    /** @var array<string,true> */
    private array $blocked = [];

    /** @param  string[]  $companyNames */
    public function __construct(array $companyNames = [])
    {
        foreach ($companyNames as $name) {
            $key = $this->key($name);
            if ($key !== '') {
                $this->blocked[$key] = true;
            }
        }
    }

    public static function fromFile(?string $path): self
    {
        if ($path === null || ! is_file($path)) {
            return new self;
        }

        $lines = preg_split('/\r\n|\r|\n/', (string) file_get_contents($path)) ?: [];
        $names = array_filter($lines, fn ($l) => trim($l) !== '' && ! str_starts_with(trim($l), '#'));

        return new self(array_values($names));
    }

    public function isSuppressed(string $companyName): bool
    {
        return isset($this->blocked[$this->key($companyName)]);
    }

    public function count(): int
    {
        return count($this->blocked);
    }

    private function key(string $name): string
    {
        return strtolower(trim($name));
    }
}
