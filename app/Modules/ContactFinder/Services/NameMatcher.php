<?php

declare(strict_types=1);

namespace App\Modules\ContactFinder\Services;

/**
 * Tolerant person-name matching, used to decide whether two sources agree on
 * the same person, conflict on different people, or whether an email belongs to
 * the resolved person.
 *
 * Pure and deterministic — no framework, no I/O. This is the trust signal that
 * lets "agreement raises confidence" work without naive exact-string matching.
 */
final class NameMatcher
{
    /** Honorifics / titles stripped before comparison. */
    private const TITLES = ['dr', 'mr', 'mrs', 'ms', 'miss', 'prof'];

    /** Mailbox local-parts that are roles/teams, never a person. */
    private const GENERIC_LOCALS = [
        'info', 'office', 'contact', 'sales', 'admin', 'hello',
        'support', 'billing', 'accounts', 'team', 'mail', 'help',
    ];

    /**
     * Tokenize a display name into lowercase word tokens, dropping titles and
     * anything parenthetical (e.g. "Jeff (manager)" -> ["jeff"]).
     *
     * @return list<string>
     */
    public function tokens(string $name): array
    {
        $name = strtolower(trim($name));
        // Drop parentheticals like "(manager)".
        $name = (string) preg_replace('/\([^)]*\)/', ' ', $name);
        // Keep letters, spaces, dots and hyphens; drop other punctuation.
        $name = (string) preg_replace('/[^a-z\s.\-]/', ' ', $name);

        $tokens = [];
        foreach (preg_split('/[\s.\-]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if (! in_array($token, self::TITLES, true)) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    /** The surname = last meaningful token, or null if none. */
    public function surname(string $name): ?string
    {
        $tokens = $this->tokens($name);

        return $tokens === [] ? null : $tokens[array_key_last($tokens)];
    }

    /**
     * Do two names plausibly refer to the same person?
     *
     * Rule: same surname, and first tokens are compatible (equal, an initial of
     * the other, or a known nickname). Same surname is the strong signal —
     * "Robert Kowalski"/"Bob Kowalski" and "Sean Murphy"/"S. Murphy" both pass;
     * "Tina Alvarez"/"Marcus Webb" does not.
     */
    public function samePerson(string $a, string $b): bool
    {
        $ta = $this->tokens($a);
        $tb = $this->tokens($b);
        if ($ta === [] || $tb === []) {
            return false;
        }

        $surnameA = $ta[array_key_last($ta)];
        $surnameB = $tb[array_key_last($tb)];
        if ($surnameA !== $surnameB) {
            return false;
        }

        // Surnames match. If either has no distinct first name, accept.
        if (count($ta) < 2 || count($tb) < 2) {
            return true;
        }

        return $this->firstNamesCompatible($ta[0], $tb[0]);
    }

    /** Two names that both exist but cannot be the same person. */
    public function conflicts(string $a, string $b): bool
    {
        if ($this->tokens($a) === [] || $this->tokens($b) === []) {
            return false; // a missing name is "cannot verify", not a conflict
        }

        return ! $this->samePerson($a, $b);
    }

    /** Is this mailbox a generic role address rather than a person's? */
    public function isGenericEmail(string $email): bool
    {
        $local = $this->localPart($email);

        return $local === '' || in_array($local, self::GENERIC_LOCALS, true);
    }

    /**
     * Does the email's local-part plausibly belong to the given person?
     * e.g. "d.ortega@..." matches "Daniel Ortega"; "karen@..." matches "Karen Liu".
     */
    public function emailMatchesName(string $email, string $name): bool
    {
        if ($this->isGenericEmail($email)) {
            return false;
        }

        $local = $this->localPart($email);
        $localTokens = preg_split('/[._\-]+/', $local, -1, PREG_SPLIT_NO_EMPTY) ?: [$local];
        $nameTokens = $this->tokens($name);
        if ($nameTokens === []) {
            return false;
        }

        foreach ($nameTokens as $nt) {
            foreach ($localTokens as $lt) {
                // full token match, or initial against a name token
                if ($lt === $nt || (strlen($lt) === 1 && str_starts_with($nt, $lt))) {
                    return true;
                }
            }
        }

        return false;
    }

    private function firstNamesCompatible(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        // Initial vs full name ("s" ~ "sean").
        if (strlen($a) === 1 && str_starts_with($b, $a)) {
            return true;
        }
        if (strlen($b) === 1 && str_starts_with($a, $b)) {
            return true;
        }

        return $this->areNicknames($a, $b);
    }

    private function areNicknames(string $a, string $b): bool
    {
        $pairs = [
            ['robert', 'bob'], ['william', 'bill'], ['richard', 'rick'],
            ['james', 'jim'], ['michael', 'mike'], ['thomas', 'tom'],
            ['charles', 'charlie'], ['joseph', 'joe'], ['daniel', 'dan'],
            ['edward', 'ed'], ['anthony', 'tony'], ['margaret', 'maggie'],
        ];

        foreach ($pairs as [$full, $nick]) {
            if (($a === $full && $b === $nick) || ($a === $nick && $b === $full)) {
                return true;
            }
        }

        return false;
    }

    private function localPart(string $email): string
    {
        $email = strtolower(trim($email));
        $at = strpos($email, '@');

        return $at === false ? $email : substr($email, 0, $at);
    }
}
