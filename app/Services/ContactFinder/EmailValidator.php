<?php

declare(strict_types=1);

namespace App\Services\ContactFinder;

final class EmailValidator
{
    /** @var array<string, bool> */
    private const GENERIC_EMAIL_PREFIXES = [
        'info' => true, 'office' => true, 'contact' => true, 'sales' => true, 'admin' => true, 'hello' => true,
        'team' => true, 'billing' => true, 'accounts' => true, 'ap' => true, 'support' => true, 'mail' => true,
        'noreply' => true, 'no-reply' => true, 'inquiry' => true, 'ask' => true, 'question' => true,
        'test' => true, 'example' => true, 'help' => true, 'notifications' => true, 'notify' => true,
    ];

    public function isGeneric(?string $email): bool
    {
        if (!$email) {
            return false;
        }

        $local = strtolower(explode('@', $email)[0]);

        return isset(self::GENERIC_EMAIL_PREFIXES[$local]);
    }

    public function isValid(?string $email): bool
    {
        return $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
