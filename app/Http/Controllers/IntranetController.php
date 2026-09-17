<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Audit;
use App\Support\Development;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class IntranetController extends Controller
{
    public function home(Request $r)
    {
        return view('home', [
            'colleagues' => User::where('active', true)->count(),
            'teamCount' => DB::table('team_assignments')->where('leader_id', $r->user()->id)->count()]);
    }

    public function colleagues(Request $r)
    {
        $r->validate(['q' => 'nullable|string|max:100']);
        $query = User::where('active', true)->select('id', 'name', 'job_title', 'phone', 'email');
        if ($r->filled('q')) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$r->q.'%')->orWhere('job_title', 'like', '%'.$r->q.'%'));
        }

        return view('colleagues', ['people' => $query->orderBy('name')->get()]);
    }

    public function profile(Request $r, ?int $id = null)
    {
        $id = $id ?? $r->user()->id;
        abort_unless($r->user()->canSeePublicProfile($id), 403);
        $person = User::findOrFail($id);
        $canSeeDevelopment = $r->user()->canSeeDevelopmentData($id);
        if ($r->query('tab') === 'kompetencer') {
            abort_unless($canSeeDevelopment, 403);
            $data = app(DevelopmentController::class)->card($r, $id)->getData();
            $data['skills'] = DB::table('kls_skills')->orderBy('id')->get();
            $data['skillHistory'] = DB::table('kls_records')->where('user_id', $id)->orderByDesc('id')->get();
            $data['skillRecords'] = $data['skillHistory']->unique('skill_id')->keyBy('skill_id');
            $data['profileCertificates'] = DB::table('employee_safety_certificates')->where('user_id', $id)->orderBy('title')->get();
            $data['profileLinks'] = DB::table('skill_certificate_links')->join('kls_skills', 'kls_skills.id', '=', 'skill_certificate_links.skill_id')->where('skill_certificate_links.user_id', $id)->select('skill_certificate_links.*', 'kls_skills.name as skill_name')->get();

            return view('development.profile-competencies', $data);
        }

        return view('profile', ['person' => $person,
            'currentTools' => DB::table('shared_tool_loans as loans')->join('shared_tools as tools', 'tools.id', '=', 'loans.tool_id')->where('loans.user_id', $id)->whereNull('loans.returned_at')->where('tools.active', true)->orderBy('tools.name')->select('tools.id', 'tools.name', 'tools.asset_number', 'loans.site', 'loans.checked_out_at')->get(),
            'upcoming' => DB::table('calendar_entries')->where('user_id', $id)->whereNull('cancelled_at')->where('ends_on', '>=', now()->toDateString())->orderBy('starts_on')->limit(8)->get(),
            'canSeeDevelopment' => $canSeeDevelopment,
            'levels' => Development::LEVELS,
            'competencies' => $canSeeDevelopment ? DB::table('competencies')->orderBy('name')->get() : collect(),
            'assessments' => $canSeeDevelopment ? Development::latestAssessments($id) : collect(),
            'educations' => $canSeeDevelopment ? DB::table('employee_educations')->where('user_id', $id)->orderBy('title')->get() : collect(),
            'licenses' => $canSeeDevelopment ? DB::table('employee_driving_licenses')->where('user_id', $id)->orderBy('category')->get() : collect(),
            'certificates' => $canSeeDevelopment ? DB::table('employee_safety_certificates')->where('user_id', $id)->orderBy('title')->get() : collect(),
            'linkedCertificates' => $canSeeDevelopment ? $this->linkedCertificates($id) : collect(),
            'files' => $canSeeDevelopment ? DB::table('private_files')->where('owner_id', $id)->where('purpose', 'reference')->latest()->get() : collect(),
            'leaders' => User::whereIn('id', DB::table('team_assignments')->where('employee_id', $id)->pluck('leader_id'))->get()]);
    }

    public function team(Request $r)
    {
        abort_unless($r->user()->is_leader, 403);

        return view('team', ['people' => User::whereIn('id', DB::table('team_assignments')->where('leader_id', $r->user()->id)->pluck('employee_id'))->orderBy('name')->get()]);
    }

    public function knowledge()
    {
        return view('knowledge');
    }

    public function upload(Request $r, int $id)
    {
        abort_unless($r->user()->is_admin, 403);
        User::findOrFail($id);
        $r->validate(['file' => ['required', 'file', 'mimes:pdf', 'max:20480']]);
        $file = $r->file('file');
        $path = $file->store('references', 'local');
        try {
            DB::transaction(function () use ($file, $path, $id, $r) {
                $fileId = DB::table('private_files')->insertGetId(['owner_id' => $id, 'uploaded_by' => $r->user()->id, 'name' => mb_substr(basename($file->getClientOriginalName()), 0, 200), 'path' => $path, 'mime' => 'application/pdf', 'size' => $file->getSize(), 'purpose' => 'reference', 'created_at' => now(), 'updated_at' => now()]);
                Audit::record('reference_uploaded', 'private_file', $fileId, ['owner_id' => $id]);
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }

        return back()->with('success', 'Referencefilen er gemt privat.');
    }

    public function download(Request $r, int $id)
    {
        $file = DB::table('private_files')->find($id);
        abort_unless($file, 404);
        abort_unless(in_array($file->purpose, ['reference', 'certificate']) && $r->user()->canSeeDevelopmentData((int) $file->owner_id), 403);
        abort_unless(Storage::disk('local')->exists($file->path), 404);

        return Storage::disk('local')->download($file->path, $file->name, ['Content-Type' => 'application/pdf', 'Cache-Control' => 'no-store, private']);
    }

    private function linkedCertificates(int $id)
    {
        $licenses = DB::table('employee_driving_licenses')->where('user_id', $id)->get()->keyBy('id');
        $certificates = DB::table('employee_safety_certificates')->where('user_id', $id)->get()->keyBy('id');

        return DB::table('competency_certificate_links')
            ->join('competencies', 'competencies.id', '=', 'competency_certificate_links.competency_id')
            ->where('competency_certificate_links.user_id', $id)
            ->orderBy('competencies.name')
            ->select('competency_certificate_links.*', 'competencies.name as competency_name')
            ->get()
            ->map(function ($link) use ($licenses, $certificates) {
                $source = $link->certificate_type === 'driving_license' ? $licenses->get($link->certificate_id) : $certificates->get($link->certificate_id);
                $link->certificate_title = $source ? ($link->certificate_type === 'driving_license' ? 'Kørekort '.$source->category : $source->title) : 'Slettet certificering';
                $link->expires_on = $source?->expires_on;

                return $link;
            });
    }
}
