<?php

namespace App\Modules\ContactFinder\Support;

/**
 * Normalizes and compares person names so that the same human reported by two
 * providers in different forms (e.g. "Robert Kowalski" vs "Bob Kowalski", or
 * "Sean Murphy" vs "S. Murphy") is recognized as agreement rather than a
 * conflict. This is the backbone of cross-source corroboration.
 */
class NameNormalizer
{
    private const TITLES = ['dr', 'mr', 'mrs', 'ms', 'miss', 'prof', 'sir', 'rev'];

    /** Common nickname -> canonical given name (compared canonically). */
    private const NICKNAMES = [
        'bob' => 'robert', 'rob' => 'robert', 'robbie' => 'robert',
        'bill' => 'william', 'will' => 'william', 'billy' => 'william',
        'mike' => 'michael', 'mikey' => 'michael',
        'jim' => 'james', 'jimmy' => 'james',
        'tony' => 'anthony',
        'dan' => 'daniel', 'danny' => 'daniel',
        'dave' => 'david',
        'chris' => 'christopher',
        'joe' => 'joseph',
        'ed' => 'edward', 'eddie' => 'edward',
        'ken' => 'kenneth',
        'rick' => 'richard', 'rich' => 'richard', 'dick' => 'richard',
        'steve' => 'steven',
        'sam' => 'samuel',
        'kate' => 'katherine', 'katie' => 'katherine',
        'beth' => 'elizabeth', 'liz' => 'elizabeth',
        'peggy' => 'margaret', 'meg' => 'margaret',
        'sue' => 'susan',
        'tom' => 'thomas', 'tommy' => 'thomas',
        'jeff' => 'jeffrey',
        'greg' => 'gregory',
        'andy' => 'andrew',
        'matt' => 'matthew',
        'nick' => 'nicholas',
        'pat' => 'patrick',
        'ron' => 'ronald',
        'don' => 'donald',
        'pete' => 'peter',
        'fred' => 'frederick',
        'charlie' => 'charles', 'chuck' => 'charles',
    ];

    /** Strip titles and parenthetical tags; collapse whitespace. */
    public function clean(string $name): string
    {
        $name = preg_replace('/\([^)]*\)/', ' ', $name) ?? $name;
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $kept = [];
        foreach ($parts as $part) {
            $bare = strtolower(rtrim($part, '.'));
            if ($bare === '' || in_array($bare, self::TITLES, true)) {
                continue;
            }
            $kept[] = $part;
        }

        return trim(implode(' ', $kept));
    }

    public function isRealPersonName(?string $name): bool
    {
        return $name !== null && $this->clean($name) !== '';
    }

    /** Partial = a single token, or contains an initial, or carried a tag. */
    public function isPartial(string $name): bool
    {
        $hadTag = str_contains($name, '(');
        $clean = $this->clean($name);
        if ($clean === '') {
            return true;
        }
        $parts = preg_split('/\s+/', $clean) ?: [];
        if (count($parts) < 2) {
            return true;
        }
        foreach ($parts as $part) {
            if (strlen(rtrim($part, '.')) <= 1) {
                return true;
            }
        }

        return $hadTag;
    }

    /** Text found inside parentheses, e.g. "Jeff (manager)" -> "manager". */
    public function parentheticalRole(?string $name): ?string
    {
        if ($name !== null && preg_match('/\(([^)]+)\)/', $name, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    /**
     * Lowercased canonical tokens (nickname-expanded). Accepts a name or an
     * email local part that has already had separators turned into spaces.
     *
     * @return string[]
     */
    public function tokens(string $value): array
    {
        $clean = strtolower($this->clean($value));
        if ($clean === '') {
            return [];
        }
        $parts = preg_split('/\s+/', $clean) ?: [];

        return array_map(fn ($p) => $this->canonical(rtrim($p, '.')), $parts);
    }

    private function canonical(string $token): string
    {
        return self::NICKNAMES[$token] ?? $token;
    }

    /** Do two names plausibly refer to the same person? */
    public function matches(string $a, string $b): bool
    {
        $ta = $this->tokens($a);
        $tb = $this->tokens($b);
        if ($ta === [] || $tb === []) {
            return false;
        }

        // Single-token name (e.g. just a surname): match if the token appears
        // in the other name, or matches its first initial.
        if (count($ta) === 1 || count($tb) === 1) {
            $single = count($ta) === 1 ? $ta[0] : $tb[0];
            $other = count($ta) === 1 ? $tb : $ta;

            return in_array($single, $other, true) || $this->initialMatch($single, $other[0]);
        }

        // Multi-token: surnames must align, then first names by equality or initial.
        if (end($ta) !== end($tb)) {
            return false;
        }
        $firstA = $ta[0];
        $firstB = $tb[0];

        return $firstA === $firstB
            || $this->initialMatch($firstA, $firstB)
            || $this->initialMatch($firstB, $firstA);
    }

    private function initialMatch(string $maybeInitial, string $full): bool
    {
        return strlen($maybeInitial) === 1 && $full !== '' && str_starts_with($full, $maybeInitial);
    }
}
