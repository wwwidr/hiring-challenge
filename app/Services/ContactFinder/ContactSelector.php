<?php

declare(strict_types=1);

namespace App\Services\ContactFinder;

use App\Services\ContactFinder\Types\MockRecord;
use App\Services\ContactFinder\Types\SelectedContact;

final class ContactSelector
{
    /** @var set<string> */
    private const NON_DECISION_MAKER_ROLES = ['registered agent', 'agent'];

    /** @var array<string, int> */
    private const ROLE_PRIORITY = [
        'ap manager' => 1, 'accounts payable' => 1, 'ap' => 1,
        'owner' => 2, 'founder' => 2, 'co-owner' => 2, 'proprietor' => 2,
        'president' => 3, 'cfo' => 3, 'controller' => 3,
        'office manager' => 4, 'manager' => 4,
    ];

    public function __construct(
        private readonly NameMatcher $nameMatcher,
    ) {}

    public function selectBestContact(MockRecord $record): SelectedContact
    {
        $sources = [];

        if ($record->registry) {
            $sources[] = $record->registry->source_url;
        }
        if ($record->listing) {
            $sources[] = $record->listing->source_url;
        }
        if ($record->enrichment) {
            $sources[] = $record->enrichment->source_url;
        }

        $registry_name = $record->registry?->name ?? null;
        $registry_role = $record->registry?->role ?? null;
        $listing_name = $record->listing?->name ?? null;
        $registry_excluded = $this->getRolePriority($registry_role) === 999;

        $name = null;
        $role = null;

        if ($registry_name && !$registry_excluded) {
            $name = $registry_name;
            $role = $registry_role;
        }

        if (!$name && $listing_name) {
            $name = $listing_name;
            $role = null;
        }

        $email = $record->enrichment?->email ?? null;
        $phone = $record->listing?->phone ?? $record->enrichment?->phone ?? null;

        $contact_email_or_phone = null;
        if ($email && $phone) {
            $contact_email_or_phone = $email;
        } else {
            $contact_email_or_phone = $email ?? $phone ?? null;
        }

        return new SelectedContact(
            name: $name,
            role: $role,
            contact_email_or_phone: $contact_email_or_phone,
            sources: $sources,
        );
    }

    private function getRolePriority(?string $role): int
    {
        if (!$role) {
            return 99;
        }

        $lower = strtolower($role);

        if (in_array($lower, self::NON_DECISION_MAKER_ROLES, true)) {
            return 999;
        }

        foreach (self::ROLE_PRIORITY as $key => $priority) {
            if (str_contains($lower, $key)) {
                return $priority;
            }
        }

        return 99;
    }
}
