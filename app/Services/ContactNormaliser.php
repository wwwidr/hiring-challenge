<?php

namespace App\Services;

class ContactNormaliser
{
    /**
     * Merge data from all three providers into a single validated result.
     *
     * Rule: deterministic code does ALL merging and scoring.
     * The LLM's job ended at extraction — it does not touch this method.
     *
     * Confidence waterfall per field:
     *   Provider A (high) > Provider B (medium) > LLM-extracted (low) > cannot-verify
     */
    public static function merge(
        array $providerA,
        array $providerB,
        array $llmData
    ): array {
        return [
            'company' => [
                'name'                => self::field(
                    $providerA['name'] ?? null, 'provider_a', 'high'
                ),
                'registration_number' => self::field(
                    $providerA['registration_number'] ?? null, 'provider_a', 'high'
                ),
                'address'             => self::field(
                    $providerA['address'] ?? null, 'provider_a', 'high'
                ),
            ],
            'contacts' => [
                'emails'  => self::mergeContacts($providerB, 'email'),
                'phones'  => self::mergeContacts($providerB, 'phone'),
            ],
            'key_people' => [
                'ceo' => self::mergePerson(
                    name:  $llmData['ceo_name']  ?? null,
                    email: $llmData['ceo_email'] ?? null,
                ),
                'finance' => self::mergePerson(
                    name:  $llmData['finance_contact']['name']  ?? null,
                    email: $llmData['finance_contact']['email'] ?? null,
                    // Cross-check: if Provider B already has this email, upgrade confidence
                    emailConfidence: self::emailExistsInProviderB($providerB, $llmData['finance_contact']['email'] ?? null)
                        ? 'medium'
                        : 'low',
                ),
                'operations' => self::mergePerson(
                    name:  $llmData['ops_contact']['name']  ?? null,
                    email: $llmData['ops_contact']['email'] ?? null,
                ),
            ],
        ];
    }

    // -------------------------------------------------------------------------

    private static function field(mixed $value, string $source, string $confidence): array
    {
        if ($value === null) {
            return ['value' => null, 'confidence' => 'cannot-verify', 'source' => null];
        }

        return ['value' => $value, 'confidence' => $confidence, 'source' => $source];
    }

    private static function mergeContacts(array $providerB, string $type): array
    {
        return array_values(array_map(
            fn($c) => [
                'value'      => $c['value'],
                'role'       => $c['role'] ?? 'general',
                'confidence' => 'medium',
                'source'     => 'provider_b',
            ],
            array_filter($providerB, fn($c) => ($c['type'] ?? '') === $type)
        ));
    }

    private static function mergePerson(?string $name, ?string $email, string $emailConfidence = 'low'): array
    {
        return [
            'name'  => self::field($name, 'llm_extracted', 'low'),
            'email' => $email !== null
                ? ['value' => $email, 'confidence' => $emailConfidence, 'source' => 'llm_extracted']
                : ['value' => null, 'confidence' => 'cannot-verify', 'source' => null],
        ];
    }

    private static function emailExistsInProviderB(array $providerB, ?string $email): bool
    {
        if ($email === null) {
            return false;
        }

        foreach ($providerB as $contact) {
            if (($contact['type'] ?? '') === 'email' && $contact['value'] === $email) {
                return true;
            }
        }

        return false;
    }
}
