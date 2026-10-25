<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inquiries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('property_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('business_user_id')->index()->constrained('users')->restrictOnDelete();
            $table->foreignId('provider_id')->constrained('users')->restrictOnDelete();
            $table->string('business_name', 150)->nullable();
            $table->string('contact_name', 150);
            $table->string('contact_email', 254);
            $table->string('contact_phone', 30)->nullable();
            $table->text('message');
            $table->string('status', 30)->default('pending');
            $table->timestampsTz();
            $table->index(['provider_id', 'status']);
        });
        DB::statement("ALTER TABLE inquiries ADD CONSTRAINT inquiries_status_check CHECK (status IN ('pending', 'closed'))");
        DB::unprepared(<<<'SQL'
CREATE FUNCTION protect_inquiry_history() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Inquiry history cannot be deleted' USING ERRCODE = '23514';
    END IF;
    IF ROW(NEW.property_id, NEW.business_user_id, NEW.provider_id, NEW.business_name,
           NEW.contact_name, NEW.contact_email, NEW.contact_phone, NEW.message, NEW.created_at)
       IS DISTINCT FROM
       ROW(OLD.property_id, OLD.business_user_id, OLD.provider_id, OLD.business_name,
           OLD.contact_name, OLD.contact_email, OLD.contact_phone, OLD.message, OLD.created_at) THEN
        RAISE EXCEPTION 'Inquiry identities and snapshots are immutable' USING ERRCODE = '23514';
    END IF;
    IF OLD.status = 'closed' AND NEW.status <> 'closed' THEN
        RAISE EXCEPTION 'Closed inquiries cannot be reopened' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER inquiries_protect_history BEFORE UPDATE OR DELETE ON inquiries
FOR EACH ROW EXECUTE FUNCTION protect_inquiry_history();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiries');
        DB::statement('DROP FUNCTION IF EXISTS protect_inquiry_history()');
    }
};
