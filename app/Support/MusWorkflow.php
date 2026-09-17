<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MusWorkflow
{
    public const PULSE = ['Energi og arbejdsglæde', 'Klarhed over ansvar og prioriteringer', 'Mulighed for at lykkes fagligt', 'Samarbejde og tillid', 'Læring og udvikling'];

    public const START = ['Hvad er jeg mest stolt af siden sidst?', 'Hvor skaber jeg mest værdi i dag?', 'Hvad holder mig mest tilbage fra at præstere endnu bedre?'];

    public const BEHAVIOUR = ['Leverer kvalitet og følger aftaler', 'Planlægger, prioriterer og melder rettidigt tilbage', 'Tager ansvar og handler i virksomhedens interesse', 'Samarbejder respektfuldt og deler viden', 'Skaber god kundedialog og ser relevant mersalg', 'Arbejder sikkert og efterlader området ordentligt', 'Bevarer overblik og kommunikerer under pres', 'Søger forbedringer, læring og nye metoder'];

    public const DIRECTION = ['Faglig specialist', 'Projekt eller planlægning', 'Ledelse eller ansvar', 'Større faglig bredde', 'Kunde eller tilbud', 'Skal afklares'];

    public const FIELDS = ['same' => 'Her ser vi det samme', 'difference' => 'Den vigtigste forskel i vores vurdering', 'behaviour' => 'Den adfærd, vi samlet vil se mere af', 'results' => 'De vigtigste resultater og styrker siden sidst', 'next_level' => 'Det vigtigste næste niveau', 'leader_feedback' => 'Hvad skal lederen gøre mere eller mindre af?', 'ambition' => 'Hvad vil medarbejderen være markant bedre til om 12–24 måneder?', 'training' => 'Den virkelige opgave, hvor kompetencen trænes', 'leader_support' => 'Konkret lederstøtte', 'no_goals_reason' => 'Hvis ingen mål aftales: kort begrundelse'];

    public const GOAL_STATUSES = ['Aftalt', 'I gang', 'Afventer', 'Gennemført', 'Udgået'];

    public static function conversation(User $user, int $id): object
    {
        $conversation = DB::table('mus_conversations')->find($id);
        abort_unless($conversation, 404);
        abort_unless((int) $conversation->employee_id === $user->id || ($user->is_leader && (int) $conversation->leader_id === $user->id), 403);

        return $conversation;
    }

    public static function history(int $id, string $event, array $data = []): void
    {
        DB::table('mus_history')->insert(['conversation_id' => $id, 'actor_id' => auth()->id(), 'event' => $event, 'data' => json_encode($data, JSON_UNESCAPED_UNICODE), 'created_at' => now()]);
    }

    public static function decode(?string $data): array
    {
        return $data ? json_decode($data, true, 512, JSON_THROW_ON_ERROR) : [];
    }

    public static function preparationRules(string $prefix): array
    {
        return [$prefix => 'nullable|array:pulse,start,behaviour', $prefix.'.pulse' => 'nullable|array:0,1,2,3,4', $prefix.'.pulse.*' => 'nullable|integer|between:1,5',
            $prefix.'.start' => 'nullable|array:0,1,2', $prefix.'.start.*' => 'nullable|string|max:4000', $prefix.'.behaviour' => 'nullable|array:0,1,2,3,4,5,6,7', $prefix.'.behaviour.*' => 'nullable|integer|between:1,5'];
    }

    public static function save(User $user, int $id, array $input): array
    {
        return DB::transaction(function () use ($user, $id, $input) {
            $conversation = self::conversation($user, $id);
            abort_if($conversation->status === 'Arkiveret', 409, 'Forløbet er arkiveret.');
            $preparation = DB::table('mus_preparations')->where('conversation_id', $id)->where('user_id', $user->id)->first();
            $rules = array_merge(['revision' => 'required|integer', 'preparation_revision' => 'required|integer', 'shared' => 'required|array', 'shared.goals' => 'nullable|array:0,1,2', 'shared.goals.*' => 'array:result,action,owner_id,deadline,evidence,practice,transfer,competency_id,desired_level,course_topic,link_course_id,support,support_due', 'shared.direction' => ['nullable', Rule::in(self::DIRECTION)], 'shared.training_competency_id' => 'nullable|integer|exists:competencies,id'], self::preparationRules('preparation'));
            foreach (self::FIELDS as $key => $label) {
                $rules['shared.'.$key] = 'nullable|string|max:4000';
            }
            foreach (['held_on', 'checkin_on', 'followup_on', 'next_mus_on'] as $key) {
                $rules['shared.'.$key] = 'nullable|date_format:Y-m-d';
            }
            foreach (['result', 'action', 'evidence', 'practice', 'support'] as $key) {
                $rules['shared.goals.*.'.$key] = 'nullable|string|max:2000';
            }
            $rules += ['shared.goals.*.owner_id' => ['nullable', 'integer', Rule::in([$conversation->employee_id, $conversation->leader_id])],
                'shared.goals.*.deadline' => 'nullable|date_format:Y-m-d', 'shared.goals.*.support_due' => 'nullable|date_format:Y-m-d',
                'shared.goals.*.transfer' => ['nullable', Rule::in(['mus', 'competency', 'course', 'both'])],
                'shared.goals.*.competency_id' => 'nullable|integer|exists:competencies,id', 'shared.goals.*.desired_level' => 'nullable|integer|between:2,4',
                'shared.goals.*.course_topic' => 'nullable|string|max:190', 'shared.goals.*.link_course_id' => 'nullable|integer|exists:course_records,id'];
            if ((int) $conversation->leader_id === $user->id) {
                $rules = array_merge($rules, self::preparationRules('shared.joint'));
            } else {
                $rules['shared.joint'] = 'prohibited';
            }
            $data = Validator::make($input, $rules)->validate();
            $allowed = array_merge(array_keys(self::FIELDS), ['goals', 'direction', 'training_competency_id', 'held_on', 'checkin_on', 'followup_on', 'next_mus_on', 'joint']);
            $shared = array_intersect_key($data['shared'], array_flip($allowed));
            $existing = self::decode($conversation->shared_answers);
            if ((int) $conversation->leader_id !== $user->id && isset($existing['joint'])) {
                $shared['joint'] = $existing['joint'];
            }
            if ((int) $conversation->revision !== (int) $data['revision'] || (int) ($preparation?->revision ?? 0) !== (int) $data['preparation_revision']) {
                abort(409, 'Samtalen er ændret i en anden fane eller af den anden deltager. Gem en lokal kopi og genindlæs, før du samler ændringerne.');
            }
            $answers = $data['preparation'] ?? [];
            $prepRevision = (int) ($preparation?->revision ?? 0);
            if (! $preparation || self::decode($preparation->answers) != $answers) {
                $prepRevision++;
                DB::table('mus_preparations')->updateOrInsert(['conversation_id' => $id, 'user_id' => $user->id], ['answers' => json_encode($answers), 'revision' => $prepRevision, 'created_at' => $preparation?->created_at ?? now(), 'updated_at' => now()]);
                self::history($id, $preparation?->shared_at ? 'Delt forberedelse ændret' : 'Privat forberedelse gemt');
            }
            $revision = (int) $conversation->revision;
            if ($existing != $shared) {
                $revision++;
                $changed = DB::table('mus_conversations')->where('id', $id)->where('revision', $conversation->revision)->update(['shared_answers' => json_encode($shared), 'revision' => $revision, 'status' => 'Under forberedelse', 'updated_at' => now()]);
                abort_unless($changed, 409, 'Forløbet er ændret. Genindlæs siden.');
                self::history($id, 'Fælles kladde ændret', ['revision' => $revision, 'joint_recorded_by' => (int) $conversation->leader_id === $user->id && isset($shared['joint']) ? $user->id : null]);
            }

            return ['revision' => $revision, 'preparation_revision' => $prepRevision, 'saved_at' => now()->timezone('Europe/Copenhagen')->format('H:i:s')];
        });
    }

    public static function publish(User $user, int $id, int $revision): int
    {
        return DB::transaction(function () use ($user, $id, $revision) {
            $c = self::conversation($user, $id);
            abort_if($c->status === 'Arkiveret', 409);
            abort_unless((int) $c->revision === $revision, 409, 'Kladdeversionen er ændret.');
            $existing = DB::table('mus_agreement_versions')->where('conversation_id', $id)->where('version', $c->agreement_version)->first();
            if ((int) $c->submitted_revision === $revision && $existing) {
                return (int) $existing->id;
            }
            $shared = self::decode($c->shared_answers);
            foreach ($shared['goals'] ?? [] as $goal) {
                if (blank($goal['result'] ?? null) && collect($goal)->except(['owner_id', 'transfer'])->filter(fn ($value) => filled($value))->isNotEmpty()) {
                    throw ValidationException::withMessages(['goals' => 'Et påbegyndt mål mangler ønsket resultat. Udfyld resultatet, eller ryd hele målet.']);
                }
            }
            $goals = collect($shared['goals'] ?? [])->filter(fn ($g) => filled($g['result'] ?? null))->all();
            Validator::make($shared, ['held_on' => 'required|date_format:Y-m-d|before_or_equal:today', 'checkin_on' => 'required|date_format:Y-m-d|after_or_equal:held_on', 'followup_on' => 'required|date_format:Y-m-d|after_or_equal:checkin_on', 'next_mus_on' => 'required|date_format:Y-m-d|after_or_equal:followup_on', 'no_goals_reason' => count($goals) ? 'nullable' : 'required|string|max:4000'])->validate();
            if (count($goals) > 3) {
                throw ValidationException::withMessages(['goals' => 'Der må højst være tre mål.']);
            }
            $snapshotGoals = [];
            foreach ($goals as $slot => $goal) {
                $goal = array_merge(['practice' => '', 'desired_level' => null, 'competency_id' => null, 'course_topic' => null, 'link_course_id' => null], $goal);
                Validator::make($goal, ['result' => 'required|string', 'action' => 'required|string', 'owner_id' => ['required', Rule::in([$c->employee_id, $c->leader_id])], 'deadline' => 'required|date_format:Y-m-d', 'evidence' => 'required|string', 'support' => 'required|string', 'support_due' => 'required|date_format:Y-m-d', 'transfer' => ['required', Rule::in(['mus', 'competency', 'course', 'both'])]])->validate();
                if (in_array($goal['transfer'], ['competency', 'both'])) {
                    Validator::make($goal, ['competency_id' => 'required|integer|exists:competencies,id', 'practice' => 'required|string'])->validate();
                }
                if (in_array($goal['transfer'], ['course', 'both'])) {
                    Validator::make($goal, ['course_topic' => 'required|string|max:190'])->validate();
                    if (! empty($goal['link_course_id'])) {
                        $record = DB::table('course_records')->find($goal['link_course_id']);
                        if (! $record || (int) $record->user_id !== (int) $c->employee_id || ! in_array($record->status, ['Behov', 'Planlagt'])) {
                            throw ValidationException::withMessages(['course' => 'Det valgte kursusbehov skal være åbent og tilhøre medarbejderen.']);
                        }
                        if ($record->topic !== $goal['course_topic']) {
                            throw ValidationException::withMessages(['course' => 'Kursusemnet skal svare til det eksisterende behov: '.$record->topic]);
                        }
                    }
                }
                $stable = DB::table('mus_goals')->where('conversation_id', $id)->where('slot', $slot)->first();
                $goal['id'] = $stable?->id ?? (string) Str::uuid();
                if (! $stable) {
                    DB::table('mus_goals')->insert(['id' => $goal['id'], 'conversation_id' => $id, 'slot' => $slot, 'created_at' => now(), 'updated_at' => now()]);
                }
                $snapshotGoals[] = $goal;
            }
            $shared['goals'] = $snapshotGoals;
            $preparations = DB::table('mus_preparations')->where('conversation_id', $id)->whereNotNull('shared_at')->get()->map(fn ($p) => ['user_id' => $p->user_id, 'answers' => self::decode($p->answers), 'shared_at' => $p->shared_at])->all();
            $version = (int) DB::table('mus_agreement_versions')->where('conversation_id', $id)->max('version') + 1;
            $versionId = DB::table('mus_agreement_versions')->insertGetId(['conversation_id' => $id, 'version' => $version, 'snapshot' => json_encode(['shared' => $shared, 'preparations' => $preparations]), 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('mus_conversations')->where('id', $id)->update(['agreement_version' => $version, 'submitted_revision' => $revision, 'status' => 'Afventer bekræftelse', 'updated_at' => now()]);
            self::history($id, 'Aftaleversion klargjort', ['version' => $version]);

            return $versionId;
        });
    }

    public static function projection(array $goal): array
    {
        return ['result' => $goal['result'], 'description' => $goal['practice'] ?? '', 'deadline' => $goal['deadline'], 'competency_id' => $goal['competency_id'] ?? null, 'desired_level' => $goal['desired_level'] ?? null, 'course_topic' => $goal['course_topic'] ?? null, 'transfer' => $goal['transfer']];
    }

    public static function confirm(User $user, int $id, int $version): void
    {
        DB::transaction(function () use ($user, $id, $version) {
            $c = self::conversation($user, $id);
            abort_if($c->status === 'Arkiveret', 409);
            abort_unless((int) $c->agreement_version === $version && (int) $c->submitted_revision === (int) $c->revision, 409, 'Aftalerne er ændret. Begge skal bekræfte en ny version.');
            $record = DB::table('mus_agreement_versions')->where('conversation_id', $id)->where('version', $version)->first();
            abort_unless($record, 404);
            $added = DB::table('mus_confirmations')->insertOrIgnore(['agreement_version_id' => $record->id, 'user_id' => $user->id, 'name' => $user->name, 'confirmed_at' => now()]);
            if ($added) {
                self::history($id, 'Deltager har bekræftet', ['version' => $version]);
            }
            if (DB::table('mus_confirmations')->where('agreement_version_id', $record->id)->count() !== 2 || (int) $c->confirmed_version === $version) {
                return;
            }
            $snapshot = self::decode($record->snapshot)['shared'];
            $removed = DB::table('mus_goals')->where('conversation_id', $id)->whereNotIn('id', array_column($snapshot['goals'], 'id'))->pluck('id');
            DB::table('mus_goals')->whereIn('id', $removed)->update(['status' => 'Udgået', 'updated_at' => now()]);
            DB::table('development_goals')->whereIn('source_goal_id', $removed)->update(['status' => 'Udgået', 'updated_at' => now()]);
            foreach ($snapshot['goals'] as $goal) {
                $projection = self::projection($goal);
                $courseId = null;
                if (in_array($goal['transfer'], ['competency', 'both'])) {
                    DB::table('development_goals')->updateOrInsert(['source_goal_id' => $goal['id']], ['user_id' => $c->employee_id, 'competency_id' => $goal['competency_id'], 'desired_level' => $goal['desired_level'] ?: null, 'description' => $goal['result']."\n".($goal['practice'] ?? ''), 'deadline' => $goal['deadline'], 'status' => DB::table('mus_goals')->where('id', $goal['id'])->value('status'), 'source_version' => $version, 'updated_at' => now(), 'created_at' => now()]);
                } else {
                    DB::table('development_goals')->where('source_goal_id', $goal['id'])->update(['status' => 'Udgået', 'updated_at' => now()]);
                }
                if (in_array($goal['transfer'], ['course', 'both'])) {
                    $link = DB::table('mus_course_links')->where('source_goal_id', $goal['id'])->first();
                    $linked = $link ? DB::table('course_records')->find($link->course_record_id) : null;
                    if (! empty($goal['link_course_id'])) {
                        $course = DB::table('course_records')->where('id', $goal['link_course_id'])->where('user_id', $c->employee_id)->whereIn('status', ['Behov', 'Planlagt'])->first();
                        if (! $course && (int) $linked?->id !== (int) $goal['link_course_id']) {
                            throw ValidationException::withMessages(['course' => 'Det valgte kursusbehov er ændret. Klargør aftalerne igen.']);
                        }
                        $courseId = (int) $goal['link_course_id'];
                    } elseif ($linked && $linked->topic === $goal['course_topic']) {
                        $courseId = $linked->id;
                    } else {
                        $courseId = DB::table('course_records')->insertGetId(['user_id' => $c->employee_id, 'topic' => $goal['course_topic'], 'status' => 'Behov', 'updated_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
                    }
                    DB::table('mus_course_links')->updateOrInsert(['source_goal_id' => $goal['id']], ['course_record_id' => $courseId, 'source_version' => $version, 'created_at' => now(), 'updated_at' => now()]);
                }
                DB::table('mus_transfer_history')->insertOrIgnore(['goal_id' => $goal['id'], 'version' => $version, 'course_record_id' => $courseId, 'projection' => json_encode($projection), 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('mus_conversations')->where('id', $id)->update(['confirmed_version' => $version, 'status' => 'Bekræftet', 'held_on' => $snapshot['held_on'], 'checkin_on' => $snapshot['checkin_on'], 'followup_on' => $snapshot['followup_on'], 'next_mus_on' => $snapshot['next_mus_on'], 'updated_at' => now()]);
            foreach (array_unique([$snapshot['checkin_on'], $snapshot['followup_on']]) as $date) {
                DB::table('mus_followups')->insertOrIgnore(['conversation_id' => $id, 'due_on' => $date, 'created_at' => now(), 'updated_at' => now()]);
            }
            self::history($id,'Begge deltagere har bekræftet',['version' => $version]);
        });
    }
}
