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
        Schema::create('employee_educations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->foreignId('updated_by')->constrained('users');
            $table->timestamps();
            $table->unique(['user_id', 'title']);
        });

        Schema::create('employee_driving_licenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 20);
            $table->date('expires_on');
            $table->foreignId('updated_by')->constrained('users');
            $table->timestamps();
            $table->unique(['user_id', 'category']);
        });

        Schema::create('employee_safety_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->date('expires_on');
            $table->foreignId('updated_by')->constrained('users');
            $table->timestamps();
            $table->unique(['user_id', 'title']);
        });

        Schema::create('competency_certificate_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('competency_id')->constrained()->cascadeOnDelete();
            $table->string('certificate_type');
            $table->unsignedBigInteger('certificate_id');
            $table->foreignId('updated_by')->constrained('users');
            $table->timestamps();
            $table->unique(['user_id', 'competency_id', 'certificate_type', 'certificate_id'], 'competency_certificate_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('competency_certificate_links');
        Schema::dropIfExists('employee_safety_certificates');
        Schema::dropIfExists('employee_driving_licenses');
        Schema::dropIfExists('employee_educations');
    }
};
