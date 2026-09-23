<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('phone');
            $table->string('company')->nullable();

            // Nullable: an unassigned queue exists for team leads to triage.
            // ON DELETE RESTRICT: users are deactivated (is_active=false),
            // never deleted, so this FK should never actually fire — it's a
            // guard against an accidental hard-delete leaving contacts
            // dangling, not a code path we expect to exercise.
            $table->foreignId('assigned_agent_id')->nullable()->constrained('users')->restrictOnDelete();

            $table->enum('status', ['new', 'active', 'pending', 'resolved'])->default('new');

            // Denormalized "last interaction timestamp, or created_at if
            // none yet" — see DECISIONS.md #4. NOT NULL is required for
            // cursorPaginate() to sort on this column without NULLs
            // breaking the keyset comparison; enforced by Contact's
            // `creating` model event, not a DB-level default (MySQL can't
            // default one column to another column's value).
            $table->timestamp('last_activity_at');

            // Optimistic concurrency token — see DECISIONS.md #3.
            $table->unsignedInteger('version')->default(1);

            $table->timestamps();
            $table->softDeletes(); // archive, see DECISIONS.md "Domain rules"

            // Every hot query is `WHERE <equality col(s)> ORDER BY <sort
            // col>, id` — composite indexes put equality columns first so
            // MySQL can satisfy the WHERE and the ORDER BY from one index
            // scan (no filesort), which matters at the 250k-row scale the
            // brief calls out.
            $table->index(['assigned_agent_id', 'last_activity_at'], 'contacts_agent_last_activity_idx');
            $table->index(['assigned_agent_id', 'created_at'], 'contacts_agent_created_idx');
            $table->index(['status', 'last_activity_at'], 'contacts_status_last_activity_idx');
            $table->index('last_activity_at');
            $table->index('created_at');

            // LIKE '%term%' can't use a B-tree index; FULLTEXT gives indexed
            // word/prefix search across all four fields — see DECISIONS.md #6.
            $table->fullText(['name', 'email', 'phone', 'company'], 'contacts_search_fulltext');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
