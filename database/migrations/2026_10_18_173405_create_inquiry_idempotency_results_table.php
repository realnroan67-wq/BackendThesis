<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inquiry_idempotency_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_user_id')->constrained('users')->restrictOnDelete();
            $table->string('operation', 30);
            $table->uuid('idempotency_key');
            $table->char('fingerprint', 64);
            $table->foreignId('inquiry_id')->unique()->constrained()->restrictOnDelete();
            $table->text('response_body');
            $table->string('location');
            $table->timestampsTz();
            $table->unique(['business_user_id', 'operation', 'idempotency_key'], 'inquiry_idempotency_scope_unique');
        });
        DB::unprepared(<<<'SQL'
CREATE FUNCTION protect_inquiry_idempotency_history() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Successful inquiry retry records cannot be modified or deleted' USING ERRCODE = '23514';
END;
$$;
CREATE TRIGGER inquiry_idempotency_protect_history BEFORE UPDATE OR DELETE ON inquiry_idempotency_results
FOR EACH ROW EXECUTE FUNCTION protect_inquiry_idempotency_history();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiry_idempotency_results');
        DB::statement('DROP FUNCTION IF EXISTS protect_inquiry_idempotency_history()');
    }
};
