<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\ContactFinder;

use App\Modules\ContactFinder\Services\NameMatcher;
use PHPUnit\Framework\TestCase;

final class NameMatcherTest extends TestCase
{
    private NameMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new NameMatcher();
    }

    public function test_strips_titles_and_parentheticals(): void
    {
        $this->assertSame(['emily', 'hart'], $this->matcher->tokens('Dr. Emily Hart'));
        $this->assertSame(['jeff'], $this->matcher->tokens('Jeff (manager)'));
    }

    public function test_same_person_matches_exact_surname(): void
    {
        $this->assertTrue($this->matcher->samePerson('Daniel Ortega', 'Daniel Ortega'));
    }

    public function test_same_person_matches_initial(): void
    {
        // "Sean Murphy" vs "S. Murphy"
        $this->assertTrue($this->matcher->samePerson('Sean Murphy', 'S. Murphy'));
    }

    public function test_same_person_matches_nickname(): void
    {
        // "Robert Kowalski" vs "Bob Kowalski"
        $this->assertTrue($this->matcher->samePerson('Robert Kowalski', 'Bob Kowalski'));
    }

    public function test_different_people_conflict(): void
    {
        // The Coastal Breeze case: registry vs listing name a different person.
        $this->assertTrue($this->matcher->conflicts('Tina Alvarez', 'Marcus Webb'));
        $this->assertFalse($this->matcher->samePerson('Tina Alvarez', 'Marcus Webb'));
    }

    public function test_missing_name_is_not_a_conflict(): void
    {
        $this->assertFalse($this->matcher->conflicts('Tina Alvarez', ''));
    }

    public function test_email_matches_person(): void
    {
        $this->assertTrue($this->matcher->emailMatchesName('d.ortega@cedarridgeplumbing.com', 'Daniel Ortega'));
        $this->assertTrue($this->matcher->emailMatchesName('karen@bayviewauto.com', 'Karen Liu'));
        $this->assertTrue($this->matcher->emailMatchesName('emily.hart@brooksidevet.com', 'Dr. Emily Hart'));
    }

    public function test_generic_mailboxes_match_no_one(): void
    {
        $this->assertTrue($this->matcher->isGenericEmail('info@riversideprint.biz'));
        $this->assertTrue($this->matcher->isGenericEmail('office@sunbeltroofingaz.com'));
        $this->assertFalse($this->matcher->emailMatchesName('info@riversideprint.biz', 'Daniel Ortega'));
    }
}
