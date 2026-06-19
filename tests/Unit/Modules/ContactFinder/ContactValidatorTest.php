<?php

namespace Tests\Unit\Modules\ContactFinder;

use App\Modules\ContactFinder\Services\ContactValidator;
use Tests\TestCase;

class ContactValidatorTest extends TestCase
{
    private ContactValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = $this->app->make(ContactValidator::class);
    }

    public function test_rejects_gmail_as_personal_email(): void
    {
        $this->assertTrue($this->validator->isPersonalEmail('user@gmail.com'));
    }

    public function test_rejects_yahoo_as_personal_email(): void
    {
        $this->assertTrue($this->validator->isPersonalEmail('user@yahoo.com'));
    }

    public function test_rejects_hotmail_as_personal_email(): void
    {
        $this->assertTrue($this->validator->isPersonalEmail('user@hotmail.com'));
    }

    public function test_rejects_outlook_as_personal_email(): void
    {
        $this->assertTrue($this->validator->isPersonalEmail('user@outlook.com'));
    }

    public function test_rejects_icloud_as_personal_email(): void
    {
        $this->assertTrue($this->validator->isPersonalEmail('user@icloud.com'));
    }

    public function test_accepts_business_domain_email(): void
    {
        $this->assertFalse($this->validator->isPersonalEmail('bob@ironcladweld.com'));
    }

    public function test_accepts_company_specific_domain(): void
    {
        $this->assertFalse($this->validator->isPersonalEmail('d.ortega@cedarridgeplumbing.com'));
    }

    public function test_is_business_email_returns_false_for_personal(): void
    {
        $this->assertFalse($this->validator->isBusinessEmail('user@gmail.com'));
    }

    public function test_is_business_email_returns_true_for_business(): void
    {
        $this->assertTrue($this->validator->isBusinessEmail('bob@company.com'));
    }

    public function test_is_business_email_returns_false_for_empty(): void
    {
        $this->assertFalse($this->validator->isBusinessEmail(''));
    }

    public function test_identifies_generic_email(): void
    {
        $this->assertTrue($this->validator->isGenericEmail('info@company.com'));
        $this->assertTrue($this->validator->isGenericEmail('sales@company.com'));
        $this->assertTrue($this->validator->isGenericEmail('contact@company.com'));
    }

    public function test_identifies_non_generic_email(): void
    {
        $this->assertFalse($this->validator->isGenericEmail('bob@company.com'));
        $this->assertFalse($this->validator->isGenericEmail('d.ortega@company.com'));
    }

    public function test_normalizes_phone_10_digit_to_e164(): void
    {
        $this->assertEquals('+14025550148', $this->validator->normalizePhone('4025550148'));
    }

    public function test_normalizes_phone_11_digit_with_country_code(): void
    {
        $this->assertEquals('+14025550148', $this->validator->normalizePhone('14025550148'));
    }

    public function test_preserves_already_e164_phone(): void
    {
        $this->assertEquals('+14025550148', $this->validator->normalizePhone('+14025550148'));
    }

    public function test_validates_valid_phone_format(): void
    {
        $this->assertTrue($this->validator->isValidPhoneFormat('+1-402-555-0148'));
        $this->assertTrue($this->validator->isValidPhoneFormat('(402) 555-0148'));
        $this->assertTrue($this->validator->isValidPhoneFormat('4025550148'));
    }

    public function test_rejects_invalid_phone_format(): void
    {
        $this->assertFalse($this->validator->isValidPhoneFormat('123'));
        $this->assertFalse($this->validator->isValidPhoneFormat('not-a-phone'));
    }
}
