<?php

namespace App\Modules\ContactFinder\Services;

use App\Modules\ContactFinder\Enums\VerificationStatus;
use App\Modules\ContactFinder\ValueObjects\ProvenanceField;
use App\Modules\ContactFinder\ValueObjects\ProviderResult;
use App\Modules\ContactFinder\ValueObjects\ScoredContact;

class ContactMerger
{
    public function __construct(
        private readonly ConfidenceCalculator $confidenceCalculator,
        private readonly ContactValidator $contactValidator,
    ) {}

    /**
     * @param ProviderResult[] $results
     */
    public function merge(string $companyName, array $results, ?string $regulatedIndustry = null): ScoredContact
    {
        $validResults = array_filter($results, fn (?ProviderResult $result) => $result !== null);

        if (empty($validResults)) {
            return $this->buildNotFoundContact($companyName, $regulatedIndustry);
        }

        $mergedContact = $this->mergeContactFields($validResults);
        $this->validateContactFields($mergedContact);
        $components = $this->confidenceCalculator->calculateComponents($validResults, $mergedContact);
        $confidenceScore = $components['final_score'];
        $verificationStatus = $this->determineVerificationStatus($validResults, $confidenceScore);
        $threshold = config('enrichment.threshold', 70);

        $needsHumanReview = $verificationStatus !== VerificationStatus::VERIFIED;
        $explanation = $this->buildStatusExplanation($validResults, $mergedContact, $components, $confidenceScore, $verificationStatus);

        if ($confidenceScore < $threshold) {
            return $this->buildReviewContact($companyName, $mergedContact, $confidenceScore, $verificationStatus, $validResults, $components, $explanation, $regulatedIndustry);
        }

        $provenance = $this->buildProvenance($mergedContact, $validResults);
        $sourceList = $this->buildSourceList($validResults);

        return new ScoredContact(
            companyName: $companyName,
            contactName: $mergedContact['name'],
            contactRole: $mergedContact['role'],
            contactEmail: $mergedContact['email'],
            contactPhone: $mergedContact['phone'],
            confidenceScore: $confidenceScore,
            verificationStatus: $verificationStatus,
            needsHumanReview: $needsHumanReview,
            source: $sourceList,
            provenance: $provenance,
            explanation: $explanation,
            componentScores: $components,
            allRoles: $mergedContact['all_roles'],
            regulatedIndustry: $regulatedIndustry,
        );
    }

    /**
     * @param ProviderResult[] $results
     * @return array{name: string, role: string, email: string, phone: string, all_roles: array<string, string>}
     */
    private function mergeContactFields(array $results): array
    {
        $bestName = $this->pickBestName($results);
        $bestRole = $this->pickBestRole($results);
        $bestEmail = $this->pickBestEmail($results);
        $bestPhone = $this->pickBestPhone($results);
        $allRoles = $this->collectAllRoles($results);

        return [
            'name' => $bestName,
            'role' => $bestRole,
            'email' => $bestEmail,
            'phone' => $bestPhone,
            'all_roles' => $allRoles,
        ];
    }

    /**
     * @param ProviderResult[] $results
     */
    private function pickBestName(array $results): string
    {
        $namedResults = array_filter($results, fn (ProviderResult $result) => $result->hasName());

        if (empty($namedResults)) {
            return '';
        }

        usort($namedResults, fn (ProviderResult $resultA, ProviderResult $resultB) =>
            $resultB->authorityWeight <=> $resultA->authorityWeight
        );

        $bestResult = reset($namedResults);

        return $this->cleanName($bestResult->name);
    }

    /**
     * @param ProviderResult[] $results
     */
    private function pickBestRole(array $results): string
    {
        $rolePriority = config('enrichment.role_priority', []);
        $roledResults = array_filter($results, fn (ProviderResult $result) =>
            $result->role !== null && $result->role !== ''
        );

        if (empty($roledResults)) {
            return '';
        }

        usort($roledResults, function (ProviderResult $resultA, ProviderResult $resultB) use ($rolePriority) {
            $priorityA = $rolePriority[$resultA->role] ?? 99;
            $priorityB = $rolePriority[$resultB->role] ?? 99;

            return $priorityA <=> $priorityB;
        });

        return reset($roledResults)->role;
    }

    /**
     * @param ProviderResult[] $results
     */
    private function pickBestEmail(array $results): string
    {
        $emailResults = array_filter($results, fn (ProviderResult $result) => $result->hasEmail());

        if (empty($emailResults)) {
            return '';
        }

        $nonGenericEmails = array_filter($emailResults, fn (ProviderResult $result) =>
            !$this->confidenceCalculator->isGenericEmail($result->email)
        );

        if (!empty($nonGenericEmails)) {
            usort($nonGenericEmails, fn (ProviderResult $resultA, ProviderResult $resultB) =>
                ($resultB->providerConfidence ?? 0) <=> ($resultA->providerConfidence ?? 0)
            );

            return reset($nonGenericEmails)->email;
        }

        return reset($emailResults)->email;
    }

    /**
     * @param ProviderResult[] $results
     */
    private function pickBestPhone(array $results): string
    {
        $phoneResults = array_filter($results, fn (ProviderResult $result) => $result->hasPhone());

        if (empty($phoneResults)) {
            return '';
        }

        usort($phoneResults, fn (ProviderResult $resultA, ProviderResult $resultB) =>
            $resultB->authorityWeight <=> $resultA->authorityWeight
        );

        return reset($phoneResults)->phone;
    }

    /**
     * Verification status per PLAN.md:
     * - verified: confidence >= 70 AND 2+ agreeing named sources
     * - conflicting: 2+ named sources that disagree
     * - unverified: single source or no named sources (always needs review)
     *
     * @param ProviderResult[] $results
     */
    private function determineVerificationStatus(array $results, int $confidenceScore): VerificationStatus
    {
        $namedResults = array_filter($results, fn (ProviderResult $result) => $result->hasName());
        $threshold = config('enrichment.threshold', 70);

        if (count($namedResults) < 2) {
            return VerificationStatus::UNVERIFIED;
        }

        $normalizedNames = array_values(array_map(
            fn (ProviderResult $result) => $this->confidenceCalculator->normalizeNameForComparison($result->name),
            $namedResults,
        ));

        $agreementScore = $this->confidenceCalculator->calculateAgreement($namedResults);

        if ($agreementScore === 0.0) {
            return VerificationStatus::CONFLICTING;
        }

        if ($confidenceScore >= $threshold) {
            return VerificationStatus::VERIFIED;
        }

        return VerificationStatus::UNVERIFIED;
    }

    /**
     * @param array{name: string, role: string, email: string, phone: string} $mergedContact
     * @param ProviderResult[] $results
     */
    private function buildProvenance(array $mergedContact, array $results): array
    {
        $provenance = [];

        foreach (['name', 'role', 'email', 'phone'] as $field) {
            if (empty($mergedContact[$field])) {
                continue;
            }

            $sources = [];
            foreach ($results as $result) {
                $resultValue = match ($field) {
                    'name' => $result->name,
                    'role' => $result->role,
                    'email' => $result->email,
                    'phone' => $result->phone,
                };

                if ($resultValue !== null && $resultValue !== '') {
                    $sources[] = [
                        'provider' => $result->providerName,
                        'source_url' => $result->sourceUrl,
                    ];
                }
            }

            if (!empty($sources)) {
                $provenance[$field] = [
                    'value' => $mergedContact[$field],
                    'sources' => $sources,
                ];
            }
        }

        return $provenance;
    }

    /**
     * Reject personal emails (gmail, yahoo, etc.) — B2B contacts only.
     *
     * @param array{name: string, role: string, email: string, phone: string} $mergedContact
     */
    private function validateContactFields(array &$mergedContact): void
    {
        if (!empty($mergedContact['email']) && $this->contactValidator->isPersonalEmail($mergedContact['email'])) {
            $mergedContact['email'] = '';
        }
    }

    /**
     * @param ProviderResult[] $results
     */
    private function buildSourceList(array $results): string
    {
        return implode(', ', array_map(
            fn (ProviderResult $result) => $result->sourceUrl ?? $result->providerName,
            $results,
        ));
    }

    private function buildNotFoundContact(string $companyName, ?string $regulatedIndustry = null): ScoredContact
    {
        $emptyComponents = ['agreement' => 0.0, 'authority' => 0.0, 'completeness' => 0.0, 'recency' => 0.0, 'final_score' => 0];

        return new ScoredContact(
            companyName: $companyName,
            contactName: '',
            contactRole: '',
            contactEmail: '',
            contactPhone: '',
            confidenceScore: 0,
            verificationStatus: VerificationStatus::NOT_FOUND,
            needsHumanReview: true,
            source: '',
            provenance: [],
            explanation: 'Status: not found. No provider returned data for this company. Manual research required.',
            componentScores: $emptyComponents,
            regulatedIndustry: $regulatedIndustry,
        );
    }

    /**
     * @param array{name: string, role: string, email: string, phone: string, all_roles: array<string, string>} $mergedContact
     * @param ProviderResult[] $results
     * @param array{agreement: float, authority: float, completeness: float, recency: float, final_score: int} $componentScores
     */
    private function buildReviewContact(
        string $companyName,
        array $mergedContact,
        int $confidenceScore,
        VerificationStatus $verificationStatus,
        array $results,
        array $componentScores,
        string $explanation,
        ?string $regulatedIndustry = null,
    ): ScoredContact {
        $sourceList = $this->buildSourceList($results);
        $provenance = $this->buildProvenance($mergedContact, $results);

        return new ScoredContact(
            companyName: $companyName,
            contactName: $mergedContact['name'],
            contactRole: $mergedContact['role'],
            contactEmail: $mergedContact['email'],
            contactPhone: $mergedContact['phone'],
            confidenceScore: $confidenceScore,
            verificationStatus: $verificationStatus,
            needsHumanReview: true,
            source: $sourceList,
            provenance: $provenance,
            explanation: $explanation,
            componentScores: $componentScores,
            allRoles: $mergedContact['all_roles'],
            regulatedIndustry: $regulatedIndustry,
        );
    }

    /**
     * Collect all roles from providers with their source, preserving priority order.
     *
     * @param ProviderResult[] $results
     * @return array<string, string> role => provider name
     */
    private function collectAllRoles(array $results): array
    {
        $rolePriority = config('enrichment.role_priority', []);
        $roles = [];

        foreach ($results as $result) {
            if ($result->role !== null && $result->role !== '') {
                $roles[$result->role] = $result->providerName;
            }
        }

        uksort($roles, function (string $roleA, string $roleB) use ($rolePriority) {
            return ($rolePriority[$roleA] ?? 99) <=> ($rolePriority[$roleB] ?? 99);
        });

        return $roles;
    }

    /**
     * Build a human-readable explanation for the result status.
     *
     * @param ProviderResult[] $results
     * @param array{name: string, role: string, email: string, phone: string, all_roles: array<string, string>} $mergedContact
     * @param array{agreement: float, authority: float, completeness: float, recency: float, final_score: int} $components
     */
    private function buildStatusExplanation(
        array $results,
        array $mergedContact,
        array $components,
        int $confidenceScore,
        VerificationStatus $verificationStatus,
    ): string {
        $parts = [];
        $sourceCount = count($results);
        $namedSources = array_filter($results, fn (ProviderResult $result) => $result->hasName());
        $namedCount = count($namedSources);
        $threshold = config('enrichment.threshold', 70);

        $providerNames = implode(', ', array_map(fn (ProviderResult $result) => $result->providerName, $results));
        $parts[] = "{$sourceCount} provider(s) queried: {$providerNames}.";

        if ($namedCount >= 2) {
            $names = array_map(fn (ProviderResult $result) => "\"{$result->name}\" ({$result->providerName})", $namedSources);
            $nameList = implode(', ', $names);

            if ($components['agreement'] === 1.0) {
                $parts[] = "Names agree across {$namedCount} sources: {$nameList}.";
            } else {
                $parts[] = "Names conflict across sources: {$nameList}.";
            }
        } elseif ($namedCount === 1) {
            $singleNamed = reset($namedSources);
            $parts[] = "Only 1 source provided a name: \"{$singleNamed->name}\" ({$singleNamed->providerName}). Cannot independently verify.";
        } else {
            $parts[] = "No source returned a contact name.";
        }

        if (count($mergedContact['all_roles']) > 1) {
            $roleParts = array_map(
                fn (string $role, string $provider) => "{$role} ({$provider})",
                array_keys($mergedContact['all_roles']),
                array_values($mergedContact['all_roles']),
            );
            $parts[] = "Multiple roles found: " . implode(', ', $roleParts) . ". Selected \"{$mergedContact['role']}\" by priority (AP Mgr > Owner > CFO > Office Mgr > Registered Agent).";
        } elseif (!empty($mergedContact['role'])) {
            $roleProvider = $mergedContact['all_roles'][$mergedContact['role']] ?? 'unknown';
            $parts[] = "Role \"{$mergedContact['role']}\" from {$roleProvider}.";
        }

        if (!empty($mergedContact['email']) && $this->confidenceCalculator->isGenericEmail($mergedContact['email'])) {
            $parts[] = "Email \"{$mergedContact['email']}\" is generic (info@/contact@), reducing completeness score.";
        }

        $scoreBreakdown = sprintf(
            'Score: %d = (%.2f agreement x %.2f + %.2f authority x %.2f + %.2f completeness x %.2f + %.2f recency x %.2f) x 100.',
            $confidenceScore,
            $components['agreement'], config('enrichment.weights.agreement'),
            $components['authority'], config('enrichment.weights.authority'),
            $components['completeness'], config('enrichment.weights.completeness'),
            $components['recency'], config('enrichment.weights.recency'),
        );
        $parts[] = $scoreBreakdown;

        $statusExplanation = match ($verificationStatus) {
            VerificationStatus::VERIFIED => "Status: verified (score {$confidenceScore} >= {$threshold} and {$namedCount}+ agreeing sources).",
            VerificationStatus::CONFLICTING => "Status: conflicting (sources disagree on contact name). Human must resolve which contact is correct.",
            VerificationStatus::UNVERIFIED => $confidenceScore >= $threshold
                ? "Status: unverified (score {$confidenceScore} >= {$threshold} but only {$namedCount} named source). Single-source results always require human review."
                : "Status: unverified (score {$confidenceScore} < {$threshold}). Insufficient confidence for automated use.",
            VerificationStatus::NOT_FOUND => "Status: not found. No provider returned data for this company. Manual research required.",
        };
        $parts[] = $statusExplanation;

        return implode(' ', $parts);
    }

    private function cleanName(?string $name): string
    {
        if ($name === null || $name === '') {
            return '';
        }

        $name = preg_replace('/\(.*?\)/', '', $name);

        return trim($name);
    }
}
