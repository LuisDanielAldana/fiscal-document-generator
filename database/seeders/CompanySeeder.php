<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * One company with the three roles filled in, for local development.
 *
 * No credentials are seeded: an e-signature is real key material and does not
 * belong in a seeder. Add one from the panel to exercise generation.
 */
class CompanySeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::query()->firstOrCreate(
            ['rfc' => 'DEM240101AB1'],
            [
                'name' => 'Demo',
                'legal_name' => 'Demo S.A. de C.V.',
                'is_active' => true,
                'fiscal_postal_code' => '06600',
                'tax_regime_code' => '601',
                'tax_regime_name' => 'General de Ley Personas Morales',
                'email' => 'contacto@demo.mx',
            ],
        );

        $this->user('Representante Legal', 'representante@demo.mx', UserRole::LegalRepresentative, $company);
        $this->user('Administradora', 'admin@demo.mx', UserRole::Admin, $company);

        // A plain user: no panel access, WhatsApp only, and their own RFC so they
        // have something of their own to ask for.
        $this->user('Usuario Simple', 'usuario@demo.mx', UserRole::User, $company, 'USI900101AB2');
    }

    private function user(
        string $name,
        string $email,
        UserRole $role,
        Company $company,
        ?string $rfc = null,
    ): void {
        User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'role' => $role,
                'company_id' => $company->id,
                'rfc' => $rfc,
            ],
        );
    }
}
