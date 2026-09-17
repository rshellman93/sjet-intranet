<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminController extends Controller
{
    private function check(Request $r): void
    {
        abort_unless($r->user()->is_admin, 403);
    }

    public function index(Request $r)
    {
        $this->check($r);

        return view('admin', ['people' => User::orderBy('name')->get(),
            'events' => DB::table('audit_events')->leftJoin('users', 'users.id', '=', 'audit_events.actor_id')->select('audit_events.*', 'users.name as actor')->orderByDesc('audit_events.id')->limit(15)->get()]);
    }

    public function edit(Request $r, ?int $id = null)
    {
        $this->check($r);

        return view('user-edit', ['person' => $id ? User::findOrFail($id) : new User(['active' => true]),
            'leaders' => User::where('is_leader', true)->where('active', true)->when($id, fn ($q) => $q->where('id', '!=', $id))->orderBy('name')->get(),
            'assigned' => $id ? DB::table('team_assignments')->where('employee_id', $id)->pluck('leader_id')->all() : []]);
    }

    public function save(Request $r, ?int $id = null)
    {
        $this->check($r);
        $r->merge(['email' => mb_strtolower((string) $r->input('email'))]);
        $data = $r->validate(['name' => 'required|string|max:120', 'email' => ['required', 'email', 'max:190', Rule::unique('users')->ignore($id)],
            'job_title' => 'nullable|string|max:120', 'phone' => 'nullable|string|max:40',
            'password' => $id ? 'prohibited' : 'required|string|min:12|max:200', 'revision' => $id ? 'required|integer' : 'nullable',
            'is_admin' => 'nullable|boolean', 'is_leader' => 'nullable|boolean', 'active' => 'nullable|boolean',
            'leaders' => 'nullable|array', 'leaders.*' => ['integer', 'distinct', Rule::exists('users', 'id')->where('active', true)->where('is_leader', true)]]);
        DB::transaction(function () use ($r, $id, $data) {
            $person = $id ? User::findOrFail($id) : new User;
            if ($id && $person->revision !== (int) $data['revision']) {
                throw ValidationException::withMessages(['revision' => 'En anden har ændret medarbejderen. Genindlæs siden før du gemmer.']);
            }
            if ($id === $r->user()->id && (! $r->boolean('active') || ! $r->boolean('is_admin'))) {
                throw ValidationException::withMessages(['active' => 'Du kan ikke deaktivere din egen konto eller fjerne din egen administratorrolle.']);
            }
            $leaders = array_map('intval', $data['leaders'] ?? []);
            if ($id && in_array($id, $leaders, true)) {
                throw ValidationException::withMessages(['leaders' => 'En medarbejder kan ikke være sin egen leder.']);
            }
            $before = $person->exists ? $person->only(['active', 'is_admin', 'is_leader']) : null;
            $person->forceFill(collect($data)->only(['name', 'email', 'job_title', 'phone'])->all());
            $person->email = mb_strtolower($person->email);
            $person->active = $r->boolean('active');
            $person->is_admin = $r->boolean('is_admin');
            $person->is_leader = $r->boolean('is_leader');
            if (! $id) {
                $person->password = $data['password'];
            }
            if ($person->active && ! $person->activated_at) {
                $person->activated_at = now();
            }
            $person->revision = ($person->revision ?? 0) + 1;
            $person->save();
            DB::table('team_assignments')->where('employee_id', $person->id)->delete();
            foreach ($leaders as $leader) {
                DB::table('team_assignments')->insert(['leader_id' => $leader, 'employee_id' => $person->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            // Team administration never changes participant access on existing MUS conversations.
            if (! $person->active || ! $person->is_leader) {
                DB::table('team_assignments')->where('leader_id', $person->id)->delete();
            }
            if (! $person->active || ($before && ($before['is_admin'] !== $person->is_admin || $before['is_leader'] !== $person->is_leader))) {
                DB::table('sessions')->where('user_id', $person->id)->delete();
                $person->forceFill(['remember_token' => null])->save();
            }
            Audit::record($id ? 'user_updated' : 'user_created', 'user', $person->id, ['before' => $before, 'after' => $person->only(['active', 'is_admin', 'is_leader']), 'leader_ids' => $leaders]);
        });

        return redirect('/administration')->with('success', 'Medarbejderen er gemt.');
    }

    public function resetPassword(Request $r, int $id)
    {
        $this->check($r);
        $data = $r->validate([
            'current_password' => 'required|current_password:web',
            'password' => 'required|string|min:12|max:200|confirmed',
            'revision' => 'required|integer',
        ]);
        DB::transaction(function () use ($id, $data) {
            $person = User::lockForUpdate()->findOrFail($id);
            if ($person->revision !== (int) $data['revision']) {
                throw ValidationException::withMessages(['revision' => 'Medarbejderen er ændret. Genindlæs siden før du nulstiller adgangskoden.']);
            }
            $person->forceFill(['password' => $data['password'], 'remember_token' => null, 'revision' => $person->revision + 1])->save();
            DB::table('sessions')->where('user_id', $id)->delete();
            DB::table('password_reset_tokens')->where('email', $person->email)->delete();
            Audit::record('password_reset_by_admin', 'user', $id);
        });
        if ($id === $r->user()->id) {
            auth()->logout();
            $r->session()->invalidate();
            $r->session()->regenerateToken();

            return redirect('/login')->with('success', 'Adgangskoden er ændret. Log ind med den nye adgangskode.');
        }

        return redirect('/administration/medarbejder/'.$id)->with('success', 'Adgangskoden er ændret, og aktive sessioner er tilbagekaldt. Giv medarbejderen den nye adgangskode personligt.');
    }

    public function export(Request $r)
    {
        $this->check($r);
        Audit::record('access_exported', 'users', null);

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Eksporttidspunkt', 'Navn', 'Kontostatus', 'Adgang aktiveret', 'Første login', 'Seneste login'], ';', '"', '');

            $exported = now()->timezone('Europe/Copenhagen')->format('d/m/Y H:i:s P');
            foreach (User::orderBy('name')->cursor() as $u) {
                $safe = fn ($v) => preg_match('/^[=+@\-\t\r]/', (string) $v) ? "'".$v : $v;
                $date = fn ($v) => $v?->timezone('Europe/Copenhagen')->format('d/m/Y H:i:s P') ?? '';
                fputcsv($out, array_map($safe, [$exported, $u->name, $u->active ? 'Aktiv' : 'Deaktiveret', $date($u->activated_at), $date($u->first_login_at), $date($u->last_login_at)]), ';', '"', '');
            } fclose($out);
        }, 'adgangsoversigt-'.now()->format('d-m-Y').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }
}
