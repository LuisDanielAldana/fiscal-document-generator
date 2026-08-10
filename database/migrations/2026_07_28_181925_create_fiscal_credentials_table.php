<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How one RFC authenticates against the SAT, through SatGo.
     *
     * Two mechanisms exist and a taxpayer may hold either or both:
     *
     *  - FIEL: the e-signature pair. The `.cer` and `.key` live on the private
     *    `fiscal-credentials` disk and only their paths are kept here, with the
     *    key's passphrase in `fiel_password`.
     *  - CIEC: the SAT portal password, kept in `ciec_password` and sent to
     *    SatGo as the `Secret` header.
     *
     * The two passwords are unrelated, so each gets its own column rather than
     * sharing one. Both are encrypted at rest by the model's `encrypted` casts,
     * which means rotating APP_KEY without re-encrypting these rows leaves every
     * stored password unrecoverable.
     */
    public function up(): void
    {
        Schema::create('fiscal_credentials', function (Blueprint $table) {
            $table->id();
            // Company (persona moral) or User (persona física).
            $table->morphs('owner');
            $table->string('rfc', 13);

            // FIEL. All three are null on a CIEC-only credential.
            $table->string('cer_path')->nullable();
            $table->string('key_path')->nullable();
            // These hold Laravel-encrypted payloads, which are much longer than
            // the plaintext and would overflow a default-length string column.
            $table->text('fiel_password')->nullable();

            // CIEC. Null on a FIEL-only credential.
            $table->text('ciec_password')->nullable();

            // Forces one mechanism when both are present; null prefers the FIEL,
            // whose documents the SAT treats as carrying more weight.
            $table->string('preferred_method')->nullable();

            $table->date('fiel_expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_validated_at')->nullable();
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id', 'rfc']);
            $table->index(['is_active', 'rfc']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_credentials');
    }
};
