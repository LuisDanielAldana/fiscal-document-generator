<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The kinds of document the bot can deliver.
     *
     * This table owns presentation and policy (label, ordering, whether it is
     * offered, how stale a copy may be before we refuse to send it). The code
     * that actually talks to SatGo is resolved from `code` by the
     * DocumentGeneratorRegistry, so no class name is ever read from the database.
     */
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            // How many days old a stored copy may be and still be delivered.
            // Past this, the bot refuses rather than sending a stale document.
            $table->unsignedTinyInteger('max_age_days')->default(2);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_types');
    }
};
