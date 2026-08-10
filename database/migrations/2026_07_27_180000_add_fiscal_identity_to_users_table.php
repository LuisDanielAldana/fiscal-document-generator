<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Natural persons have their own RFC, but an employee added to a
            // company does not necessarily have one, so this stays optional.
            $table->string('rfc', 13)->nullable()->unique();
            $table->foreignId('company_id')->nullable()->constrained('companies', 'id')->nullOnDelete();
            $table->string('role')->default(UserRole::User->value)->index();
            $table->string('fiscal_postal_code', 5)->nullable();
            $table->string('tax_regime_code', 3)->nullable();
            $table->string('tax_regime_name')->nullable();
            // E.164 without the leading '+', which is the format Meta sends.
            $table->string('whatsapp_number', 20)->nullable()->unique();
            $table->boolean('whatsapp_verified')->default(false);
            $table->timestamp('whatsapp_verified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['rfc']);
            $table->dropUnique(['whatsapp_number']);
            $table->dropForeign(['company_id']);
            $table->dropColumn([
                'rfc',
                'company_id',
                'role',
                'fiscal_postal_code',
                'tax_regime_code',
                'tax_regime_name',
                'whatsapp_number',
                'whatsapp_verified',
                'whatsapp_verified_at',
            ]);
        });
    }
};
