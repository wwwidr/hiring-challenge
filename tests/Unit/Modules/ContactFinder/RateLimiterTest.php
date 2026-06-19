<?php

namespace Tests\Unit\Modules\ContactFinder;

use App\Modules\ContactFinder\Services\RateLimiter;
use Tests\TestCase;

class RateLimiterTest extends TestCase
{
    public function test_allows_requests_within_limit(): void
    {
        $limiter = new RateLimiter();

        $this->assertTrue($limiter->attempt('test_provider', maxAttempts: 5));
        $this->assertTrue($limiter->attempt('test_provider', maxAttempts: 5));
        $this->assertTrue($limiter->attempt('test_provider', maxAttempts: 5));
    }

    public function test_blocks_requests_exceeding_limit(): void
    {
        $limiter = new RateLimiter();

        for ($i = 0; $i < 3; $i++) {
            $limiter->attempt('test_provider', maxAttempts: 3);
        }

        $this->assertFalse($limiter->attempt('test_provider', maxAttempts: 3));
    }

    public function test_tracks_providers_independently(): void
    {
        $limiter = new RateLimiter();

        for ($i = 0; $i < 3; $i++) {
            $limiter->attempt('provider_a', maxAttempts: 3);
        }

        $this->assertFalse($limiter->attempt('provider_a', maxAttempts: 3));
        $this->assertTrue($limiter->attempt('provider_b', maxAttempts: 3));
    }

    public function test_reset_clears_counter(): void
    {
        $limiter = new RateLimiter();

        for ($i = 0; $i < 3; $i++) {
            $limiter->attempt('test_provider', maxAttempts: 3);
        }

        $this->assertFalse($limiter->attempt('test_provider', maxAttempts: 3));

        $limiter->reset('test_provider');
        $this->assertTrue($limiter->attempt('test_provider', maxAttempts: 3));
    }

    public function test_remaining_attempts_counts_correctly(): void
    {
        $limiter = new RateLimiter();

        $this->assertEquals(5, $limiter->getRemainingAttempts('test_provider', 5));

        $limiter->attempt('test_provider', maxAttempts: 5);
        $limiter->attempt('test_provider', maxAttempts: 5);

        $this->assertEquals(3, $limiter->getRemainingAttempts('test_provider', 5));
    }
}
