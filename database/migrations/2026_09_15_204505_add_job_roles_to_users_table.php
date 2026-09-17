<?php

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
        Schema::table('users', function (Blueprint $table) {
            $table->json('job_roles')->nullable()->after('job_title');
        });

        $roles = ['Lærling', 'Voksenlærling', 'Elektriker', 'Pladsformand', 'Serviceleder', 'Direktør', 'Installatør'];
        DB::table('users')->whereNotNull('job_title')->orderBy('id')->eachById(function (object $user) use ($roles): void {
            $matchedRoles = array_values(array_filter($roles, fn (string $role): bool => mb_stripos($user->job_title, $role) !== false));

            if ($matchedRoles !== []) {
                DB::table('users')->where('id', $user->id)->update(['job_roles' => json_encode($matchedRoles, JSON_UNESCAPED_UNICODE)]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('job_roles');
        });
    }
};
