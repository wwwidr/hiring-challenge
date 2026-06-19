<?php

namespace App\Modules\ContactFinder\Services;

class CsvSanitizer
{
    private const CSV_INJECTION_CHARS = ['=', '+', '-', '@', "\t", "\r", "\n"];
    private const DEFAULT_MAX_ROWS = 10000;

    public function __construct(
        private readonly int $maxRows = self::DEFAULT_MAX_ROWS,
    ) {}

    public function sanitizeField(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return $value;
        }

        while (strlen($value) > 0 && in_array($value[0], self::CSV_INJECTION_CHARS, true)) {
            $value = substr($value, 1);
        }

        $value = preg_replace('/[^\P{C}\t\n\r]/u', '', $value);

        return trim($value);
    }

    public function validateRowCount(string $csvPath): void
    {
        $lineCount = 0;
        $handle = fopen($csvPath, 'r');

        if ($handle === false) {
            throw new \RuntimeException("Cannot open CSV file: {$csvPath}");
        }

        while (fgets($handle) !== false) {
            $lineCount++;

            if ($lineCount > $this->maxRows + 1) {
                fclose($handle);
                throw new \RuntimeException(
                    "CSV file exceeds maximum allowed rows ({$this->maxRows}). "
                    . 'Process in smaller batches or increase the limit.'
                );
            }
        }

        fclose($handle);
    }
}
