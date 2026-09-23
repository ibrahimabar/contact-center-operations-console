<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interactions', function (Blueprint $table) {
            $table->id();

            // RESTRICT, not CASCADE: interactions are an audit trail.
            // Contacts are archived (soft-deleted), never hard-deleted, so
            // this should never actually fire — it's a guard against an
            // accidental multi-million-row cascade, not an expected path.
            $table->foreignId('contact_id')->constrained('contacts')->restrictOnDelete();

            $table->enum('type', ['phone', 'email', 'sms', 'chat']);

            // The logical moment the interaction happened — may be backdated
            // by the agent (e.g. logging a call after the fact) and so is
            // distinct from created_at (when the row was inserted).
            $table->timestamp('timestamp');

            $table->text('note');

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();

            // Idempotency — see DECISIONS.md #2. Scoped to (created_by, key)
            // rather than globally unique, so one user can't replay/read
            // another user's interaction by guessing or reusing a key.
            $table->char('idempotency_key', 36)->nullable();
            $table->char('request_hash', 64)->nullable();

            // Immutable/append-only: no updated_at, no update endpoint.
            $table->timestamp('created_at')->useCurrent();

            // Ascending (contact_id, timestamp) also serves the "newest
            // first" timeline query — InnoDB can scan a B-tree index
            // backwards for ORDER BY ... DESC just as efficiently as
            // forwards, so a separate descending index isn't needed.
            $table->index(['contact_id', 'timestamp'], 'interactions_contact_timestamp_idx');

            // NULL is allowed (and excluded from the uniqueness check by
            // MySQL) for any interaction created without a key, e.g. seeded
            // demo data.
            $table->unique(['created_by', 'idempotency_key'], 'interactions_creator_idempotency_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interactions');
    }
};
