<?php

namespace Database\Factories;

use App\Enums\SatCredentialMethod;
use App\Models\Company;
use App\Models\FiscalCredential;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @extends Factory<FiscalCredential>
 */
class FiscalCredentialFactory extends Factory
{
    /**
     * A complete FIEL by default, since that is the mechanism the SAT prefers.
     *
     * The paths point at files that do not exist; tests that exercise the client
     * should put them on the fake disk with `withStoredFiles()`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $rfc = Str::upper(fake()->unique()->bothify('???######???'));

        return [
            'owner_type' => (new Company)->getMorphClass(),
            'owner_id' => Company::factory(),
            'rfc' => $rfc,
            'cer_path' => 'fiel/'.Str::lower($rfc).'.cer',
            'key_path' => 'fiel/'.Str::lower($rfc).'.key',
            'fiel_password' => 'e.firma-secret',
            'ciec_password' => null,
            'preferred_method' => null,
            'fiel_expires_at' => now()->addYear(),
            'is_active' => true,
        ];
    }

    /**
     * Owned by a given company or natural person.
     *
     * Named rather than relying on `for()`, so a test reads as "this credential
     * belongs to that taxpayer" without naming the morph relationship.
     */
    public function ownedBy(Model $owner): static
    {
        return $this->state(fn (array $attributes): array => [
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
        ]);
    }

    /**
     * CIEC only: no e-signature on file.
     */
    public function ciecOnly(): static
    {
        return $this->state(fn (array $attributes): array => [
            'cer_path' => null,
            'key_path' => null,
            'fiel_password' => null,
            'ciec_password' => 'sat-portal-password',
            'fiel_expires_at' => null,
        ]);
    }

    /**
     * Both mechanisms registered, with different passwords.
     */
    public function withBothMethods(): static
    {
        return $this->state(fn (array $attributes): array => [
            'ciec_password' => 'sat-portal-password',
        ]);
    }

    public function preferring(SatCredentialMethod $method): static
    {
        return $this->state(fn (array $attributes): array => [
            'preferred_method' => $method,
        ]);
    }

    /**
     * A certificate the SAT will refuse.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'fiel_expires_at' => now()->subDay(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    /**
     * Nothing usable at all: a row that the nightly run must skip.
     */
    public function withoutCredentials(): static
    {
        return $this->state(fn (array $attributes): array => [
            'cer_path' => null,
            'key_path' => null,
            'fiel_password' => null,
            'ciec_password' => null,
        ]);
    }

    /**
     * Put the e-signature files on the (usually faked) private disk, so a client
     * test gets past the "missing key material" guard.
     */
    public function withStoredFiles(): static
    {
        return $this->afterCreating(function (FiscalCredential $credential): void {
            $disk = Storage::disk(FiscalCredential::STORAGE_DISK);

            if ($credential->cer_path !== null) {
                $disk->put($credential->cer_path, 'certificate-bytes');
            }

            if ($credential->key_path !== null) {
                $disk->put($credential->key_path, 'private-key-bytes');
            }
        });
    }
}
