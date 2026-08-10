<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => UserRole::User,
            'rfc' => Str::upper(fake()->unique()->bothify('????######???')),
            'fiscal_postal_code' => fake()->numerify('#####'),
            'tax_regime_code' => fake()->randomElement(['605', '612', '626']),
            'tax_regime_name' => 'Régimen Simplificado de Confianza',
            // E.164 without the '+', matching what Meta sends on the webhook.
            'whatsapp_number' => fake()->unique()->numerify('52##########'),
            'whatsapp_verified' => true,
            'whatsapp_verified_at' => now(),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * A user who administers their company and may reach the panel.
     */
    public function administrator(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Admin,
        ]);
    }

    /**
     * The company's legal representative ("owner").
     */
    public function legalRepresentative(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::LegalRepresentative,
        ]);
    }

    /**
     * A user belonging to the given company, creating one when not supplied.
     */
    public function ofCompany(?Company $company = null): static
    {
        return $this->for($company ?? Company::factory());
    }

    /**
     * A user the bot cannot message.
     */
    public function withoutWhatsApp(): static
    {
        return $this->state(fn (array $attributes) => [
            'whatsapp_number' => null,
            'whatsapp_verified' => false,
            'whatsapp_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     *
     * Only reachable when Fortify's two-factor feature is enabled, which also
     * supplies the columns these attributes write to.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['code-one', 'code-two'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
