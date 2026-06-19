<?php

namespace App\Modules\ContactFinder\Services;

class ContactValidator
{
    private const PERSONAL_EMAIL_DOMAINS = [
        'gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'aol.com',
        'icloud.com', 'mail.com', 'protonmail.com', 'zoho.com', 'yandex.com',
        'live.com', 'msn.com', 'me.com', 'inbox.com', 'gmx.com',
        'yahoo.co.uk', 'hotmail.co.uk', 'outlook.co.uk',
    ];

    /** @var string[] */
    private readonly array $genericEmailPrefixes;

    public function __construct()
    {
        $this->genericEmailPrefixes = config('enrichment.generic_email_prefixes', []);
    }

    public function isPersonalEmail(string $email): bool
    {
        $domain = strtolower(trim(explode('@', $email)[1] ?? ''));

        return in_array($domain, self::PERSONAL_EMAIL_DOMAINS, true);
    }

    public function isGenericEmail(string $email): bool
    {
        $localPart = strtolower(explode('@', $email)[0] ?? '');

        return in_array($localPart, $this->genericEmailPrefixes, true);
    }

    public function isBusinessEmail(string $email): bool
    {
        if (empty($email)) {
            return false;
        }

        if ($this->isPersonalEmail($email)) {
            return false;
        }

        return true;
    }

    public function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/[^\d+]/', '', $phone);

        if (str_starts_with($digits, '+')) {
            return $digits;
        }

        if (strlen($digits) === 10) {
            return '+1' . $digits;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+' . $digits;
        }

        return $phone;
    }

    public function isValidPhoneFormat(string $phone): bool
    {
        $cleaned = preg_replace('/[\s\-\(\)\.]/', '', $phone);

        return (bool) preg_match('/^\+?1?\d{10,15}$/', $cleaned);
    }
}
