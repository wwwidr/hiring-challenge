<?php

namespace App\Modules\ContactFinder\ValueObjects;

class ProvenanceField
{
    /**
     * @param string[] $sources
     * @param string[] $sourceUrls
     */
    public function __construct(
        public readonly string $value,
        public readonly array $sources,
        public readonly array $sourceUrls,
    ) {}

    public static function fromSingle(string $value, string $source, string $sourceUrl): self
    {
        return new self($value, [$source], [$sourceUrl]);
    }

    public function mergeWith(self $other): self
    {
        return new self(
            $this->value,
            array_unique(array_merge($this->sources, $other->sources)),
            array_unique(array_merge($this->sourceUrls, $other->sourceUrls)),
        );
    }
}
