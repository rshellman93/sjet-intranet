<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Audit;
use App\Support\Development;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DevelopmentController extends Controller
{
    public function card(Request $request, int $id): View
    {
        abort_unless($request->user()->canSeeDevelopmentData($id), 403);
        $person = User::findOrFail($id);
        $records = DB::table('course_records')->where('user_id', $id)->orderByDesc('created_at')->orderByDesc('id')->get();
        $licenses = DB::table('employee_driving_licenses')->where('user_id', $id)->orderBy('category')->get();
        $safetyCertificates = DB::table('employee_safety_certificates')->where('user_id', $id)->orderBy('title')->get();

        return view('development.card', ['person' => $person, 'competencies' => DB::table('competencies')->orderBy('name')->get(), 'assessments' => Development::latestAssessments($id),
            'assessmentHistory' => DB::table('competency_assessments')->where('user_id', $id)->orderByDesc('assessed_on')->orderByDesc('id')->get(),
            'people' => User::pluck('name', 'id'), 'records' => $records, 'transfers' => DB::table('mus_transfer_history')->whereIn('course_record_id', $records->pluck('id'))->orderByDesc('id')->get()->groupBy('course_record_id'), 'entries' => DB::table('profile_entries')->where('user_id', $id)->get(),
            'goals' => DB::table('development_goals')->where('user_id', $id)->get(), 'levels' => Development::LEVELS,
            'educationOptions' => Development::EDUCATIONS, 'safetyOptions' => Development::SAFETY_CERTIFICATES,
            'educations' => DB::table('employee_educations')->where('user_id', $id)->orderBy('title')->get(),
            'licenses' => $licenses, 'safetyCertificates' => $safetyCertificates,
            'certificateLinks' => $this->certificateLinks($id, $licenses, $safetyCertificates),
            'certificateOptions' => $this->certificateOptions($licenses, $safetyCertificates)]);
    }

    public function assess(Request $request, int $id): RedirectResponse
    {
        abort_unless($request->user()->canManageDevelopmentData(), 403);
        User::findOrFail($id);
        $data = $request->validate(['competency_id' => 'required|integer|exists:competencies,id', 'level' => ['required', Rule::in(array_keys(Development::LEVELS))], 'assessed_on' => 'required|date_format:Y-m-d|before_or_equal:today', 'basis' => 'required|string|max:2000', 'submission_id' => 'required|uuid', 'visible_to_colleagues' => 'nullable|boolean']);
        DB::transaction(function () use ($request, $id, $data) {
            if (DB::table('competency_assessments')->where('submission_id', $data['submission_id'])->exists()) {
                return;
            }
            $row = DB::table('competency_assessments')->insertGetId(array_merge($data, ['visible_to_colleagues' => $request->boolean('visible_to_colleagues') && (int) $data['level'] >= 3, 'user_id' => $id, 'assessor_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]));
            Audit::record('competency_assessed', 'competency_assessment', $row, ['user_id' => $id, 'competency_id' => $data['competency_id'], 'level' => $data['level']]);
        });

        return back()->with('success', 'Den faglige vurdering er registreret. Historikken er bevaret.');
    }

    public function entry(Request $request, int $id): RedirectResponse
    {
        abort_unless($request->user()->canManageDevelopmentData(), 403);
        User::findOrFail($id);
        $data = $request->validate(['type' => ['required', Rule::in(['education', 'experience'])], 'title' => 'required|string|max:190', 'period' => 'nullable|string|max:100', 'description' => 'nullable|string|max:1000', 'submission_id' => 'required|uuid']);
        DB::transaction(function () use ($data, $id) {
            DB::table('profile_entries')->insertOrIgnore(array_merge($data, ['user_id' => $id, 'created_at' => now(), 'updated_at' => now()]));
            Audit::record('profile_entry_added', 'user', $id);
        });

        return back()->with('success', 'Oplysningen er gemt.');
    }

    public function review(Request $request, int $id): RedirectResponse
    {
        abort_unless($request->user()->canManageDevelopmentData(), 403);
        $person = User::findOrFail($id);
        $data = $request->validate(['reviewed_on' => 'required|date_format:Y-m-d|before_or_equal:today']);
        DB::transaction(function () use ($person, $data, $request) {
            $person->forceFill(['competency_reviewed_on' => $data['reviewed_on'], 'competency_reviewed_by' => $request->user()->id])->save();
            Audit::record('competency_card_reviewed', 'user', $person->id);
        });

        return back()->with('success', 'Gennemgangen er registreret. Det er ikke en digital underskrift.');
    }

    public function courseForm(Request $request, int $id, ?int $record = null): View
    {
        abort_unless($request->user()->canManageDevelopmentData(), 403);
        $course = $record ? DB::table('course_records')->where('user_id', $id)->find($record) : null;
        abort_if($record && ! $course, 404);

        return view('development.course', ['person' => User::findOrFail($id), 'record' => $course, 'courses' => DB::table('courses')->orderBy('name')->get()]);
    }

    public function courseSave(Request $request, int $id, ?int $record = null): RedirectResponse
    {
        abort_unless($request->user()->canManageDevelopmentData(), 403);
        User::findOrFail($id);
        $data = $request->validate(['course_id' => 'nullable|integer|exists:courses,id', 'topic' => 'required|string|max:190', 'status' => ['required', Rule::in(Development::STATUSES)],
            'date_precision' => ['required', Rule::in(['date', 'year', 'interval', 'unknown'])], 'original_date' => 'nullable|string|max:100', 'completed_on' => 'nullable|date_format:Y-m-d|before_or_equal:today',
            'planned_on' => 'nullable|date_format:Y-m-d', 'provider' => 'nullable|string|max:190', 'valid_until' => 'nullable|date_format:Y-m-d', 'refresh_on' => 'nullable|date_format:Y-m-d', 'note' => 'nullable|string|max:2000',
            'revision' => $record ? 'required|integer' : 'nullable', 'submission_id' => $record ? 'prohibited' : 'required|uuid', 'certificate' => 'nullable|file|mimes:pdf|max:20480']);
        if ($data['status'] === 'Gennemført') {
            if ($data['date_precision'] === 'date' && empty($data['completed_on'])) {
                throw ValidationException::withMessages(['completed_on' => 'Angiv gennemførelsesdatoen, eller vælg den præcision, som historikken faktisk har.']);
            }
            if ($data['date_precision'] === 'year' && ! preg_match('/^\d{4}$/', $data['original_date'] ?? '')) {
                throw ValidationException::withMessages(['original_date' => 'Angiv et årstal med fire cifre.']);
            }
            if ($data['date_precision'] === 'interval' && ! preg_match('/^\d{4}\s*[-–]\s*\d{4}$/u', $data['original_date'] ?? '')) {
                throw ValidationException::withMessages(['original_date' => 'Angiv perioden, eksempelvis 2006–2008.']);
            }
        }
        if ($data['status'] !== 'Gennemført' || $data['date_precision'] !== 'date') {
            $data['completed_on'] = null;
        }
        if (! empty($data['completed_on']) && ! empty($data['valid_until']) && $data['valid_until'] < $data['completed_on']) {
            throw ValidationException::withMessages(['valid_until' => 'Gyldighedsdatoen kan ikke være før gennemførelsen.']);
        }
        unset($data['certificate']);
        $path = null;
        try {
            DB::transaction(function () use ($request, $id, $record, $data, &$path) {
                $current = $record ? DB::table('course_records')->where('user_id', $id)->find($record) : null;
                abort_if($record && ! $current, 404);
                if (! $record && DB::table('course_records')->where('submission_id', $data['submission_id'])->exists()) {
                    return;
                }
                if ($current && (int) $current->revision !== (int) $data['revision']) {
                    throw ValidationException::withMessages(['revision' => 'Kurset er ændret af en anden. Genindlæs før du gemmer.']);
                }
                if ($request->hasFile('certificate')) {
                    $file = $request->file('certificate');
                    $path = $file->store('certificates', 'local');
                    $data['certificate_id'] = DB::table('private_files')->insertGetId(['owner_id' => $id, 'uploaded_by' => $request->user()->id, 'name' => mb_substr(basename($file->getClientOriginalName()), 0, 200), 'path' => $path, 'mime' => 'application/pdf', 'size' => $file->getSize(), 'purpose' => 'certificate', 'created_at' => now(), 'updated_at' => now()]);
                }
                $data['revision'] = ($current?->revision ?? 0) + 1;
                $data['updated_by'] = $request->user()->id;
                $data['updated_at'] = now();
                if ($current) {
                    DB::table('course_records')->where('id', $record)->update($data);
                } else {
                    $record = DB::table('course_records')->insertGetId(array_merge($data, ['user_id' => $id, 'created_at' => now()]));
                }
                Audit::record('course_record_saved', 'course_record', $record, ['user_id' => $id, 'status' => $data['status']]);
            });
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            } throw $exception;
        }

        return redirect()->route('development.card', $id)->with('success', 'Kursusregistreringen er gemt. Kompetenceniveauet er uændret.');
    }

    public function matrix(Request $request): View
    {
        abort_unless($request->user()->is_admin || $request->user()->is_leader, 403);
        $query = User::where('active', true);
        if (! $request->user()->is_admin) {
            $query->whereIn('id', DB::table('team_assignments')->where('leader_id', $request->user()->id)->pluck('employee_id'));
        }
        $people = $query->orderBy('name')->get();
        $assessments = [];
        foreach ($people as $person) {
            $assessments[$person->id] = Development::latestAssessments($person->id);
        }

        return view('development.matrix', ['people' => $people, 'assessments' => $assessments, 'competencies' => DB::table('competencies')->orderBy('name')->get(), 'levels' => Development::LEVELS]);
    }

    public function catalog(Request $request): View
    {
        abort_unless($request->user()->canManageDevelopmentData(), 403);

        return view('development.catalog', ['competencies' => DB::table('competencies')->orderBy('name')->get(), 'courses' => DB::table('courses')->orderBy('name')->get()]);
    }

    public function catalogSave(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageDevelopmentData(), 403);
        $data = $request->validate(['kind' => ['required', Rule::in(['competencies', 'courses'])], 'id' => 'nullable|integer', 'name' => 'required|string|max:190']);
        Validator::make($data, ['name' => Rule::unique($data['kind'], 'name')->ignore($data['id'] ?? null)])->validate();
        DB::transaction(function () use ($data) {
            if (! empty($data['id'])) {
                abort_unless(DB::table($data['kind'])->where('id', $data['id'])->exists(), 404);
                DB::table($data['kind'])->where('id', $data['id'])->update(['name' => $data['name'], 'updated_at' => now()]);
            } else {
                $data['id'] = DB::table($data['kind'])->insertGetId(['name' => $data['name'], 'created_at' => now(), 'updated_at' => now()]);
            }
            Audit::record('catalog_updated', $data['kind'], (int) $data['id']);
        });

        return back()->with('success', 'Kataloget er opdateret.');
    }

    public function educationSave(Request $request, int $id): RedirectResponse
    {
        abort_unless($request->user()->canManageDevelopmentData(), 403);
        User::findOrFail($id);
        $data = $request->validate(['title' => ['required', Rule::in(Development::EDUCATIONS)], 'note' => 'nullable|string|max:2000']);
        DB::table('employee_educations')->updateOrInsert(
            ['user_id' => $id, 'title' => $data['title']],
            ['note' => $data['note'] ?? null, 'updated_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]
        );
        Audit::record('employee_education_saved', 'user', $id, $data);

        return back()->with('success', 'Grunduddannelsen er gemt.');
    }

    public function educationDelete(Request $request, int $id, int $education): RedirectResponse
    {
        abort_unless($request->user()->canManageDevelopmentData(), 403);
        DB::table('employee_educations')->where('user_id', $id)->where('id', $education)->delete();
        Audit::record('employee_education_deleted', 'user', $id, ['education_id' => $education]);

        return back()->with('success', 'Grunduddannelsen er fjernet.');
    }

    public function licenseSave(Request $request, int $id): RedirectResponse
    {
        abort_unless($request->user()->canManageDevelopmentData(), 403);
        User::findOrFail($id);
        $data = $request->validate(['category' => 'required|string|max:20', 'note' => 'nullable|string|max:2000']);
        $data['category'] = mb_strtoupper(trim($data['category']));
        DB::table('employee_driving_licenses')->updateOrInsert(
            ['user_id' => $id, 'category' => $data['category']],
            ['expires_on' => null, 'note' => $data['note'] ?? null, 'updated_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]
        );
        Audit::record('employee_driving_license_saved', 'user', $id, $data);

        return back()->with('success', 'Kørekortet er gemt.');
    }

    public function licenseDelete(Request $request, int $id, int $license): RedirectResponse
    {
        abort_unless($request->user()->canManageDevelopmentData(), 403);
        DB::table('employee_driving_licenses')->where('user_id', $id)->where('id', $license)->delete();
        DB::table('competency_certificate_links')->where('user_id', $id)->where('certificate_type', 'driving_license')->where('certificate_id', $license)->delete();
        Audit::record('employee_driving_license_deleted', 'user', $id, ['license_id' => $license]);

        return back()->with('success', 'Kørekortet er fjernet.');
    }

    public function safetyCertificateSave(Request $request, int $id): RedirectResponse
    {
        abort_unless($request->user()->canManageDevelopmentData(), 403);
        User::findOrFail($id);
        $data = $request->validate(['title' => ['required', Rule::in(Development::SAFETY_CERTIFICATES)], 'expires_on' => 'required|date_format:Y-m-d']);
        DB::table('employee_safety_certificates')->updateOrInsert(
            ['user_id' => $id, 'title' => $data['title']],
            ['expires_on' => $data['expires_on'], 'updated_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]
        );
        Audit::record('employee_safety_certificate_saved', 'user', $id, $data);

        return back()->with('success', 'Certifikatet er gemt.');
    }

    public function safetyCertificateDelete(Request $request, int $id, int $certificate): RedirectResponse
    {
        abort_unless($request->user()->canManageDevelopmentData(), 403);
        DB::table('employee_safety_certificates')->where('user_id', $id)->where('id', $certificate)->delete();
        DB::table('competency_certificate_links')->where('user_id', $id)->where('certificate_type', 'safety_certificate')->where('certificate_id', $certificate)->delete();
        Audit::record('employee_safety_certificate_deleted', 'user', $id, ['certificate_id' => $certificate]);

        return back()->with('success', 'Certifikatet er fjernet.');
    }

    public function certificateLinkSave(Request $request, int $id): RedirectResponse
    {
        abort_unless($request->user()->canManageDevelopmentData(), 403);
        User::findOrFail($id);
        $data = $request->validate(['competency_id' => 'required|integer|exists:competencies,id', 'certificate' => 'required|string']);
        [$type, $certificateId] = array_pad(explode(':', $data['certificate'], 2), 2, null);
        abort_unless(in_array($type, ['driving_license', 'safety_certificate'], true) && ctype_digit((string) $certificateId), 422);
        $table = $type === 'driving_license' ? 'employee_driving_licenses' : 'employee_safety_certificates';
        abort_unless(DB::table($table)->where('user_id', $id)->where('id', (int) $certificateId)->exists(), 404);
        DB::table('competency_certificate_links')->updateOrInsert(
            ['user_id' => $id, 'competency_id' => $data['competency_id'], 'certificate_type' => $type, 'certificate_id' => (int) $certificateId],
            ['updated_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]
        );
        Audit::record('competency_certificate_link_saved', 'user', $id, ['competency_id' => $data['competency_id'], 'certificate_type' => $type, 'certificate_id' => (int) $certificateId]);

        return back()->with('success', 'Certifikatet er knyttet til kompetencen.');
    }

    public function certificateLinkDelete(Request $request, int $id, int $link): RedirectResponse
    {
        abort_unless($request->user()->canManageDevelopmentData(), 403);
        DB::table('competency_certificate_links')->where('user_id', $id)->where('id', $link)->delete();
        Audit::record('competency_certificate_link_deleted', 'user', $id, ['link_id' => $link]);

        return back()->with('success', 'Tilknytningen er fjernet.');
    }

    private function certificateOptions($licenses, $safetyCertificates)
    {
        return $licenses->map(fn ($license) => (object) ['value' => 'driving_license:'.$license->id, 'label' => 'Kørekort '.$license->category])
            ->concat($safetyCertificates->map(fn ($certificate) => (object) ['value' => 'safety_certificate:'.$certificate->id, 'label' => $certificate->title]));
    }

    private function certificateLinks(int $id, $licenses, $safetyCertificates)
    {
        $licenses = $licenses->keyBy('id');
        $safetyCertificates = $safetyCertificates->keyBy('id');

        return DB::table('competency_certificate_links')
            ->join('competencies', 'competencies.id', '=', 'competency_certificate_links.competency_id')
            ->where('competency_certificate_links.user_id', $id)
            ->orderBy('competencies.name')
            ->select('competency_certificate_links.*', 'competencies.name as competency_name')
            ->get()
            ->map(function ($link) use ($licenses, $safetyCertificates) {
                $source = $link->certificate_type === 'driving_license' ? $licenses->get($link->certificate_id) : $safetyCertificates->get($link->certificate_id);
                $link->certificate_title = $source ? ($link->certificate_type === 'driving_license' ? 'Kørekort '.$source->category : $source->title) : 'Slettet certificering';
                $link->expires_on = $source?->expires_on;

                return $link;
            });
    }
}
