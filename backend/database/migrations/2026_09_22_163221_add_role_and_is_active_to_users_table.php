<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Two static roles for this scope — see DECISIONS.md "Roles as
            // a simple enum column, not Spatie Permission".
            $table->enum('role', ['agent', 'team_lead'])->default('agent')->after('email');

            // Deactivated users are locked out at login and their existing
            // sessions are rejected, rather than deleting the row — contacts
            // and interactions keep a valid assigned_agent_id/created_by FK.
            $table->boolean('is_active')->default(true)->after('role');

            $table->index(['role', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role', 'is_active']);
            $table->dropColumn(['role', 'is_active']);
        });
    }
};
