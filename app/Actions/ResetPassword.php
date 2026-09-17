<?php

namespace App\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetPassword implements ResetsUserPasswords
{
    public function reset($user, array $input): void
    {
        Validator::make($input, ['password' => ['required', 'confirmed', Password::min(12)]])->validate();
        DB::transaction(function () use ($user, $input) {
            $user->forceFill(['password' => $input['password'], 'remember_token' => null])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
        });
    }
}
