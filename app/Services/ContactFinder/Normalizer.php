<?php

declare(strict_types=1);

namespace App\Services\ContactFinder;

use App\Services\ContactFinder\Types\NormalizedCompany;

final class Normalizer
{
    private const ENTITY_SUFFIXES = ['LLC', 'PLLC', 'LLP', 'Corp', 'Inc', 'Ltd', 'LP', 'PC', 'Co'];

    public function normalize(string $name, string $address): NormalizedCompany
    {
        $entity_type = null;
        $common_name = $name;

        foreach (self::ENTITY_SUFFIXES as $suffix) {
            $pattern = '/\b' . preg_quote($suffix) . '\.?$/i';
            if (preg_match($pattern, trim($name))) {
                $entity_type = $suffix;
                $common_name = preg_replace($pattern, '', $name);
                $common_name = preg_replace('/,\s*$/', '', $common_name);
                $common_name = trim($common_name);
                break;
            }
        }

        $parts = array_map('trim', explode(',', $address));
        $street = $parts[0] ?? '';
        $city = $parts[1] ?? '';
        $state_zip = trim($parts[2] ?? '');
        $state_zip_parts = preg_split('/\s+/', $state_zip);
        $state = $state_zip_parts[0] ?? '';
        $zip = $state_zip_parts[1] ?? '';

        return new NormalizedCompany(
            legal_name: $name,
            common_name: $common_name,
            entity_type: $entity_type,
            street: $street,
            city: $city,
            state: $state,
            zip: $zip,
        );
    }
}
