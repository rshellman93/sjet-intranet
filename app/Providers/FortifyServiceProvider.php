<?php

namespace App\Providers;

use App\Actions\ResetPassword;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Fortify::loginView(fn () => view('auth.login'));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.forgot'));
        Fortify::resetPasswordView(fn (Request $r) => view('auth.reset', ['request' => $r]));
        Fortify::confirmPasswordView(fn () => view('auth.confirm'));
        Fortify::resetUserPasswordsUsing(ResetPassword::class);
        Fortify::authenticateUsing(function (Request $r) {
            $user = User::where('email', mb_strtolower((string) $r->email))->where('active', true)->first();
            // Hash check is retained for missing accounts to avoid a fast user-enumeration path.
            $valid = Hash::check((string) $r->password, $user?->password ?? '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');

            return $user && $valid ? $user : null;
        });
        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(5)->by(mb_strtolower((string) $r->email).'|'.$r->ip()));
        Event::listen(Login::class, function ($event) {
            $event->user->forceFill(['first_login_at' => $event->user->first_login_at ?? now(), 'last_login_at' => now()])->save();
        });
    }
}
