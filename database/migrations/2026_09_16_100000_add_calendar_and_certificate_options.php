<?php

use App\Support\Development;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('employee_driving_licenses', function (Blueprint $table) {
            $table->date('expires_on')->nullable()->change();
            $table->text('note')->nullable();
        });
        Schema::table('employee_safety_certificates', function (Blueprint $table) {
            $table->date('expires_on')->nullable()->change();
            $table->boolean('never_expires')->default(false);
            $table->text('note')->nullable();
            $table->foreignId('file_id')->nullable()->constrained('private_files')->nullOnDelete();
        });
        Schema::table('kls_records', function (Blueprint $table) {
            $table->boolean('never_expires')->default(false);
        });
        $certificateSkills = DB::table('kls_skills')->whereIn('name', Development::SAFETY_CERTIFICATES)->pluck('name', 'id');
        $latest = DB::table('kls_records')->whereIn('skill_id', $certificateSkills->keys())->orderByDesc('id')->get()->unique(fn ($record) => $record->user_id.':'.$record->skill_id);
        foreach ($latest as $record) {
            if ($record->value) {
                DB::table('employee_safety_certificates')->insertOrIgnore([
                    'user_id' => $record->user_id, 'title' => $certificateSkills[$record->skill_id], 'expires_on' => $record->expires_on,
                    'never_expires' => false, 'note' => $record->basis, 'updated_by' => $record->recorded_by,
                    'created_at' => $record->created_at, 'updated_at' => $record->updated_at,
                ]);
            }
        }
        Schema::create('skill_certificate_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained('kls_skills');
            $table->foreignId('certificate_id')->constrained('employee_safety_certificates')->cascadeOnDelete();
            $table->foreignId('updated_by')->constrained('users');
            $table->timestamps();
            $table->unique(['user_id', 'skill_id', 'certificate_id'], 'skill_certificate_unique');
        });
        Schema::create('calendar_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('type', 20);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('title')->nullable();
            $table->string('school')->nullable();
            $table->string('school_stage')->nullable();
            $table->string('module_code', 10)->nullable();
            $table->text('note')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('updated_by')->constrained('users');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['starts_on', 'ends_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calendar_entries');
        Schema::dropIfExists('skill_certificate_links');
        Schema::table('kls_records', fn (Blueprint $table) => $table->dropColumn('never_expires'));
        Schema::table('employee_safety_certificates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('file_id');
            $table->dropColumn(['never_expires', 'note']);
        });
        Schema::table('employee_driving_licenses', fn (Blueprint $table) => $table->dropColumn('note'));
    }
};
