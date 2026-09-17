<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kls_skills', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->string('category');
            $t->string('kind');
            $t->timestamps();
        });
        Schema::create('kls_records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('skill_id')->constrained('kls_skills');
            $t->unsignedTinyInteger('value');
            $t->date('assessed_on')->nullable();
            $t->date('expires_on')->nullable();
            $t->foreignId('recorded_by')->constrained('users');
            $t->text('basis');
            $t->string('import_key')->nullable()->unique();
            $t->timestamps();
            $t->index(['user_id', 'skill_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kls_records');
        Schema::dropIfExists('kls_skills');
    }
};
