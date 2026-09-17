<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shared_tools', function (Blueprint $table) {
            $table->boolean('is_fleet')->default(false)->after('note');
            $table->boolean('is_leased')->default(false)->after('is_fleet');
        });
        Schema::create('shared_tool_serial_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tool_id')->constrained('shared_tools')->cascadeOnDelete();
            $table->string('serial_number', 190)->unique();
            $table->timestamps();
            $table->index('tool_id');
        });
        foreach (DB::table('shared_tools')->get(['id', 'serial_number']) as $tool) {
            $serials = collect(preg_split('/[+\r\n]+/', $tool->serial_number ?? ''))->map(fn ($serial) => trim($serial))->filter()->unique();
            foreach ($serials as $serial) {
                DB::table('shared_tool_serial_numbers')->insertOrIgnore(['tool_id' => $tool->id, 'serial_number' => $serial, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        DB::table('shared_tools')->update(['is_fleet' => true]);
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_tool_serial_numbers');
        Schema::table('shared_tools', fn (Blueprint $table) => $table->dropColumn(['is_fleet', 'is_leased']));
    }
};
