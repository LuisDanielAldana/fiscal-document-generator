<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $legalName = fake()->company();

        return [
            'name' => $legalName,
            'legal_name' => $legalName.' S.A. de C.V.',
            'rfc' => Str::upper(fake()->unique()->bothify('???######???')),
            'is_active' => true,
            'fiscal_postal_code' => fake()->numerify('#####'),
            'tax_regime_code' => fake()->randomElement(['601', '603', '626']),
            'tax_regime_name' => 'General de Ley Personas Morales',
            'email' => fake()->unique()->companyEmail(),
            'phone_number' => fake()->numerify('52##########'),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
