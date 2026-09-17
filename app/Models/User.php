<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $guarded = ['id'];

    protected $attributes = ['revision' => 1, 'active' => true, 'is_admin' => false, 'is_leader' => false];

    public const JOB_ROLES = ['Lærling', 'Voksenlærling', 'Elektriker', 'Pladsformand', 'Serviceleder', 'Direktør', 'Installatør'];

    public function jobRolesLabel(): string
    {
        $roles = $this->job_roles ?? [];
        if (is_string($roles)) {
            $roles = json_decode($roles, true) ?: [];
        }

        return $roles !== [] ? implode(' · ', $roles) : ($this->job_title ?? 'Stilling ikke angivet');
    }

    public function canSeeProfile(int $id): bool
    {
        return $this->id === $id || $this->is_admin || $this->leads($id);
    }

    public function canSeePublicProfile(int $id): bool
    {
        return $this->id === $id || $this->is_admin || User::where('id', $id)->where('active', true)->exists();
    }

    public function canSeeDevelopmentData(int $id): bool
    {
        return $this->id === $id || $this->is_admin || $this->leads($id);
    }

    public function canManageDevelopmentData(): bool
    {
        return $this->is_admin;
    }

    public function canAssess(int $id): bool
    {
        return $this->is_admin || ((int) $this->id !== $id && $this->leads($id));
    }

    public function leads(int $id): bool
    {
        return $this->is_leader && DB::table('team_assignments')->where('leader_id', $this->id)->where('employee_id', $id)->exists();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'job_roles' => 'array',
            'is_admin' => 'boolean', 'is_leader' => 'boolean', 'active' => 'boolean',
            'activated_at' => 'datetime', 'first_login_at' => 'datetime', 'last_login_at' => 'datetime', 'two_factor_confirmed_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
