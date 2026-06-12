<?php

declare(strict_types=1);

namespace App\Services\ContactFinder;

final class NameMatcher
{
    /** @var array<string, string[]> */
    private const NICKNAMES = [
        'robert' => ['bob', 'bobby', 'rob'],
        'william' => ['bill', 'billy', 'will'],
        'james' => ['jim', 'jimmy'],
        'thomas' => ['tom', 'tommy'],
        'michael' => ['mike', 'mickey'],
        'david' => ['dave', 'davey'],
        'joseph' => ['joe', 'joey'],
        'richard' => ['rick', 'dick', 'rich'],
        'charles' => ['chuck', 'charlie'],
        'edward' => ['ted', 'ned', 'ed'],
    ];

    public function matches(?string $a, ?string $b): bool
    {
        if (!$a || !$b) {
            return false;
        }

        $na = $this->stripTitle($a);
        $nb = $this->stripTitle($b);

        if ($na === $nb) {
            return true;
        }

        $partsA = preg_split('/\s+/', $na);
        $partsB = preg_split('/\s+/', $nb);

        if (count($partsA) < 2 || count($partsB) < 2) {
            return false;
        }

        $firstA = str_replace('.', '', $partsA[0]);
        $lastA = $partsA[count($partsA) - 1];
        $firstB = str_replace('.', '', $partsB[0]);
        $lastB = $partsB[count($partsB) - 1];

        if ($lastA !== $lastB) {
            return false;
        }

        if ($firstA === $firstB) {
            return true;
        }

        if (strlen($firstA) === 1 && str_starts_with($firstB, $firstA)) {
            return true;
        }

        if (strlen($firstB) === 1 && str_starts_with($firstA, $firstB)) {
            return true;
        }

        foreach (self::NICKNAMES as $formal => $nicks) {
            $all = array_merge([$formal], $nicks);
            if (in_array($firstA, $all, true) && in_array($firstB, $all, true)) {
                return true;
            }
        }

        return false;
    }

    private function stripTitle(string $name): string
    {
        return strtolower(preg_replace('/^(dr|mr|mrs|ms|prof)\.?\s+/i', '', trim($name)));
    }
}
