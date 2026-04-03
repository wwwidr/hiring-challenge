<?php

namespace Database\Factories;

use App\Modules\Sequence\Models\Sequence;
use App\Modules\Company\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

class SequenceFactory extends Factory
{
    protected $model = Sequence::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'status' => 'active',
            'amount' => fake()->randomFloat(2, 100, 10000),
            'currency' => 'EUR',
        ];
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'cancelled',
        ]);
    }

    public function recovered(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'recovered',
        ]);
    }

    public function installment(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'installment',
        ]);
    }
}
