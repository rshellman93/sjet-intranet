<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->date('competency_reviewed_on')->nullable();
            $t->foreignId('competency_reviewed_by')->nullable()->constrained('users');
        });
        Schema::table('mus_conversations', function (Blueprint $t) {
            $t->unsignedInteger('submitted_revision')->nullable();
            $t->uuid('creation_token')->nullable()->unique();
        });
        Schema::table('course_records', function (Blueprint $t) {
            $t->uuid('submission_id')->nullable()->unique();
        });
        Schema::table('competency_assessments', function (Blueprint $t) {
            $t->uuid('submission_id')->nullable()->unique();
        });
        Schema::table('profile_entries', function (Blueprint $t) {
            $t->uuid('submission_id')->nullable()->unique();
        });
        Schema::table('mus_goals', function (Blueprint $t) {
            $t->date('support_delivered_on')->nullable();
        });
        Schema::create('mus_transfer_history', function (Blueprint $t) {
            $t->id();
            $t->uuid('goal_id');
            $t->foreign('goal_id')->references('id')->on('mus_goals');
            $t->unsignedInteger('version');
            $t->foreignId('course_record_id')->nullable()->constrained();
            $t->json('projection');
            $t->timestamps();
            $t->unique(['goal_id', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mus_transfer_history');
        Schema::table('mus_goals', fn (Blueprint $t) => $t->dropColumn('support_delivered_on'));
        foreach (['course_records', 'competency_assessments', 'profile_entries'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('submission_id'));
        }
        Schema::table('mus_conversations', fn (Blueprint $t) => $t->dropColumn(['submitted_revision', 'creation_token']));
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('competency_reviewed_by');
            $t->dropColumn('competency_reviewed_on');
        });
    }
};
