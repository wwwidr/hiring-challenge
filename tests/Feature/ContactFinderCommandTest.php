<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Runs the whole slice through `php artisan contacts:find` over the real CSV +
 * fixtures, and asserts the precision-biased split the design predicts.
 */
final class ContactFinderCommandTest extends TestCase
{
    private string $output;

    protected function setUp(): void
    {
        parent::setUp();
        $this->output = storage_path('app/testing/contacts_found_test.csv');
        @unlink($this->output);
    }

    protected function tearDown(): void
    {
        @unlink($this->output);
        parent::tearDown();
    }

    public function test_command_writes_enriched_csv_for_every_company(): void
    {
        $this->artisan('contacts:find', ['--output' => $this->output])
            ->assertSuccessful();

        $this->assertFileExists($this->output);

        $rows = $this->readCsv($this->output);

        // All 30 input companies appear in the output (none silently dropped).
        $this->assertCount(30, $rows);

        $emitted = array_filter($rows, fn (array $r) => $r['needs_human_review'] === 'false');
        $review = array_filter($rows, fn (array $r) => $r['needs_human_review'] === 'true');

        // Calibrated expectation: precision over recall — most rows go to review.
        $this->assertCount(8, $emitted, '8 confident auto-emits');
        $this->assertCount(22, $review, '22 routed to human review (incl. 12 no-source rows)');
    }

    public function test_conflicting_row_is_blanked_and_flagged(): void
    {
        $this->artisan('contacts:find', ['--output' => $this->output])->assertSuccessful();

        $rows = $this->readCsv($this->output);
        $coastal = $this->findRow($rows, 'Coastal Breeze Pool Service');

        $this->assertSame('true', $coastal['needs_human_review']);
        $this->assertSame('', $coastal['contact_email_or_phone']);
        $this->assertSame('conflicting_sources', $coastal['reason']);
    }

    public function test_emitted_rows_always_carry_provenance(): void
    {
        $this->artisan('contacts:find', ['--output' => $this->output])->assertSuccessful();

        foreach ($this->readCsv($this->output) as $row) {
            if ($row['needs_human_review'] === 'false') {
                $this->assertNotSame('', $row['contact_email_or_phone'], "{$row['company_name']} emitted without a channel");
                $this->assertNotSame('', $row['source_urls'], "{$row['company_name']} emitted without provenance");
            }
        }
    }

    /** @return list<array<string,string>> */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        $this->assertNotFalse($handle);

        $header = fgetcsv($handle, escape: '');
        $rows = [];
        while (($row = fgetcsv($handle, escape: '')) !== false) {
            if ($row === [null]) {
                continue;
            }
            /** @var array<string,string> $assoc */
            $assoc = array_combine($header, $row);
            $rows[] = $assoc;
        }
        fclose($handle);

        return $rows;
    }

    /**
     * @param list<array<string,string>> $rows
     * @return array<string,string>
     */
    private function findRow(array $rows, string $company): array
    {
        foreach ($rows as $row) {
            if ($row['company_name'] === $company) {
                return $row;
            }
        }

        $this->fail("Row not found for {$company}");
    }
}
