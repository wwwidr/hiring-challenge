<?php

namespace Tests\Unit\Modules\ContactFinder;

use App\Modules\ContactFinder\Enums\CircuitState;
use App\Modules\ContactFinder\Services\CircuitBreaker;
use Tests\TestCase;

class CircuitBreakerTest extends TestCase
{
    public function test_starts_in_closed_state(): void
    {
        $breaker = new CircuitBreaker();

        $this->assertEquals(CircuitState::CLOSED, $breaker->getState('test_provider'));
        $this->assertTrue($breaker->isAvailable('test_provider'));
    }

    public function test_opens_after_threshold_failures(): void
    {
        $breaker = new CircuitBreaker(failureThreshold: 3);

        $breaker->recordFailure('test_provider');
        $breaker->recordFailure('test_provider');
        $this->assertTrue($breaker->isAvailable('test_provider'));

        $breaker->recordFailure('test_provider');
        $this->assertEquals(CircuitState::OPEN, $breaker->getState('test_provider'));
        $this->assertFalse($breaker->isAvailable('test_provider'));
    }

    public function test_resets_on_success(): void
    {
        $breaker = new CircuitBreaker(failureThreshold: 3);

        $breaker->recordFailure('test_provider');
        $breaker->recordFailure('test_provider');
        $breaker->recordSuccess('test_provider');

        $this->assertEquals(CircuitState::CLOSED, $breaker->getState('test_provider'));
        $this->assertTrue($breaker->isAvailable('test_provider'));
    }

    public function test_transitions_to_half_open_after_cooldown(): void
    {
        $breaker = new CircuitBreaker(failureThreshold: 1, cooldownSeconds: 1);

        $breaker->recordFailure('test_provider');
        $this->assertFalse($breaker->isAvailable('test_provider'));
        $this->assertEquals(CircuitState::OPEN, $breaker->getState('test_provider'));

        sleep(2);
        $this->assertTrue($breaker->isAvailable('test_provider'));
        $this->assertEquals(CircuitState::HALF_OPEN, $breaker->getState('test_provider'));
    }

    public function test_half_open_reopens_on_failure(): void
    {
        $breaker = new CircuitBreaker(failureThreshold: 1, cooldownSeconds: 1);

        $breaker->recordFailure('test_provider');
        sleep(2);
        $breaker->isAvailable('test_provider');

        $breaker->recordFailure('test_provider');
        $this->assertEquals(CircuitState::OPEN, $breaker->getState('test_provider'));
    }

    public function test_half_open_closes_on_success(): void
    {
        $breaker = new CircuitBreaker(failureThreshold: 1, cooldownSeconds: 1);

        $breaker->recordFailure('test_provider');
        sleep(2);
        $breaker->isAvailable('test_provider');

        $breaker->recordSuccess('test_provider');
        $this->assertEquals(CircuitState::CLOSED, $breaker->getState('test_provider'));
    }

    public function test_tracks_providers_independently(): void
    {
        $breaker = new CircuitBreaker(failureThreshold: 1);

        $breaker->recordFailure('provider_a');

        $this->assertFalse($breaker->isAvailable('provider_a'));
        $this->assertTrue($breaker->isAvailable('provider_b'));
    }
}
