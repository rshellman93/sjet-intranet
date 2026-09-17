<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class KlsController extends Controller
{
    public function matrix(Request $request)
    {
        abort_unless($request->user()->is_admin || $request->user()->is_leader, 403);
        $request->validate(['q' => 'nullable|string|max:100', 'category' => 'nullable|string|max:100', 'role' => ['nullable', Rule::in(User::JOB_ROLES)]]);
        $skills = DB::table('kls_skills')->orderBy('id')->get();
        $categories = $skills->pluck('category')->unique()->values();
        $selectedCategory = $categories->contains($request->input('category'))
            ? $request->string('category')->toString()
            : ($categories->first(fn (string $category): bool => $category === 'Faglige kompetencer') ?? $categories->first());
        $visibleSkills = $skills->where('category', $selectedCategory)->values();
        $people = User::where('active', true)
            ->when(! $request->user()->is_admin, fn ($query) => $query->whereIn('id', DB::table('team_assignments')->where('leader_id', $request->user()->id)->pluck('employee_id')))
            ->when($request->filled('q'), fn ($query) => $query->where('name', 'like', '%'.$request->input('q').'%'))
            ->when($request->filled('role'), fn ($query) => $query->whereJsonContains('job_roles', $request->input('role')))
            ->orderBy('name')->get();
        $records = DB::table('kls_records')->whereIn('user_id', $people->pluck('id'))->whereIn('skill_id', $visibleSkills->pluck('id'))
            ->orderByDesc('id')->get()->unique(fn ($record): string => $record->user_id.':'.$record->skill_id)
            ->keyBy(fn ($record): string => $record->user_id.':'.$record->skill_id);

        if ($request->routeIs('competencies.export')) {
            return response()->streamDownload(function () use ($people, $visibleSkills, $records): void {
                $stream = fopen('php://output', 'w');
                fwrite($stream, "\xEF\xBB\xBF");
                $safe = fn ($value) => preg_match('/^[\s]*[=+@-]/u', (string) $value) ? "'".$value : $value;
                fputcsv($stream, array_map($safe, array_merge(['Medarbejder', 'Stilling'], $visibleSkills->pluck('name')->all())), ';', '"', '');
                foreach ($people as $person) {
                    $row = [$person->name, $person->jobRolesLabel()];
                    foreach ($visibleSkills as $skill) {
                        $record = $records->get($person->id.':'.$skill->id);
                        $row[] = ! $record ? 'Ikke registreret' : ($skill->kind === 'boolean' ? ($record->value ? 'Ja' : 'Nej') : $record->value);
                    }
                    fputcsv($stream, array_map($safe, $row), ';', '"', '');
                }
                fclose($stream);
            }, 'kompetencer.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
        }

        return view('development.kls-matrix', compact('people', 'skills', 'categories', 'selectedCategory', 'visibleSkills', 'records'));
    }

    public function card(Request $request, int $id)
    {
        abort_unless($request->user()->canSeeDevelopmentData($id), 403);

        return redirect('/profil/'.$id.'?tab=kompetencer');
    }

    public function save(Request $request, int $id)
    {
        abort_unless($request->user()->canAssess($id), 403);
        User::findOrFail($id);
        $data = $request->validate(['skill_id' => 'required|integer|exists:kls_skills,id', 'value' => 'required|integer|min:0|max:4', 'assessed_on' => 'required|date_format:Y-m-d|before_or_equal:today', 'basis' => 'nullable|string|max:2000', 'previous_id' => 'required|integer|min:0']);
        $data['basis'] = $data['basis'] ?? '';
        $skill = DB::table('kls_skills')->find($data['skill_id']);
        abort_unless($skill->kind === 'rated' || $request->user()->is_admin, 403);
        if (! $request->user()->is_admin) {
            unset($data['expires_on']);
        }
        if ($skill->kind === 'boolean') {
            $request->validate(['value' => Rule::in([0, 1])]);
        }
        DB::transaction(function () use ($request, $id, $data): void {
            User::whereKey($id)->lockForUpdate()->firstOrFail();
            $latest = DB::table('kls_records')->where('user_id', $id)->where('skill_id', $data['skill_id'])->max('id') ?? 0;
            abort_unless((int) $latest === (int) $data['previous_id'], 409, 'Vurderingen er ændret. Genindlæs siden.');
            unset($data['previous_id']);
            $record = DB::table('kls_records')->insertGetId($data + ['user_id' => $id, 'recorded_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            Audit::record('kls_recorded', 'kls_record', $record, ['user_id' => $id]);
        });

        return back()->with('success', 'Registreringen er gemt. Tidligere vurderinger er bevaret.');
    }

    public function skill(Request $request)
    {
        abort_unless($request->user()->is_admin, 403);
        $data = $request->validate(['name' => 'required|string|max:190|unique:kls_skills,name', 'category' => 'required|string|max:100', 'kind' => ['required', Rule::in(['rated', 'boolean'])]]);
        $id = DB::table('kls_skills')->insertGetId($data + ['created_at' => now(), 'updated_at' => now()]);
        Audit::record('kls_skill_created', 'kls_skill', $id);

        return back()->with('success', 'Kompetencen er oprettet.');
    }
}
