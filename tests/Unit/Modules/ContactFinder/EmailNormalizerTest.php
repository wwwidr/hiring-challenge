<?php

namespace Tests\Unit\Modules\ContactFinder;

use App\Modules\ContactFinder\Support\EmailNormalizer;
use PHPUnit\Framework\TestCase;

class EmailNormalizerTest extends TestCase
{
    private EmailNormalizer $emails;

    protected function setUp(): void
    {
        $this->emails = new EmailNormalizer;
    }

    public function test_normalizes_case_and_whitespace(): void
    {
        $this->assertSame('d.ortega@example.com', $this->emails->normalize('  D.Ortega@Example.com '));
        $this->assertNull($this->emails->normalize(null));
        $this->assertNull($this->emails->normalize('   '));
    }

    public function test_detects_generic_mailboxes(): void
    {
        $this->assertTrue($this->emails->isGeneric('info@riversideprint.biz'));
        $this->assertTrue($this->emails->isGeneric('office@sunbeltroofingaz.com'));
        $this->assertTrue($this->emails->isGeneric('sales@anchormarine.co'));
    }

    public function test_personal_mailbox_is_not_generic(): void
    {
        $this->assertFalse($this->emails->isGeneric('d.ortega@cedarridgeplumbing.com'));
        $this->assertFalse($this->emails->isGeneric('emily.hart@brooksidevet.com'));
    }

    public function test_local_part_as_words(): void
    {
        $this->assertSame('d ortega', $this->emails->localPartAsWords('d.ortega@example.com'));
        $this->assertSame('emily hart', $this->emails->localPartAsWords('emily.hart@example.com'));
    }
}
