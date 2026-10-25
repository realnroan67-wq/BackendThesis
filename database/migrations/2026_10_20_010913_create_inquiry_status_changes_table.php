<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inquiry_status_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inquiry_id')->unique()->constrained()->restrictOnDelete();
            $table->string('previous_status', 30);
            $table->string('new_status', 30);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('changed_at')->useCurrent();
            $table->index(['actor_id', 'changed_at']);
        });
        DB::statement("ALTER TABLE inquiry_status_changes ADD CONSTRAINT inquiry_status_changes_transition_check CHECK (previous_status = 'pending' AND new_status = 'closed')");
        DB::unprepared(<<<'SQL'
CREATE FUNCTION protect_inquiry_status_change_history() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Inquiry status audit cannot be modified or deleted' USING ERRCODE = '23514';
END;
$$;
CREATE TRIGGER inquiry_status_changes_protect_history BEFORE UPDATE OR DELETE ON inquiry_status_changes
FOR EACH ROW EXECUTE FUNCTION protect_inquiry_status_change_history();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiry_status_changes');
        DB::statement('DROP FUNCTION IF EXISTS protect_inquiry_status_change_history()');
    }
};
