<?php

namespace Tests\Unit\Modules\ContactFinder;

use App\Modules\ContactFinder\Services\CsvSanitizer;
use PHPUnit\Framework\TestCase;

class CsvSanitizerTest extends TestCase
{
    private CsvSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new CsvSanitizer();
    }

    public function test_strips_leading_equals_sign(): void
    {
        $this->assertEquals('CMD("malicious")', $this->sanitizer->sanitizeField('=CMD("malicious")'));
    }

    public function test_strips_leading_plus_sign(): void
    {
        $this->assertEquals('CMD("malicious")', $this->sanitizer->sanitizeField('+CMD("malicious")'));
    }

    public function test_strips_leading_at_sign(): void
    {
        $this->assertEquals('SUM(A1:A10)', $this->sanitizer->sanitizeField('@SUM(A1:A10)'));
    }

    public function test_strips_multiple_leading_injection_chars(): void
    {
        $this->assertEquals('test', $this->sanitizer->sanitizeField('=+@test'));
    }

    public function test_preserves_normal_company_names(): void
    {
        $this->assertEquals('Cedar Ridge Plumbing LLC', $this->sanitizer->sanitizeField('Cedar Ridge Plumbing LLC'));
    }

    public function test_trims_whitespace(): void
    {
        $this->assertEquals('Test Company', $this->sanitizer->sanitizeField('  Test Company  '));
    }

    public function test_handles_empty_string(): void
    {
        $this->assertEquals('', $this->sanitizer->sanitizeField(''));
    }

    public function test_preserves_addresses_with_hyphens_in_middle(): void
    {
        $this->assertEquals('4821 Maple Ave, Lincoln, NE 68504', $this->sanitizer->sanitizeField('4821 Maple Ave, Lincoln, NE 68504'));
    }

    public function test_validate_row_count_accepts_valid_csv(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'csv_test');
        file_put_contents($tempFile, "header\nrow1\nrow2\nrow3\n");

        $this->sanitizer->validateRowCount($tempFile);
        $this->assertTrue(true);

        unlink($tempFile);
    }

    public function test_validate_row_count_rejects_oversized_csv(): void
    {
        $smallSanitizer = new CsvSanitizer(maxRows: 2);
        $tempFile = tempnam(sys_get_temp_dir(), 'csv_test');
        file_put_contents($tempFile, "header\nrow1\nrow2\nrow3\nrow4\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exceeds maximum allowed rows');
        $smallSanitizer->validateRowCount($tempFile);

        unlink($tempFile);
    }
}
