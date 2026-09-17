<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shared_tools', function (Blueprint $table) {
            $table->id();
            $table->string('asset_number', 80)->unique();
            $table->string('name', 190);
            $table->string('category', 120)->nullable();
            $table->string('manufacturer', 120)->nullable();
            $table->string('model', 120)->nullable();
            $table->string('serial_number', 190)->nullable();
            $table->text('note')->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('updated_by')->constrained('users');
            $table->timestamps();
            $table->index(['active', 'name']);
        });

        Schema::create('shared_tool_loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tool_id')->constrained('shared_tools');
            $table->foreignId('user_id')->constrained('users');
            $table->string('site', 190)->nullable();
            $table->text('checkout_note')->nullable();
            $table->timestamp('checked_out_at');
            $table->foreignId('checked_out_by')->constrained('users');
            $table->timestamp('returned_at')->nullable();
            $table->foreignId('returned_by')->nullable()->constrained('users');
            $table->text('return_note')->nullable();
            $table->timestamps();
            $table->index(['tool_id', 'returned_at']);
            $table->index(['user_id', 'returned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_tool_loans');
        Schema::dropIfExists('shared_tools');
    }
};
