<?php

namespace App\Modules\ContactFinder\Services;

use App\Modules\ContactFinder\Enums\CircuitState;
use Illuminate\Support\Facades\Log;

class CircuitBreaker
{
    /** @var array<string, array{state: CircuitState, failureCount: int, lastFailure: float, lastProbe: float}> */
    private array $circuits = [];

    public function __construct(
        private readonly int $failureThreshold = 5,
        private readonly int $cooldownSeconds = 60,
    ) {}

    public function isAvailable(string $providerName): bool
    {
        $circuit = $this->getCircuit($providerName);

        if ($circuit['state'] === CircuitState::CLOSED) {
            return true;
        }

        if ($circuit['state'] === CircuitState::OPEN) {
            $elapsed = microtime(true) - $circuit['lastFailure'];

            if ($elapsed >= $this->cooldownSeconds) {
                $this->transitionTo($providerName, CircuitState::HALF_OPEN);

                return true;
            }

            return false;
        }

        return true;
    }

    public function recordSuccess(string $providerName): void
    {
        $circuit = $this->getCircuit($providerName);

        if ($circuit['state'] === CircuitState::HALF_OPEN) {
            Log::info('Circuit breaker recovered', ['provider' => $providerName]);
        }

        $this->circuits[$providerName] = [
            'state' => CircuitState::CLOSED,
            'failureCount' => 0,
            'lastFailure' => 0.0,
            'lastProbe' => 0.0,
        ];
    }

    public function recordFailure(string $providerName): void
    {
        $circuit = $this->getCircuit($providerName);
        $circuit['failureCount']++;
        $circuit['lastFailure'] = microtime(true);

        if ($circuit['state'] === CircuitState::HALF_OPEN) {
            $circuit['state'] = CircuitState::OPEN;
            Log::warning('Circuit breaker re-opened after probe failure', ['provider' => $providerName]);
        } elseif ($circuit['failureCount'] >= $this->failureThreshold) {
            $circuit['state'] = CircuitState::OPEN;
            Log::warning('Circuit breaker opened', [
                'provider' => $providerName,
                'failures' => $circuit['failureCount'],
            ]);
        }

        $this->circuits[$providerName] = $circuit;
    }

    public function getState(string $providerName): CircuitState
    {
        return $this->getCircuit($providerName)['state'];
    }

    /**
     * @return array{state: CircuitState, failureCount: int, lastFailure: float, lastProbe: float}
     */
    private function getCircuit(string $providerName): array
    {
        return $this->circuits[$providerName] ?? [
            'state' => CircuitState::CLOSED,
            'failureCount' => 0,
            'lastFailure' => 0.0,
            'lastProbe' => 0.0,
        ];
    }

    private function transitionTo(string $providerName, CircuitState $newState): void
    {
        $circuit = $this->getCircuit($providerName);
        $circuit['state'] = $newState;
        $circuit['lastProbe'] = microtime(true);
        $this->circuits[$providerName] = $circuit;

        Log::info('Circuit breaker state transition', [
            'provider' => $providerName,
            'new_state' => $newState->value,
        ]);
    }
}
