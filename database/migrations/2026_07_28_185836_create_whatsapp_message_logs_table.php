<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit trail for every message in and out, and the idempotency guard for
     * inbound webhooks — Meta retries deliveries, so the message id it assigns
     * must be unique here.
     */
    public function up(): void
    {
        Schema::create('whatsapp_message_logs', function (Blueprint $table) {
            $table->id();
            // Null for outbound messages we log before Meta assigns an id.
            $table->string('whatsapp_message_id')->nullable()->unique();
            // inbound | outbound
            $table->string('direction');
            $table->string('whatsapp_phone', 20);
            $table->json('payload');
            // sent | delivered | read | failed, for outbound messages.
            $table->string('status')->nullable();
            $table->timestamps();

            $table->index(['whatsapp_phone', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_message_logs');
    }
};
