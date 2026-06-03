<?php

namespace Tests\Unit\Modules\ContactFinder;

use App\Modules\ContactFinder\Support\PhoneNormalizer;
use PHPUnit\Framework\TestCase;

class PhoneNormalizerTest extends TestCase
{
    private PhoneNormalizer $phones;

    protected function setUp(): void
    {
        $this->phones = new PhoneNormalizer;
    }

    public function test_normalizes_us_number_to_e164(): void
    {
        $this->assertSame('+14025550148', $this->phones->normalize('+1-402-555-0148'));
        $this->assertSame('+14025550148', $this->phones->normalize('(402) 555-0148'));
    }

    public function test_returns_null_for_empty(): void
    {
        $this->assertNull($this->phones->normalize(null));
        $this->assertNull($this->phones->normalize(''));
    }

    public function test_equality_across_formats(): void
    {
        $this->assertTrue($this->phones->equals('+1-402-555-0148', '402-555-0148'));
        $this->assertFalse($this->phones->equals('+1-402-555-0148', '+1-402-555-0199'));
        $this->assertFalse($this->phones->equals(null, null));
    }
}
