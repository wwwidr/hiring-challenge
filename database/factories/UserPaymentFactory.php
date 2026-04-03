<?php

namespace Database\Factories;

use App\Modules\Payment\Models\UserPayment;
use App\Modules\Sequence\Models\Sequence;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserPaymentFactory extends Factory
{
    protected $model = UserPayment::class;

    public function definition(): array
    {
        return [
            'sequence_id' => Sequence::factory(),
            'amount' => fake()->randomFloat(2, 50, 5000),
            'currency' => 'EUR',
            'status' => 'completed',
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
        ]);
    }
}
