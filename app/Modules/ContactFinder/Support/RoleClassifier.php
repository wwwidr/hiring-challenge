<?php

namespace App\Modules\ContactFinder\Support;

/**
 * Maps a free-text role to the decision-maker persona priority defined in
 * CLARIFICATIONS.md for debt collection:
 *
 *   accounts payable  >  owner / founder  >  CFO / finance  >  office manager
 *
 * A registered agent is explicitly weak: it is often a lawyer or formation
 * service, not the person who pays the bill.
 */
class RoleClassifier
{
    public const AP = 'accounts_payable';

    public const OWNER = 'owner';

    public const FINANCE = 'finance';

    public const MANAGER = 'manager';

    public const REGISTERED_AGENT = 'registered_agent';

    public const UNKNOWN = 'unknown';

    /** Lower rank = higher priority target. */
    private const RANK = [
        self::AP => 1,
        self::OWNER => 2,
        self::FINANCE => 3,
        self::MANAGER => 4,
        self::REGISTERED_AGENT => 8,
        self::UNKNOWN => 9,
    ];

    /** Scoring contribution per persona (precision-first role fit). */
    private const POINTS = [
        self::AP => 15,
        self::OWNER => 12,
        self::FINANCE => 12,
        self::MANAGER => 8,
        self::REGISTERED_AGENT => 2,
        self::UNKNOWN => 0,
    ];

    private const LABELS = [
        self::AP => 'Accounts Payable',
        self::OWNER => 'Owner',
        self::FINANCE => 'Finance',
        self::MANAGER => 'Manager',
        self::REGISTERED_AGENT => 'Registered Agent',
        self::UNKNOWN => 'Unknown',
    ];

    public function classify(?string $role): string
    {
        if ($role === null) {
            return self::UNKNOWN;
        }
        $r = strtolower($role);

        return match (true) {
            str_contains($r, 'accounts payable'), str_contains($r, 'a/p'), str_contains($r, 'billing'), str_contains($r, 'accounts receivable') => self::AP,
            str_contains($r, 'owner'), str_contains($r, 'founder'), str_contains($r, 'president'), str_contains($r, 'principal'), str_contains($r, 'proprietor'), str_contains($r, 'ceo') => self::OWNER,
            str_contains($r, 'cfo'), str_contains($r, 'finance'), str_contains($r, 'controller'), str_contains($r, 'treasurer') => self::FINANCE,
            str_contains($r, 'registered agent'), $r === 'agent' => self::REGISTERED_AGENT,
            str_contains($r, 'manager'), str_contains($r, 'office'), str_contains($r, 'gm') => self::MANAGER,
            default => self::UNKNOWN,
        };
    }

    public function rank(?string $role): int
    {
        return self::RANK[$this->classify($role)];
    }

    public function points(?string $role): int
    {
        return self::POINTS[$this->classify($role)];
    }

    public function isRegisteredAgent(?string $role): bool
    {
        return $this->classify($role) === self::REGISTERED_AGENT;
    }

    /** Human-friendly label; preserves the original text when persona is unknown. */
    public function label(?string $role): string
    {
        $persona = $this->classify($role);
        if ($persona === self::UNKNOWN) {
            return $role !== null ? trim($role) : '';
        }

        return self::LABELS[$persona];
    }
}
