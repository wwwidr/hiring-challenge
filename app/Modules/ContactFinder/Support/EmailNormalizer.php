<?php

namespace App\Modules\ContactFinder\Support;

/**
 * Normalizes emails and distinguishes a personal-looking mailbox
 * (e.g. d.ortega@) from a generic role mailbox (info@, office@, sales@).
 * Generic mailboxes are weaker evidence of a specific decision-maker.
 */
class EmailNormalizer
{
    private const GENERIC_LOCAL_PARTS = [
        'info', 'office', 'sales', 'contact', 'admin', 'support', 'billing',
        'accounts', 'accounting', 'hello', 'team', 'mail', 'enquiries',
        'inquiries', 'help', 'service', 'services', 'general', 'no-reply',
        'noreply',
    ];

    public function normalize(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }
        $email = strtolower(trim($email));

        return $email === '' ? null : $email;
    }

    public function localPart(string $email): string
    {
        $at = strpos($email, '@');

        return $at === false ? $email : substr($email, 0, $at);
    }

    public function isGeneric(string $email): bool
    {
        $local = $this->localPart(strtolower($email));

        return in_array($local, self::GENERIC_LOCAL_PARTS, true);
    }

    /** Local part with separators turned into spaces, for name comparison. */
    public function localPartAsWords(string $email): string
    {
        return str_replace(['.', '_', '-', '+'], ' ', $this->localPart(strtolower($email)));
    }
}
