<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per taxpayer, document type and calendar day.
     *
     * History is kept deliberately: a compliance opinion is a point-in-time
     * statement, so being able to show what the SAT said on a given date is
     * worth a row per day. The unique key makes the nightly job and the 07:00
     * retry idempotent — a retry updates that day's row instead of adding one.
     *
     * `fiscal_subject` is the authorization anchor (who the document is about);
     * `fiscal_credential_id` is provenance (which FIEL fetched it).
     */
    public function up(): void
    {
        Schema::create('fiscal_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // Company or User. Denormalized from the credential's owner so that
            // access checks never have to join through fiscal_credentials.
            $table->morphs('fiscal_subject');
            $table->foreignId('document_type_id')->constrained('document_types', 'id')->cascadeOnDelete();
            $table->foreignId('fiscal_credential_id')->nullable()->constrained('fiscal_credentials', 'id')->nullOnDelete();
            // Who asked, when the request was user-initiated rather than scheduled.
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users', 'id')->nullOnDelete();
            $table->string('status')->default('pending');
            // schedule | whatsapp | manual
            $table->string('source')->nullable();

            // File location and metadata. Null until the first success; a later
            // failure leaves these intact so the last good copy stays servable.
            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->string('original_filename')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();

            $table->text('error_message')->nullable();
            $table->date('generated_for_date');
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('generated_at')->nullable();
            // Set once a failure has been reported, so the 08:30 command does
            // not notify the same failure twice.
            $table->timestamp('failure_notified_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['fiscal_credential_id', 'document_type_id', 'generated_for_date'],
                'fiscal_documents_daily_unique'
            );
            $table->index(
                ['fiscal_subject_type', 'fiscal_subject_id', 'document_type_id', 'status'],
                'fiscal_documents_subject_lookup'
            );
            $table->index(['status', 'generated_for_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_documents');
    }
};
