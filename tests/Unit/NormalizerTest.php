<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ContactFinder\Normalizer;
use PHPUnit\Framework\TestCase;

final class NormalizerTest extends TestCase
{
    private Normalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new Normalizer();
    }

    public function test_parses_a_full_llc_entry(): void
    {
        $result = $this->normalizer->normalize('Cedar Ridge Plumbing LLC', '4821 Maple Ave, Lincoln, NE 68504');

        $this->assertSame('Cedar Ridge Plumbing LLC', $result->legal_name);
        $this->assertSame('Cedar Ridge Plumbing', $result->common_name);
        $this->assertSame('LLC', $result->entity_type);
        $this->assertSame('Lincoln', $result->city);
        $this->assertSame('NE', $result->state);
        $this->assertSame('68504', $result->zip);
    }

    public function test_parses_an_inc_entry(): void
    {
        $result = $this->normalizer->normalize('Pioneer Landscaping Inc', '940 Prairie View Dr, Boise, ID 83704');

        $this->assertSame('Pioneer Landscaping Inc', $result->legal_name);
        $this->assertSame('Pioneer Landscaping', $result->common_name);
        $this->assertSame('Inc', $result->entity_type);
    }

    public function test_parses_a_co_entry(): void
    {
        $result = $this->normalizer->normalize('Sunbelt Roofing Co', '7714 Desert Bloom Rd, Mesa, AZ 85207');

        $this->assertSame('Sunbelt Roofing', $result->common_name);
        $this->assertSame('Co', $result->entity_type);
    }

    public function test_handles_a_name_with_no_entity_suffix(): void
    {
        $result = $this->normalizer->normalize('Bayview Auto Repair', '129 Harbor St, Tacoma, WA 98402');

        $this->assertSame('Bayview Auto Repair', $result->legal_name);
        $this->assertSame('Bayview Auto Repair', $result->common_name);
        $this->assertNull($result->entity_type);
    }

    public function test_handles_a_dba_style_name_with_no_suffix(): void
    {
        $result = $this->normalizer->normalize('Crescent Moon Cafe', '73 Bourbon Walk, Lafayette, LA 70501');

        $this->assertNull($result->entity_type);
    }

    public function test_parses_street_address_separately_from_city_state_zip(): void
    {
        $result = $this->normalizer->normalize('Ironclad Welding Shop', '1701 Foundry Rd, Pittsburgh, PA 15201');

        $this->assertSame('1701 Foundry Rd', $result->street);
        $this->assertSame('Pittsburgh', $result->city);
        $this->assertSame('PA', $result->state);
        $this->assertSame('15201', $result->zip);
    }
}
