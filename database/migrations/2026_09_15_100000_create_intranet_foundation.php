<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
        });
        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('department_id')->nullable()->constrained();
            $t->string('job_title')->nullable();
            $t->string('phone')->nullable();
            $t->boolean('is_leader')->default(false);
            $t->boolean('is_admin')->default(false);
            $t->boolean('active')->default(true);
            $t->unsignedInteger('revision')->default(1);
            $t->timestamp('activated_at')->nullable();
            $t->timestamp('first_login_at')->nullable();
            $t->timestamp('last_login_at')->nullable();
            $t->text('two_factor_secret')->nullable();
            $t->text('two_factor_recovery_codes')->nullable();
            $t->timestamp('two_factor_confirmed_at')->nullable();
        });
        Schema::create('team_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('leader_id')->constrained('users');
            $t->foreignId('employee_id')->constrained('users');
            $t->unique(['leader_id', 'employee_id']);
            $t->timestamps();
        });
        Schema::create('audit_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_id')->nullable()->constrained('users');
            $t->string('event');
            $t->string('subject_type');
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamp('created_at');
        });
        Schema::create('private_files', function (Blueprint $t) {
            $t->id();
            $t->foreignId('owner_id')->constrained('users');
            $t->foreignId('uploaded_by')->constrained('users');
            $t->string('name');
            $t->string('path')->unique();
            $t->string('mime');
            $t->unsignedBigInteger('size');
            $t->string('purpose')->default('reference');
            $t->timestamps();
        });
        Schema::create('competencies', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->timestamps();
        });
        Schema::create('competency_assessments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('competency_id')->constrained();
            $t->unsignedTinyInteger('level');
            $t->date('assessed_on');
            $t->foreignId('assessor_id')->constrained('users');
            $t->text('basis')->nullable();
            $t->boolean('visible_to_colleagues')->default(false);
            $t->timestamps();
        });
        Schema::create('profile_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->string('type');
            $t->string('title');
            $t->string('period')->nullable();
            $t->text('description')->nullable();
            $t->timestamps();
        });
        Schema::create('courses', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->timestamps();
        });
        Schema::create('course_competency', function (Blueprint $t) {
            $t->foreignId('course_id')->constrained();
            $t->foreignId('competency_id')->constrained();
            $t->primary(['course_id', 'competency_id']);
        });
        Schema::create('course_records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('course_id')->nullable()->constrained();
            $t->string('topic');
            $t->string('status')->default('Behov');
            $t->string('original_date')->nullable();
            $t->string('date_precision')->default('unknown');
            $t->date('completed_on')->nullable();
            $t->date('planned_on')->nullable();
            $t->string('provider')->nullable();
            $t->date('valid_until')->nullable();
            $t->date('refresh_on')->nullable();
            $t->foreignId('certificate_id')->nullable()->constrained('private_files');
            $t->text('note')->nullable();
            $t->foreignId('updated_by')->constrained('users');
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
        });
        Schema::create('mus_conversations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained('users');
            $t->foreignId('leader_id')->constrained('users');
            $t->string('employee_name');
            $t->string('leader_name');
            $t->string('job_title')->nullable();
            $t->string('template_version')->default('3.1');
            $t->string('status')->default('Planlagt');
            $t->date('scheduled_on');
            $t->date('held_on')->nullable();
            $t->date('checkin_on')->nullable();
            $t->date('followup_on')->nullable();
            $t->date('next_mus_on')->nullable();
            $t->json('shared_answers')->nullable();
            $t->unsignedInteger('revision')->default(1);
            $t->unsignedInteger('agreement_version')->default(1);
            $t->unsignedInteger('confirmed_version')->nullable();
            $t->timestamps();
        });
        Schema::create('mus_preparations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('conversation_id')->constrained('mus_conversations');
            $t->foreignId('user_id')->constrained();
            $t->json('answers')->nullable();
            $t->timestamp('shared_at')->nullable();
            $t->unsignedInteger('revision')->default(1);
            $t->unique(['conversation_id', 'user_id']);
            $t->timestamps();
        });
        Schema::create('mus_agreement_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('conversation_id')->constrained('mus_conversations');
            $t->unsignedInteger('version');
            $t->json('snapshot');
            $t->foreignId('created_by')->constrained('users');
            $t->unique(['conversation_id', 'version']);
            $t->timestamps();
        });
        Schema::create('mus_confirmations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('agreement_version_id')->constrained('mus_agreement_versions');
            $t->foreignId('user_id')->constrained();
            $t->string('name');
            $t->timestamp('confirmed_at');
            $t->unique(['agreement_version_id', 'user_id']);
        });
        Schema::create('mus_goals', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('conversation_id')->constrained('mus_conversations');
            $t->unsignedTinyInteger('slot');
            $t->string('status')->default('Aftalt');
            $t->date('started_on')->nullable();
            $t->unique(['conversation_id', 'slot']);
            $t->timestamps();
        });
        Schema::create('development_goals', function (Blueprint $t) {
            $t->id();
            $t->uuid('source_goal_id')->unique();
            $t->foreign('source_goal_id')->references('id')->on('mus_goals');
            $t->foreignId('user_id')->constrained();
            $t->foreignId('competency_id')->nullable()->constrained();
            $t->unsignedTinyInteger('desired_level')->nullable();
            $t->text('description');
            $t->date('deadline');
            $t->string('status')->default('Aftalt');
            $t->unsignedInteger('source_version');
            $t->timestamps();
        });
        Schema::create('mus_course_links', function (Blueprint $t) {
            $t->uuid('source_goal_id')->primary();
            $t->foreign('source_goal_id')->references('id')->on('mus_goals');
            $t->foreignId('course_record_id')->constrained();
            $t->unsignedInteger('source_version');
            $t->timestamps();
        });
        Schema::create('mus_followups', function (Blueprint $t) {
            $t->id();
            $t->foreignId('conversation_id')->constrained('mus_conversations');
            $t->date('due_on');
            $t->date('completed_on')->nullable();
            $t->json('progress')->nullable();
            $t->foreignId('recorded_by')->nullable()->constrained('users');
            $t->unique(['conversation_id', 'due_on']);
            $t->timestamps();
        });
        Schema::create('mus_history', function (Blueprint $t) {
            $t->id();
            $t->foreignId('conversation_id')->constrained('mus_conversations');
            $t->foreignId('actor_id')->constrained('users');
            $t->string('event');
            $t->json('data')->nullable();
            $t->timestamp('created_at');
        });
        Schema::create('documents', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->string('category');
            $t->unsignedBigInteger('current_version_id')->nullable();
            $t->timestamps();
        });
        Schema::create('document_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained();
            $t->unsignedInteger('version');
            $t->string('status')->default('Kladde');
            $t->text('description');
            $t->longText('body')->nullable();
            $t->text('keywords')->nullable();
            $t->longText('pdf_text')->nullable();
            $t->foreignId('file_id')->nullable()->constrained('private_files');
            $t->foreignId('responsible_id')->constrained('users');
            $t->boolean('receipt_required')->default(false);
            $t->foreignId('department_id')->nullable()->constrained();
            $t->date('review_on')->nullable();
            $t->text('changes')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->unique(['document_id', 'version']);
            $t->timestamps();
        });
        Schema::create('read_receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('version_id')->constrained('document_versions');
            $t->foreignId('user_id')->constrained();
            $t->timestamp('read_at');
            $t->unique(['version_id', 'user_id']);
        });
        Schema::create('chemical_products', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('manufacturer')->nullable();
            $t->text('aliases')->nullable();
            $t->boolean('active')->default(true);
            $t->json('document_ids')->nullable();
            $t->timestamps();
        });
        Schema::create('announcements', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->text('body');
            $t->foreignId('department_id')->nullable()->constrained();
            $t->foreignId('author_id')->constrained('users');
            $t->timestamps();
        });
        Schema::create('competency_targets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('competency_id')->constrained();
            $t->foreignId('department_id')->nullable()->constrained();
            $t->unsignedInteger('minimum_people');
            $t->unsignedTinyInteger('minimum_level')->default(3);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['competency_targets', 'announcements', 'chemical_products', 'read_receipts', 'document_versions', 'documents', 'mus_history', 'mus_followups', 'mus_course_links', 'development_goals', 'mus_goals', 'mus_confirmations', 'mus_agreement_versions', 'mus_preparations', 'mus_conversations', 'course_records', 'course_competency', 'courses', 'profile_entries', 'competency_assessments', 'competencies', 'private_files', 'audit_events', 'team_assignments'] as $name) {
            Schema::dropIfExists($name);
        }
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('department_id');
            $t->dropColumn(['job_title', 'phone', 'is_leader', 'is_admin', 'active', 'revision', 'activated_at', 'first_login_at', 'last_login_at', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });
        Schema::dropIfExists('departments');
    }
};
