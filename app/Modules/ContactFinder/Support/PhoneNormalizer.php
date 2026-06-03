<?php

namespace App\Modules\ContactFinder\Support;

/**
 * Normalizes US phone numbers to a comparable E.164-style form so the same
 * number reported by two providers counts as corroboration.
 */
class PhoneNormalizer
{
    public function normalize(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $hasPlus = str_starts_with(trim($phone), '+');
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        if (! $hasPlus && strlen($digits) === 10) {
            return '+1'.$digits;
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+'.$digits;
        }

        return '+'.$digits;
    }

    public function equals(?string $a, ?string $b): bool
    {
        $a = $this->normalize($a);
        $b = $this->normalize($b);

        return $a !== null && $a === $b;
    }
}
