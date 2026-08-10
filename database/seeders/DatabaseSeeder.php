<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Required, not sample data: nothing generates without these rows.
        $this->call(DocumentTypeSeeder::class);

        // The account that receives the nightly failure digest.
        User::query()->firstOrCreate(
            ['email' => 'super@example.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'role' => UserRole::SuperAdmin,
            ],
        );

        $this->call(CompanySeeder::class);
    }
}
