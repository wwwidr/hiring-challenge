<?php

declare(strict_types=1);

namespace App\Services\ContactFinder\Types;

final readonly class SelectedContact
{
    public function __construct(
        public ?string $name,
        public ?string $role,
        public ?string $contact_email_or_phone,
        /** @var list<string> */
        public array $sources,
    ) {}
}
