<?php

namespace Tests\Unit;

use App\Services\ContactNormaliser;
use PHPUnit\Framework\TestCase;

class ContactNormaliserTest extends TestCase
{
    private array $providerA;
    private array $providerB;
    private array $llmData;

    protected function setUp(): void
    {
        $this->providerA = [
            'name'                => 'Dupont Industries SAS',
            'registration_number' => 'FR-482-910-231',
            'address'             => ['street' => '14 Rue de Rivoli', 'city' => 'Paris'],
        ];

        $this->providerB = [
            ['type' => 'email', 'value' => 'contact@dupont-industries.fr', 'role' => 'general'],
            ['type' => 'email', 'value' => 'j.martin@dupont-industries.fr', 'role' => 'finance'],
            ['type' => 'phone', 'value' => '+33 1 42 60 00 01', 'role' => 'main'],
        ];

        $this->llmData = [
            'ceo_name'        => 'Jean-Pierre Dupont',
            'ceo_email'       => null,
            'finance_contact' => ['name' => null, 'email' => 'j.martin@dupont-industries.fr'],
            'ops_contact'     => ['name' => 'Marie Lefevre', 'email' => null],
        ];
    }

    // -------------------------------------------------------------------------
    // Provider A fields — always high confidence

    /** @test */
    public function company_name_from_provider_a_is_high_confidence(): void
    {
        $result = ContactNormaliser::merge($this->providerA, $this->providerB, $this->llmData);

        $this->assertSame('Dupont Industries SAS', $result['company']['name']['value']);
        $this->assertSame('high', $result['company']['name']['confidence']);
        $this->assertSame('provider_a', $result['company']['name']['source']);
    }

    /** @test */
    public function missing_provider_a_field_returns_cannot_verify(): void
    {
        $providerA = ['name' => null, 'registration_number' => null, 'address' => null];

        $result = ContactNormaliser::merge($providerA, [], []);

        $this->assertSame('cannot-verify', $result['company']['name']['confidence']);
        $this->assertNull($result['company']['name']['value']);
        $this->assertNull($result['company']['name']['source']);
    }

    // -------------------------------------------------------------------------
    // Provider B contacts — always medium confidence

    /** @test */
    public function emails_from_provider_b_are_medium_confidence(): void
    {
        $result = ContactNormaliser::merge($this->providerA, $this->providerB, $this->llmData);

        $emails = $result['contacts']['emails'];
        $this->assertCount(2, $emails);
        foreach ($emails as $email) {
            $this->assertSame('medium', $email['confidence']);
            $this->assertSame('provider_b', $email['source']);
        }
    }

    /** @test */
    public function phones_from_provider_b_are_separated_from_emails(): void
    {
        $result = ContactNormaliser::merge($this->providerA, $this->providerB, $this->llmData);

        $this->assertCount(1, $result['contacts']['phones']);
        $this->assertSame('+33 1 42 60 00 01', $result['contacts']['phones'][0]['value']);
    }

    // -------------------------------------------------------------------------
    // LLM extracted data — low confidence, except when cross-validated

    /** @test */
    public function ceo_name_extracted_by_llm_is_low_confidence(): void
    {
        $result = ContactNormaliser::merge($this->providerA, $this->providerB, $this->llmData);

        $this->assertSame('Jean-Pierre Dupont', $result['key_people']['ceo']['name']['value']);
        $this->assertSame('low', $result['key_people']['ceo']['name']['confidence']);
    }

    /** @test */
    public function finance_email_found_in_both_llm_and_provider_b_is_upgraded_to_medium(): void
    {
        // j.martin@dupont-industries.fr appears in BOTH Provider B and LLM output
        $result = ContactNormaliser::merge($this->providerA, $this->providerB, $this->llmData);

        $financeEmail = $result['key_people']['finance']['email'];
        $this->assertSame('j.martin@dupont-industries.fr', $financeEmail['value']);
        $this->assertSame('medium', $financeEmail['confidence']); // upgraded because cross-validated
    }

    /** @test */
    public function ops_contact_with_no_email_returns_cannot_verify_for_email(): void
    {
        $result = ContactNormaliser::merge($this->providerA, $this->providerB, $this->llmData);

        $opsEmail = $result['key_people']['operations']['email'];
        $this->assertNull($opsEmail['value']);
        $this->assertSame('cannot-verify', $opsEmail['confidence']);
    }

    // -------------------------------------------------------------------------
    // Empty providers

    /** @test */
    public function empty_provider_b_produces_empty_contacts(): void
    {
        $result = ContactNormaliser::merge($this->providerA, [], $this->llmData);

        $this->assertSame([], $result['contacts']['emails']);
        $this->assertSame([], $result['contacts']['phones']);
    }

    /** @test */
    public function empty_llm_data_marks_key_people_as_cannot_verify(): void
    {
        $emptyLlm = ['ceo_name' => null, 'ceo_email' => null, 'finance_contact' => null, 'ops_contact' => null];

        $result = ContactNormaliser::merge($this->providerA, $this->providerB, $emptyLlm);

        $this->assertSame('cannot-verify', $result['key_people']['ceo']['name']['confidence']);
        $this->assertSame('cannot-verify', $result['key_people']['finance']['email']['confidence']);
    }
}
