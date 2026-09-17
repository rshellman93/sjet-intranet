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
        Schema::create('knowledge_folders', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
        });
        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedInteger('revision')->default(1);
            $table->uuid('creation_token')->nullable()->unique();
        });
        Schema::table('document_versions', function (Blueprint $table) {
            $table->string('title')->nullable();
            $table->foreignId('folder_id')->nullable()->constrained('knowledge_folders');
            $table->string('type')->default('article');
            $table->string('pdf_text_status')->nullable();
            $table->unsignedInteger('page_count')->nullable();
            $table->unsignedInteger('revision')->default(1);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('folder_id');
            $table->dropColumn(['title', 'type', 'pdf_text_status', 'page_count', 'revision']);
        });
        Schema::table('documents', function (Blueprint $table) {
            $table->dropUnique(['creation_token']);
            $table->dropColumn(['revision', 'creation_token']);
        });
        Schema::dropIfExists('knowledge_folders');
    }
};
