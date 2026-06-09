<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ContactFinder\NameMatcher;
use PHPUnit\Framework\TestCase;

final class NameMatcherTest extends TestCase
{
    private NameMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new NameMatcher();
    }

    public function test_matches_exact_names(): void
    {
        $this->assertTrue($this->matcher->matches('Maria Gomez', 'Maria Gomez'));
    }

    public function test_matches_name_with_title_prefix(): void
    {
        $this->assertTrue($this->matcher->matches('Dr. Emily Hart', 'Emily Hart'));
    }

    public function test_matches_initial_to_full_first_name(): void
    {
        $this->assertTrue($this->matcher->matches('Sean Murphy', 'S. Murphy'));
    }

    public function test_matches_nickname_to_full_first_name(): void
    {
        $this->assertTrue($this->matcher->matches('Robert Kowalski', 'Bob Kowalski'));
    }

    public function test_does_not_match_completely_different_names(): void
    {
        $this->assertFalse($this->matcher->matches('Tina Alvarez', 'Marcus Webb'));
    }

    public function test_handles_null_gracefully(): void
    {
        $this->assertFalse($this->matcher->matches(null, 'Maria Gomez'));
        $this->assertFalse($this->matcher->matches('Maria Gomez', null));
        $this->assertFalse($this->matcher->matches(null, null));
    }
}
