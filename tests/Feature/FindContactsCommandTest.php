<?php

namespace Tests\Feature;

use Tests\TestCase;

class FindContactsCommandTest extends TestCase
{
    private string $workdir;

    private string $inputCsv;

    private string $outJson;

    private string $outCsv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workdir = storage_path('app/testing/contact-finder-'.uniqid());
        @mkdir($this->workdir, 0775, true);

        $this->inputCsv = $this->workdir.'/companies.csv';
        $this->outJson = $this->workdir.'/contacts.json';
        $this->outCsv = $this->workdir.'/contacts.csv';

        file_put_contents($this->inputCsv, implode("\n", [
            'company_name,mailing_address',
            'Pioneer Landscaping Inc,"940 Prairie View Dr, Boise, ID 83704"',
            'Riverside Print & Sign,"302 W 3rd St, Davenport, IA 52801"',
            'Redwood Cabinetry,"509 Timber Ct, Eugene, OR 97401"',
        ])."\n");
    }

    protected function tearDown(): void
    {
        foreach ([$this->inputCsv, $this->outJson, $this->outCsv] as $file) {
            @unlink($file);
        }
        @rmdir($this->workdir);

        parent::tearDown();
    }

    public function test_command_runs_and_writes_outputs(): void
    {
        $this->artisan('contacts:find', [
            '--input' => $this->inputCsv,
            '--mocks' => base_path('challenge/mocks/enrichment_responses.json'),
            '--out' => $this->outJson,
            '--csv' => $this->outCsv,
        ])->assertExitCode(0);

        $this->assertFileExists($this->outJson);
        $this->assertFileExists($this->outCsv);

        $payload = json_decode((string) file_get_contents($this->outJson), true);
        $this->assertSame(70, $payload['threshold']);
        $this->assertSame(3, $payload['total']);
        $this->assertSame(1, $payload['emitted']);
    }

    public function test_high_confidence_row_is_emitted_with_provenance(): void
    {
        $this->artisan('contacts:find', [
            '--input' => $this->inputCsv,
            '--mocks' => base_path('challenge/mocks/enrichment_responses.json'),
            '--out' => $this->outJson,
            '--csv' => $this->outCsv,
        ])->assertExitCode(0);

        $rows = collect(json_decode((string) file_get_contents($this->outJson), true)['results'])
            ->keyBy('company_name');

        $pioneer = $rows['Pioneer Landscaping Inc'];
        $this->assertFalse($pioneer['needs_human_review']);
        $this->assertSame('maria@pioneerlandscaping.com', $pioneer['contact_email_or_phone']);
        $this->assertNotEmpty($pioneer['source_urls']);
    }

    public function test_low_confidence_and_missing_rows_are_flagged_without_fabrication(): void
    {
        $this->artisan('contacts:find', [
            '--input' => $this->inputCsv,
            '--mocks' => base_path('challenge/mocks/enrichment_responses.json'),
            '--out' => $this->outJson,
            '--csv' => $this->outCsv,
        ])->assertExitCode(0);

        $rows = collect(json_decode((string) file_get_contents($this->outJson), true)['results'])
            ->keyBy('company_name');

        // Weak lone-enrichment guess -> review, empty contact.
        $riverside = $rows['Riverside Print & Sign'];
        $this->assertTrue($riverside['needs_human_review']);
        $this->assertSame('', $riverside['contact_email_or_phone']);

        // No source at all -> cannot-verify, empty contact.
        $redwood = $rows['Redwood Cabinetry'];
        $this->assertTrue($redwood['needs_human_review']);
        $this->assertSame('', $redwood['contact_email_or_phone']);
        $this->assertSame(0, $redwood['confidence_score']);
    }
}
