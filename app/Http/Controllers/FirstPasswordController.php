<?php

namespace App\Http\Controllers;

use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class FirstPasswordController extends Controller
{
    public function edit(Request $request): View|RedirectResponse
    {
        return $request->user()->must_change_password ? view('auth.first-password') : redirect('/');
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()->must_change_password, 403);
        $data = $request->validate([
            'current_password' => 'required|current_password:web',
            'password' => 'required|string|min:12|max:200|confirmed',
        ]);
        if (Hash::check($data['password'], $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'Vælg en anden adgangskode end startkoden.']);
        }
        DB::transaction(function () use ($request, $data) {
            $user = $request->user()->newQuery()->lockForUpdate()->findOrFail($request->user()->id);
            abort_unless($user->must_change_password, 409);
            $user->forceFill(['password' => $data['password'], 'must_change_password' => false, 'remember_token' => null, 'revision' => $user->revision + 1])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            Audit::record('initial_password_changed', 'user', $user->id);
        });
        auth()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login')->with('success', 'Din personlige adgangskode er gemt. Log ind med den nye kode.');
    }
}
