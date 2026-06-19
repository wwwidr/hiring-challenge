<?php

namespace App\Modules\ContactFinder\Services;

use Illuminate\Support\Facades\Log;

class RateLimiter
{
    /** @var array<string, array{count: int, windowStart: float}> */
    private array $counters = [];

    /**
     * @return bool True if the request is allowed, false if rate limited.
     */
    public function attempt(string $providerName, int $maxAttempts, int $decaySeconds = 60): bool
    {
        $now = microtime(true);
        $key = $providerName;

        if (!isset($this->counters[$key])) {
            $this->counters[$key] = ['count' => 0, 'windowStart' => $now];
        }

        $window = $this->counters[$key];

        if (($now - $window['windowStart']) >= $decaySeconds) {
            $this->counters[$key] = ['count' => 1, 'windowStart' => $now];

            return true;
        }

        if ($window['count'] >= $maxAttempts) {
            Log::warning('Rate limit reached for provider', [
                'provider' => $providerName,
                'limit' => $maxAttempts,
                'window_seconds' => $decaySeconds,
            ]);

            return false;
        }

        $this->counters[$key]['count']++;

        return true;
    }

    public function reset(string $providerName): void
    {
        unset($this->counters[$providerName]);
    }

    public function getRemainingAttempts(string $providerName, int $maxAttempts): int
    {
        if (!isset($this->counters[$providerName])) {
            return $maxAttempts;
        }

        return max(0, $maxAttempts - $this->counters[$providerName]['count']);
    }
}
