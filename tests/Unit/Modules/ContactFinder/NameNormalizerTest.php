<?php

namespace Tests\Unit\Modules\ContactFinder;

use App\Modules\ContactFinder\Support\NameNormalizer;
use PHPUnit\Framework\TestCase;

class NameNormalizerTest extends TestCase
{
    private NameNormalizer $names;

    protected function setUp(): void
    {
        $this->names = new NameNormalizer;
    }

    public function test_strips_titles_and_parenthetical_tags(): void
    {
        $this->assertSame('Patel', $this->names->clean('Dr. Patel'));
        $this->assertSame('Jeff', $this->names->clean('Jeff (manager)'));
        $this->assertSame('Emily Hart', $this->names->clean('Dr. Emily Hart'));
    }

    public function test_matches_nickname_variants(): void
    {
        $this->assertTrue($this->names->matches('Robert Kowalski', 'Bob Kowalski'));
    }

    public function test_matches_initial_and_full_first_name(): void
    {
        $this->assertTrue($this->names->matches('Sean Murphy', 'S. Murphy'));
    }

    public function test_does_not_match_different_people(): void
    {
        $this->assertFalse($this->names->matches('Tina Alvarez', 'Marcus Webb'));
    }

    public function test_different_surnames_never_match(): void
    {
        $this->assertFalse($this->names->matches('Robert Kowalski', 'Robert Smith'));
    }

    public function test_partial_detection(): void
    {
        $this->assertTrue($this->names->isPartial('S. Murphy'));
        $this->assertTrue($this->names->isPartial('Jeff (manager)'));
        $this->assertTrue($this->names->isPartial('Patel'));
        $this->assertFalse($this->names->isPartial('Daniel Ortega'));
    }

    public function test_real_person_name_guard(): void
    {
        $this->assertFalse($this->names->isRealPersonName(null));
        $this->assertFalse($this->names->isRealPersonName(''));
        $this->assertTrue($this->names->isRealPersonName('Daniel Ortega'));
    }

    public function test_parenthetical_role_extraction(): void
    {
        $this->assertSame('manager', $this->names->parentheticalRole('Jeff (manager)'));
        $this->assertNull($this->names->parentheticalRole('Daniel Ortega'));
    }
}
