<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureActive
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user() && ! $request->user()->active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $request->expectsJson() ? response()->json(['message' => 'Din konto er deaktiveret.'], 403) : redirect('/login')->withErrors(['email' => 'Din konto er deaktiveret. Kontakt administratoren.']);
        }
        if ($request->user()?->must_change_password && ! $request->routeIs('password.first', 'password.first.update', 'logout')) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Du skal ændre din startkode før du fortsætter.'], 403)
                : redirect()->route('password.first');
        }
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
