<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Stop rather than merge identities or invent verification during backfill.
        $canonical = "lower(btrim(email, E' \\t\\r\\n'))";
        if (DB::table('users')->selectRaw($canonical.' AS identity')->groupByRaw($canonical)->havingRaw('count(*) > 1')->exists()) {
            throw new RuntimeException('Canonical email collisions require an approved identity review before migration.');
        }
        if (DB::table('users')->whereRaw("octet_length($canonical) NOT BETWEEN 1 AND 254 OR $canonical ~ '[^!-~]'")->exists()) {
            throw new RuntimeException('Existing email values require review before canonicalization.');
        }
        Schema::table('users', function (Blueprint $table): void {
            $table->string('onboarding_origin', 20)->nullable();
        });
        DB::table('users')->update(['email' => DB::raw($canonical), 'onboarding_origin' => 'provisioned']);
        DB::statement("ALTER TABLE users ALTER COLUMN onboarding_origin SET DEFAULT 'self_business', ALTER COLUMN onboarding_origin SET NOT NULL");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_onboarding_origin_check CHECK (onboarding_origin IN ('self_business','self_provider','provisioned'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_public_role_check CHECK (onboarding_origin = 'provisioned' OR (onboarding_origin = 'self_business' AND role = 'business_user') OR (onboarding_origin = 'self_provider' AND role IN ('business_user','property_provider')))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_canonical_email_check CHECK (email = lower(btrim(email, E' \\t\\r\\n')) AND octet_length(email) BETWEEN 1 AND 254 AND email !~ '[^!-~]')");
        DB::unprepared(<<<'SQL'
CREATE FUNCTION protect_onboarding_origin() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.onboarding_origin IS DISTINCT FROM OLD.onboarding_origin THEN
        RAISE EXCEPTION 'Onboarding origin is immutable' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER users_protect_onboarding_origin BEFORE UPDATE ON users
FOR EACH ROW EXECUTE FUNCTION protect_onboarding_origin();
SQL);
        Schema::create('provider_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('applicant_user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('business_name', 150);
            $table->string('business_location', 255);
            $table->text('application_message');
            $table->string('status', 20)->default('pending');
            $table->timestampTz('submitted_at')->useCurrent();
            $table->timestampTz('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('public_reason', 1000)->nullable();
            $table->timestampsTz();
            $table->unique(['id', 'applicant_user_id']);
            $table->index(['status', 'submitted_at', 'id']);
        });
        DB::statement("ALTER TABLE provider_applications ADD CONSTRAINT provider_applications_state_check CHECK ((status = 'pending' AND decided_at IS NULL AND decided_by IS NULL AND public_reason IS NULL) OR (status = 'approved' AND decided_at IS NOT NULL AND decided_by IS NOT NULL AND public_reason IS NULL) OR (status = 'rejected' AND decided_at IS NOT NULL AND decided_by IS NOT NULL AND char_length(btrim(public_reason)) BETWEEN 1 AND 1000 AND public_reason IS NOT NULL))");
        DB::statement('ALTER TABLE provider_applications ADD CONSTRAINT provider_applications_actor_check CHECK (decided_by IS NULL OR decided_by <> applicant_user_id), ADD CONSTRAINT provider_applications_text_check CHECK (char_length(btrim(business_name)) BETWEEN 1 AND 150 AND char_length(btrim(business_location)) BETWEEN 1 AND 255 AND char_length(btrim(application_message)) BETWEEN 20 AND 2000), ADD CONSTRAINT provider_applications_time_check CHECK (decided_at IS NULL OR decided_at >= submitted_at)');
        DB::unprepared(<<<'SQL'
CREATE FUNCTION protect_provider_application() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Provider application history cannot be deleted' USING ERRCODE = '23514';
    END IF;
    IF ROW(NEW.id, NEW.applicant_user_id, NEW.business_name, NEW.business_location, NEW.application_message, NEW.submitted_at, NEW.created_at)
        IS DISTINCT FROM ROW(OLD.id, OLD.applicant_user_id, OLD.business_name, OLD.business_location, OLD.application_message, OLD.submitted_at, OLD.created_at)
        OR (OLD.status <> 'pending' AND NEW IS DISTINCT FROM OLD)
        OR (NEW.status = OLD.status AND NEW IS DISTINCT FROM OLD) THEN
        RAISE EXCEPTION 'Provider application snapshots and final decisions are immutable' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER provider_applications_protect_history BEFORE UPDATE OR DELETE ON provider_applications
FOR EACH ROW EXECUTE FUNCTION protect_provider_application();
SQL);
        Schema::create('onboarding_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subject_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('application_id')->nullable()->unique();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('event', 30);
            $table->string('old_state', 20)->nullable();
            $table->string('new_state', 20);
            $table->timestampTz('occurred_at')->useCurrent();
            $table->foreign(['application_id', 'subject_user_id'])->references(['id', 'applicant_user_id'])->on('provider_applications')->restrictOnDelete();
            $table->index(['subject_user_id', 'occurred_at']);
        });
        DB::statement("ALTER TABLE onboarding_audit_events ADD CONSTRAINT onboarding_audit_state_check CHECK ((event = 'email_verified' AND application_id IS NULL AND old_state IS NULL AND new_state = 'verified' AND actor_user_id = subject_user_id) OR (event = 'provider_approved' AND application_id IS NOT NULL AND old_state IS NOT NULL AND old_state = 'pending' AND new_state = 'approved' AND actor_user_id <> subject_user_id) OR (event = 'provider_rejected' AND application_id IS NOT NULL AND old_state IS NOT NULL AND old_state = 'pending' AND new_state = 'rejected' AND actor_user_id <> subject_user_id))");
        DB::statement("CREATE UNIQUE INDEX onboarding_verified_once ON onboarding_audit_events (subject_user_id) WHERE event = 'email_verified'");
        DB::unprepared(<<<'SQL'
CREATE FUNCTION protect_onboarding_audit() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Onboarding audit cannot be modified or deleted' USING ERRCODE = '23514';
END;
$$;
CREATE TRIGGER onboarding_audit_protect_history BEFORE UPDATE OR DELETE ON onboarding_audit_events
FOR EACH ROW EXECUTE FUNCTION protect_onboarding_audit();
SQL);
        Schema::create('onboarding_notification_outbox', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subject_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('application_id')->nullable();
            $table->string('notification_kind', 30);
            $table->uuid('deduplication_key')->unique();
            $table->integer('attempt_count')->default(0);
            $table->timestampTz('next_attempt_at')->useCurrent();
            $table->timestampTz('sent_at')->nullable();
            $table->string('last_error_code', 40)->nullable();
            $table->timestampsTz();
            $table->foreign(['application_id', 'subject_user_id'])->references(['id', 'applicant_user_id'])->on('provider_applications')->restrictOnDelete();
            $table->index(['sent_at', 'next_attempt_at']);
        });
        DB::statement("ALTER TABLE onboarding_notification_outbox ADD CONSTRAINT onboarding_outbox_kind_check CHECK ((notification_kind = 'email_verification' AND application_id IS NULL) OR (notification_kind = 'provider_decision' AND application_id IS NOT NULL)), ADD CONSTRAINT onboarding_outbox_attempt_check CHECK (attempt_count >= 0), ADD CONSTRAINT onboarding_outbox_error_check CHECK (last_error_code IS NULL OR last_error_code IN ('DELIVERY_UNAVAILABLE','RETRY_EXHAUSTED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_notification_outbox');
        Schema::dropIfExists('onboarding_audit_events');
        Schema::dropIfExists('provider_applications');
        DB::statement('DROP FUNCTION IF EXISTS protect_onboarding_audit(), protect_provider_application()');
        DB::statement('DROP TRIGGER IF EXISTS users_protect_onboarding_origin ON users');
        DB::statement('DROP FUNCTION IF EXISTS protect_onboarding_origin()');
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_onboarding_origin_check, DROP CONSTRAINT users_public_role_check, DROP CONSTRAINT users_canonical_email_check');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('onboarding_origin'));
    }
};
