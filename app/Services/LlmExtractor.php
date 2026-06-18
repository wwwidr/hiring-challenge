<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class LlmExtractor
{
    /**
     * Use the LLM as a fuzzy extraction layer ONLY.
     * - Never calculate values here.
     * - Never merge or score here.
     * - Always validate output against schema before returning.
     */
    public static function extract(array $snippets): array
    {
        if (empty($snippets)) {
            return self::emptyResult();
        }

        $prompt = self::buildPrompt($snippets);
        $raw    = self::callLlm($prompt);

        return self::validateAndParse($raw);
    }

    // -------------------------------------------------------------------------

    private static function buildPrompt(array $snippets): string
    {
        $text = implode("\n\n", $snippets);

        return <<<PROMPT
        You are a data extraction assistant. Extract contact information from the text below.

        Return ONLY valid JSON matching this exact schema — no preamble, no markdown:
        {
          "ceo_name":         string | null,
          "ceo_email":        string | null,
          "finance_contact":  { "name": string | null, "email": string | null } | null,
          "ops_contact":      { "name": string | null, "email": string | null } | null
        }

        If a field cannot be found in the text, set it to null. Do NOT invent values.

        TEXT:
        {$text}
        PROMPT;
    }

    private static function callLlm(string $prompt): string
    {
        // In production: call Claude API.
        // For the challenge slice: return a deterministic mock response
        // so tests run without real API keys.
        return json_encode([
            'ceo_name'        => 'Jean-Pierre Dupont',
            'ceo_email'       => null,
            'finance_contact' => ['name' => null, 'email' => 'j.martin@dupont-industries.fr'],
            'ops_contact'     => ['name' => 'Marie Lefevre', 'email' => null],
        ]);
    }

    private static function validateAndParse(string $raw): array
    {
        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('LlmExtractor: invalid JSON from LLM', ['raw' => substr($raw, 0, 200)]);
            return self::emptyResult();
        }

        $requiredKeys = ['ceo_name', 'ceo_email', 'finance_contact', 'ops_contact'];
        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $decoded)) {
                Log::warning("LlmExtractor: missing key [{$key}] in LLM response");
                return self::emptyResult();
            }
        }

        return $decoded;
    }

    private static function emptyResult(): array
    {
        return [
            'ceo_name'        => null,
            'ceo_email'       => null,
            'finance_contact' => null,
            'ops_contact'     => null,
        ];
    }
}
