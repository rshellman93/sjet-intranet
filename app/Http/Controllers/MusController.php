<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\MusWorkflow as Mus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MusController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $conversations = DB::table('mus_conversations')->where(function ($q) use ($user) {
            $q->where('employee_id', $user->id);
            if ($user->is_leader) {
                $q->orWhere('leader_id', $user->id);
            }
        })->orderByDesc('scheduled_on')->get();
        $team = $user->is_leader ? User::where('active', true)->where('id', '!=', $user->id)->whereIn('id', DB::table('team_assignments')->where('leader_id', $user->id)->pluck('employee_id'))->get() : collect();
        $followups = DB::table('mus_followups')->whereIn('conversation_id', $conversations->pluck('id'))->whereNull('completed_on')->orderBy('due_on')->get();

        return view('mus.index', compact('conversations', 'team', 'followups'));
    }

    public function create(Request $request): RedirectResponse
    {
        $data = $request->validate(['employee_id' => 'required|integer|exists:users,id', 'scheduled_on' => 'required|date_format:Y-m-d', 'creation_token' => 'required|uuid']);
        abort_unless($request->user()->leads((int) $data['employee_id']) && $request->user()->id !== (int) $data['employee_id'], 403);
        $employee = User::findOrFail($data['employee_id']);
        abort_unless($employee->active, 422);
        $id = DB::transaction(function () use ($request, $employee, $data) {
            $existing = DB::table('mus_conversations')->where('creation_token', $data['creation_token'])->first();
            if ($existing) {
                Mus::conversation($request->user(), $existing->id);

                return $existing->id;
            }
            $id = DB::table('mus_conversations')->insertGetId(['employee_id' => $employee->id, 'leader_id' => $request->user()->id, 'employee_name' => $employee->name, 'leader_name' => $request->user()->name, 'job_title' => $employee->job_title, 'scheduled_on' => $data['scheduled_on'], 'creation_token' => $data['creation_token'], 'created_at' => now(), 'updated_at' => now()]);
            Mus::history($id, 'Samtale oprettet');

            return $id;
        });

        return redirect()->route('mus.show', $id)->with('success', 'MUS-forløbet er oprettet.');
    }

    public function show(Request $request, int $id): View
    {
        $conversation = Mus::conversation($request->user(), $id);
        $shared = Mus::decode($conversation->shared_answers);
        $preparation = DB::table('mus_preparations')->where('conversation_id', $id)->where('user_id', $request->user()->id)->first();
        $other = DB::table('mus_preparations')->where('conversation_id', $id)->where('user_id', '!=', $request->user()->id)->whereNotNull('shared_at')->first();
        $versions = DB::table('mus_agreement_versions')->where('conversation_id', $id)->orderByDesc('version')->get();
        $latest = $versions->first();
        $confirmed = $versions->firstWhere('version', $conversation->confirmed_version);
        $followups = DB::table('mus_followups')->where('conversation_id', $id)->orderBy('due_on')->get();
        $goalStatus = DB::table('mus_goals')->where('conversation_id', $id)->get()->keyBy('id');
        $links = DB::table('mus_course_links')->join('course_records', 'course_records.id', '=', 'mus_course_links.course_record_id')->whereIn('source_goal_id', $goalStatus->keys())->select('source_goal_id', 'course_records.topic', 'course_records.status')->get()->keyBy('source_goal_id');
        $previous = DB::table('mus_conversations')->where('employee_id', $conversation->employee_id)->where('id', '!=', $id)->whereNotNull('confirmed_version')->get();
        $previousGoals = [];
        foreach ($previous as $old) {
            $snapshot = DB::table('mus_agreement_versions')->where('conversation_id', $old->id)->where('version', $old->confirmed_version)->first();
            if (! $snapshot) {
                continue;
            }
            $mayRead = (int) $old->employee_id === $request->user()->id || ($request->user()->is_leader && (int) $old->leader_id === $request->user()->id);
            if (! $mayRead) {
                continue;
            }
            foreach (Mus::decode($snapshot->snapshot)['shared']['goals'] as $goal) {
                $status = DB::table('mus_goals')->where('id', $goal['id'])->value('status');
                if (! in_array($status, ['Gennemført', 'Udgået'])) {
                    $previousGoals[] = ['conversation' => $old->id, 'result' => $goal['result'], 'deadline' => $goal['deadline'], 'status' => $status];
                }
            }
        }

        return view('mus.show', compact('conversation', 'shared', 'preparation', 'other', 'versions', 'latest', 'confirmed', 'followups', 'goalStatus', 'links', 'previousGoals') + [
            'own' => Mus::decode($preparation?->answers), 'otherAnswers' => Mus::decode($other?->answers),
            'competencies' => DB::table('competencies')->orderBy('name')->pluck('name', 'id'),
            'openCourses' => DB::table('course_records')->where('user_id', $conversation->employee_id)->whereIn('status', ['Behov', 'Planlagt'])->get(),
            'confirmations' => $latest ? DB::table('mus_confirmations')->where('agreement_version_id', $latest->id)->get() : collect(),
            'history' => DB::table('mus_history')->where('conversation_id', $id)->leftJoin('users', 'users.id', '=', 'mus_history.actor_id')->select('mus_history.*', 'users.name as actor')->orderByDesc('mus_history.id')->limit(30)->get()]);
    }

    public function save(Request $request, int $id): JsonResponse|RedirectResponse
    {
        $result = Mus::save($request->user(), $id, $request->all());

        return $request->expectsJson() ? response()->json($result) : back()->with('success', 'Samtalen er gemt.');
    }

    public function share(Request $request, int $id): RedirectResponse
    {
        $c = Mus::conversation($request->user(), $id);
        abort_if($c->status === 'Arkiveret', 409);
        DB::transaction(function () use ($request, $id) {
            $row = DB::table('mus_preparations')->where('conversation_id', $id)->where('user_id', $request->user()->id)->first();
            abort_unless($row, 422, 'Gem din forberedelse først.');
            if (! $row->shared_at) {
                DB::table('mus_preparations')->where('id', $row->id)->update(['shared_at' => now()]);
                Mus::history($id, 'Forberedelse delt');
            }
        });

        return back()->with('success', 'Din forberedelse er nu delt med den anden deltager. Senere ændringer er også synlige.');
    }

    public function publish(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer']);
        Mus::publish($request->user(), $id, (int) $data['revision']);

        return redirect()->route('mus.show', $id)->with('success', 'Aftaleversionen er klar. Gennemgå forhåndsvisningen, og bekræft via hvert jeres login.');
    }

    public function confirm(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['version' => 'required|integer', 'consent' => 'accepted']);
        Mus::confirm($request->user(), $id, (int) $data['version']);

        return back()->with('success', 'Din bekræftelse er registreret. Overførsel sker, når begge har bekræftet samme version.');
    }

    public function followup(Request $request, int $id, int $followup): RedirectResponse
    {
        $c = Mus::conversation($request->user(), $id);
        abort_unless($c->confirmed_version && $c->status !== 'Arkiveret', 409);
        abort_unless((int) $c->leader_id === $request->user()->id, 403);
        $version = DB::table('mus_agreement_versions')->where('conversation_id', $id)->where('version', $c->confirmed_version)->first();
        $goals = Mus::decode($version->snapshot)['shared']['goals'];
        $rules = ['version' => 'required|integer', 'next_due' => 'nullable|date_format:Y-m-d|after:today', 'next_step' => 'required|string|max:2000', 'blockers' => 'nullable|string|max:2000', 'goals' => 'nullable|array'];
        foreach ($goals as $goal) {
            $key = 'goals.'.$goal['id'];
            $rules += [$key.'.status' => ['required', Rule::in(Mus::GOAL_STATUSES)], $key.'.note' => 'required|string|max:2000', $key.'.progressed' => 'required|boolean', $key.'.support_done' => 'nullable|boolean'];
        }
        $data = $request->validate($rules);
        DB::transaction(function () use ($data, $goals, $id, $followup, $request) {
            $currentConversation = Mus::conversation($request->user(), $id);
            abort_unless($currentConversation->status !== 'Arkiveret' && (int) $currentConversation->confirmed_version === (int) $data['version'], 409, 'Aftaleversionen er ændret. Genindlæs før opfølgning.');
            $row = DB::table('mus_followups')->where('conversation_id', $id)->find($followup);
            abort_unless($row, 404);
            if ($row->completed_on) {
                return;
            }
            $allowed = [];
            foreach ($goals as $goal) {
                $entry = $data['goals'][$goal['id']];
                $allowed[$goal['id']] = $entry;
                $current = DB::table('mus_goals')->where('id', $goal['id'])->first();
                $update = ['status' => $entry['status'], 'updated_at' => now()];
                if (! $current->started_on && in_array($entry['status'], ['I gang', 'Gennemført'])) {
                    $update['started_on'] = now()->timezone('Europe/Copenhagen')->toDateString();
                }
                if (! $current->support_delivered_on && ! empty($entry['support_done'])) {
                    $update['support_delivered_on'] = now()->timezone('Europe/Copenhagen')->toDateString();
                }
                DB::table('mus_goals')->where('id', $goal['id'])->update($update);
                DB::table('development_goals')->where('source_goal_id', $goal['id'])->update(['status' => $entry['status'], 'updated_at' => now()]);
            }
            $data['goals'] = $allowed;
            DB::table('mus_followups')->where('id', $followup)->update(['completed_on' => now()->timezone('Europe/Copenhagen')->toDateString(), 'progress' => json_encode($data), 'recorded_by' => $request->user()->id, 'updated_at' => now()]);
            if (! empty($data['next_due'])) {
                DB::table('mus_followups')->insertOrIgnore(['conversation_id' => $id, 'due_on' => $data['next_due'], 'created_at' => now(), 'updated_at' => now()]);
            }
            Mus::history($id, 'Opfølgning registreret', ['followup_id' => $followup]);
        });

        return back()->with('success', 'Opfølgningen er gemt. Kursushistorik og faglige vurderinger er uændrede.');
    }

    public function archive(Request $request, int $id): RedirectResponse
    {
        $c = Mus::conversation($request->user(), $id);
        abort_unless((int) $c->leader_id === $request->user()->id, 403);
        DB::transaction(function () use ($id) {
            DB::table('mus_conversations')->where('id', $id)->update(['status' => 'Arkiveret', 'updated_at' => now()]);
            Mus::history($id, 'Forløb arkiveret');
        });

        return back()->with('success', 'Forløbet er arkiveret. Aftaler, bekræftelser og kursushistorik er bevaret.');
    }

    public function print(Request $request, int $id, int $version): View
    {
        $conversation = Mus::conversation($request->user(), $id);
        $record = DB::table('mus_agreement_versions')->where('conversation_id', $id)->where('version',$version)->first();
        abort_unless($record,404);
        Mus::history($id,'Udskriftsvisning åbnet',['version' => $version]);

        return view('mus.print',['conversation' => $conversation, 'record' => $record, 'snapshot' => Mus::decode($record->snapshot), 'confirmations' => DB::table('mus_confirmations')->where('agreement_version_id',$record->id)->get(), 'competencies' => DB::table('competencies')->pluck('name','id')]);
    }
}
