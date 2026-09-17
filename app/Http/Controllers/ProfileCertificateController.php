<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProfileCertificateController extends Controller
{
    public function save(Request $request, int $id)
    {
        abort_unless($request->user()->is_admin, 403);
        User::findOrFail($id);
        $linkOnly = $request->input('mode') === 'link' && $request->filled('certificate_id');
        $link = $request->input('mode') === 'link';
        $data = $request->validate([
            'mode' => ['required', Rule::in(['save', 'link'])],
            'certificate_id' => ['nullable', 'integer', Rule::exists('employee_safety_certificates', 'id')->where('user_id', $id)],
            'title' => [$linkOnly ? 'nullable' : 'required', 'string', 'max:190'],
            'never_expires' => 'nullable|boolean',
            'expires_on' => [$linkOnly ? 'nullable' : Rule::requiredIf(! $request->boolean('never_expires')), 'nullable', 'date_format:Y-m-d'],
            'note' => 'nullable|string|max:2000',
            'certificate_file' => 'nullable|file|mimes:pdf|max:20480',
            'skill_id' => ['nullable', Rule::requiredIf($link && ! $request->filled('new_skill')), 'integer', Rule::exists('kls_skills', 'id')->where('kind', 'rated')],
            'new_skill' => ['nullable', 'string', 'max:190'],
        ]);
        $path = null;
        try {
            DB::transaction(function () use ($request, $id, $data, $linkOnly, $link, &$path): void {
                User::whereKey($id)->lockForUpdate()->firstOrFail();
                $certificateId = $data['certificate_id'] ?? null;
                if (! $linkOnly) {
                    $row = ['title' => trim($data['title']), 'expires_on' => $request->boolean('never_expires') ? null : $data['expires_on'],
                        'never_expires' => $request->boolean('never_expires'), 'note' => $data['note'] ?? null, 'updated_by' => $request->user()->id, 'updated_at' => now()];
                    if ($request->hasFile('certificate_file')) {
                        $file = $request->file('certificate_file');
                        $path = $file->store('certificates', 'local');
                        $row['file_id'] = DB::table('private_files')->insertGetId(['owner_id' => $id, 'uploaded_by' => $request->user()->id,
                            'name' => mb_substr(basename($file->getClientOriginalName()), 0, 200), 'path' => $path, 'mime' => 'application/pdf', 'size' => $file->getSize(), 'purpose' => 'certificate', 'created_at' => now(), 'updated_at' => now()]);
                    }
                    $duplicate = DB::table('employee_safety_certificates')->where('user_id', $id)->where('title', $row['title'])->first();
                    if ($certificateId && $duplicate && (int) $duplicate->id !== (int) $certificateId) {
                        throw ValidationException::withMessages(['title' => 'Certifikatet findes allerede på profilen.']);
                    }
                    $certificateId ??= $duplicate?->id;
                    if ($certificateId) {
                        DB::table('employee_safety_certificates')->where('user_id', $id)->where('id', $certificateId)->update($row);
                    } else {
                        $certificateId = DB::table('employee_safety_certificates')->insertGetId($row + ['user_id' => $id, 'created_at' => now()]);
                    }
                }
                if ($link) {
                    $skillId = $data['skill_id'] ?? null;
                    if (! $skillId) {
                        $name = trim($data['new_skill']);
                        $existing = DB::table('kls_skills')->where('name', $name)->first();
                        if ($existing && $existing->kind !== 'rated') {
                            throw ValidationException::withMessages(['new_skill' => 'Navnet bruges allerede til en anden type registrering.']);
                        }
                        $skillId = $existing?->id ?? DB::table('kls_skills')->insertGetId(['name' => $name, 'kind' => 'rated', 'category' => 'Faglige kompetencer', 'created_at' => now(), 'updated_at' => now()]);
                    }
                    DB::table('skill_certificate_links')->updateOrInsert(['user_id' => $id, 'skill_id' => $skillId, 'certificate_id' => $certificateId],
                        ['updated_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
                }
                if (! $linkOnly) {
                    $legacy = DB::table('kls_skills')->where('name', $row['title'])->where('kind', 'boolean')->first();
                    if ($legacy) {
                        DB::table('kls_records')->insert(['user_id' => $id, 'skill_id' => $legacy->id, 'value' => 1,
                            'assessed_on' => now()->toDateString(), 'expires_on' => $row['expires_on'], 'never_expires' => $row['never_expires'],
                            'basis' => $row['note'] ?? '', 'recorded_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
                    }
                }
                Audit::record('profile_certificate_saved', 'employee_safety_certificate', (int) $certificateId, ['user_id' => $id]);
            });
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }

        return back()->with('success', 'Certifikatet er gemt.');
    }

    public function unlink(Request $request, int $id, int $link)
    {
        abort_unless($request->user()->is_admin, 403);
        DB::table('skill_certificate_links')->where('user_id', $id)->where('id', $link)->delete();
        Audit::record('skill_certificate_unlinked', 'user', $id, ['link_id' => $link]);

        return back()->with('success', 'Tilknytningen er fjernet.');
    }
}
