<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The state machine for one WhatsApp number's conversation with the bot.
     */
    public function up(): void
    {
        Schema::create('whatsapp_conversations', function (Blueprint $table) {
            $table->id();
            // Nullable: a conversation can start from a number we do not
            // recognise yet, and we still want to track it.
            $table->foreignId('user_id')->nullable()->constrained('users', 'id')->nullOnDelete();
            $table->string('whatsapp_phone', 20)->unique();
            $table->string('state')->default('idle');
            // Scratch space for the current flow, e.g. the chosen credential.
            $table->json('context')->nullable();
            $table->timestamp('last_interaction_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_conversations');
    }
};
