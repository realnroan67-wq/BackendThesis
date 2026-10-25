<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inquiry_provider_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inquiry_id')->constrained()->restrictOnDelete();
            $table->foreignId('provider_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('granted_at')->useCurrent();
            $table->foreignId('granted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('revocation_reason')->nullable();
            $table->timestampsTz();
            $table->unique(['inquiry_id', 'provider_id']);
            $table->index(['provider_id', 'revoked_at', 'inquiry_id']);
        });
        DB::statement(<<<'SQL'
ALTER TABLE inquiry_provider_grants ADD CONSTRAINT inquiry_grants_revocation_audit_check CHECK (
    (revoked_at IS NULL AND revoked_by IS NULL AND revocation_reason IS NULL)
    OR (revoked_at IS NOT NULL AND revoked_by IS NOT NULL AND revocation_reason IS NOT NULL
        AND length(btrim(revocation_reason)) > 0 AND revoked_at >= granted_at)
)
SQL);
        DB::unprepared(<<<'SQL'
CREATE FUNCTION protect_inquiry_grant_history() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Grant audit history cannot be deleted' USING ERRCODE = '23514';
    END IF;
    IF ROW(NEW.inquiry_id, NEW.provider_id, NEW.granted_at, NEW.granted_by, NEW.created_at)
       IS DISTINCT FROM ROW(OLD.inquiry_id, OLD.provider_id, OLD.granted_at, OLD.granted_by, OLD.created_at) THEN
        RAISE EXCEPTION 'Grant membership and origin audit are immutable' USING ERRCODE = '23514';
    END IF;
    IF OLD.revoked_at IS NOT NULL AND ROW(NEW.revoked_at, NEW.revoked_by, NEW.revocation_reason)
       IS DISTINCT FROM ROW(OLD.revoked_at, OLD.revoked_by, OLD.revocation_reason) THEN
        RAISE EXCEPTION 'Revoked grant audit cannot be rewritten or reactivated' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER inquiry_grants_protect_history BEFORE UPDATE OR DELETE ON inquiry_provider_grants
FOR EACH ROW EXECUTE FUNCTION protect_inquiry_grant_history();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiry_provider_grants');
        DB::statement('DROP FUNCTION IF EXISTS protect_inquiry_grant_history()');
    }
};
