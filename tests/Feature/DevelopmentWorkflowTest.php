<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class DevelopmentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));
        $employee = User::factory()->create(['name' => 'Fiktiv medarbejder']);
        $leader = User::factory()->create(['name' => 'Fiktiv leder', 'is_leader' => true]);
        $admin = User::factory()->create(['is_admin' => true]);
        DB::table('team_assignments')->insert(['leader_id' => $leader->id, 'employee_id' => $employee->id]);
        $competency = DB::table('competencies')->insertGetId(['name' => 'Ladestandere']);
        $id = DB::table('mus_conversations')->insertGetId(['employee_id' => $employee->id, 'leader_id' => $leader->id, 'employee_name' => $employee->name, 'leader_name' => $leader->name, 'scheduled_on' => '2026-09-15']);
        $shared = ['held_on' => '2026-09-15', 'checkin_on' => '2026-10-15', 'followup_on' => '2026-12-14', 'next_mus_on' => '2027-09-15', 'same' => 'Fortrolig dialog som KLS aldrig må se', 'goals' => [['result' => 'Installere ladestandere selvstændigt', 'action' => 'Vælge kursus', 'owner_id' => $employee->id, 'deadline' => '2026-12-20', 'evidence' => 'En gennemført installation', 'practice' => 'Træning på installation', 'transfer' => 'both', 'competency_id' => $competency, 'desired_level' => 3, 'course_topic' => 'Installation af ladestandere', 'support' => 'Find makker', 'support_due' => '2026-10-01']]];

        return compact('employee', 'leader', 'admin', 'competency', 'id', 'shared');
    }

    private function prepare(array $context, ?array $shared = null): int
    {
        $c = DB::table('mus_conversations')->find($context['id']);
        $prep = DB::table('mus_preparations')->where('conversation_id', $c->id)->where('user_id', $context['employee']->id)->first();
        $response = $this->actingAs($context['employee'])->postJson('/mus/'.$c->id.'/gem', ['revision' => $c->revision, 'preparation_revision' => $prep?->revision ?? 0, 'preparation' => [], 'shared' => $shared ?? $context['shared']])->assertOk();
        $this->post('/mus/'.$c->id.'/klargoer', ['revision' => $response->json('revision')])->assertRedirect();

        return (int) DB::table('mus_conversations')->where('id', $c->id)->value('agreement_version');
    }

    private function confirmBoth(array $context, int $version): void
    {
        $this->actingAs($context['employee'])->post('/mus/'.$context['id'].'/bekraeft', ['version' => $version, 'consent' => 1])->assertRedirect();
        $this->actingAs($context['leader'])->post('/mus/'.$context['id'].'/bekraeft', ['version' => $version, 'consent' => 1])->assertRedirect();
    }

    #[TestWith(['employee'])]
    #[TestWith(['leader'])]
    #[TestWith(['admin'])]
    public function test_unassigned_roles_cannot_read_write_print_or_confirm_a_mus(string $role): void
    {
        $c = $this->context();
        $outsider = User::factory()->create(['is_admin' => $role === 'admin', 'is_leader' => $role === 'leader']);

        $this->actingAs($outsider)->get('/mus/'.$c['id'])->assertForbidden();
        $this->get('/mus/'.$c['id'].'/udskriv/1')->assertForbidden();
        $this->postJson('/mus/'.$c['id'].'/gem', [])->assertForbidden();
        $this->post('/mus/'.$c['id'].'/bekraeft', ['version' => 1, 'consent' => 1])->assertForbidden();
        $this->get('/mus')->assertDontSee('Fiktiv medarbejder');
        $this->assertDatabaseCount('mus_confirmations', 0);
    }

    public function test_private_preparation_is_hidden_until_explicitly_shared_and_never_in_earlier_print(): void
    {
        $c = $this->context();
        $response = $this->actingAs($c['employee'])->postJson('/mus/'.$c['id'].'/gem', ['revision' => 1, 'preparation_revision' => 0, 'preparation' => ['start' => ['Hemmelig privat forberedelse']], 'shared' => $c['shared']])->assertOk();
        $this->actingAs($c['leader'])->get('/mus/'.$c['id'])->assertDontSee('Hemmelig privat forberedelse');
        $this->actingAs($c['employee'])->post('/mus/'.$c['id'].'/klargoer', ['revision' => $response->json('revision')])->assertRedirect();
        $this->get('/mus/'.$c['id'].'/udskriv/1')->assertDontSee('Hemmelig privat forberedelse');
        $this->post('/mus/'.$c['id'].'/del')->assertRedirect();
        $this->actingAs($c['leader'])->get('/mus/'.$c['id'])->assertSee('Hemmelig privat forberedelse');
        $this->get('/mus/'.$c['id'].'/udskriv/1')->assertDontSee('Hemmelig privat forberedelse');
    }

    public function test_complete_mus_to_course_followup_and_separate_assessment_without_duplicates(): void
    {
        $c = $this->context();
        $version = $this->prepare($c);
        $this->actingAs($c['employee'])->post('/mus/'.$c['id'].'/bekraeft', ['version' => $version, 'consent' => 1])->assertRedirect();
        $this->assertDatabaseCount('course_records', 0);
        $this->assertDatabaseCount('development_goals', 0);
        $this->confirmBoth($c, $version);
        $this->confirmBoth($c, $version);
        $this->assertDatabaseCount('mus_confirmations', 2);
        $this->assertDatabaseCount('course_records', 1);
        $this->assertDatabaseCount('development_goals', 1);
        $this->assertDatabaseCount('mus_followups', 2);
        $this->actingAs($c['admin'])->get('/kompetencekort/'.$c['employee']->id)->assertSee('Træning på installation')->assertDontSee('Fortrolig dialog som KLS aldrig må se');
        $this->get('/mus/'.$c['id'])->assertForbidden();
        $record = DB::table('course_records')->first();
        Storage::fake('local');
        $this->post('/kompetencekort/'.$c['employee']->id.'/kursus/'.$record->id, ['revision' => $record->revision, 'topic' => $record->topic, 'status' => 'Gennemført', 'date_precision' => 'date', 'completed_on' => '2026-09-15', 'certificate' => UploadedFile::fake()->create('bevis.pdf', 10, 'application/pdf')])->assertRedirect();
        $this->assertDatabaseCount('competency_assessments', 0);
        $file = DB::table('private_files')->first();
        Storage::disk('local')->assertExists($file->path);
        $this->actingAs($c['employee'])->get('/filer/'.$file->id)->assertDownload('bevis.pdf');
        $this->get('/mus/'.$c['id'])->assertSee('Gennemført');
        $goal = DB::table('mus_goals')->first();
        $followup = DB::table('mus_followups')->orderByDesc('due_on')->first();
        $this->travelTo(Carbon::parse('2026-12-14 12:00:00'));
        $payload = ['version' => $version, 'goals' => [$goal->id => ['status' => 'Gennemført', 'note' => 'Udført en installation under supervision', 'progressed' => 1, 'support_done' => 1]], 'next_step' => 'KLS foretager faglig vurdering', 'next_due' => '2027-03-14'];
        $this->actingAs($c['leader'])->post('/mus/'.$c['id'].'/opfoelgning/'.$followup->id, $payload)->assertRedirect();
        $this->post('/mus/'.$c['id'].'/opfoelgning/'.$followup->id, $payload)->assertRedirect();
        $this->assertDatabaseCount('mus_followups', 3);
        $this->assertDatabaseCount('competency_assessments', 0);
        $this->actingAs($c['admin'])->post('/kompetencekort/'.$c['employee']->id.'/vurdering', ['competency_id' => $c['competency'], 'level' => 3, 'assessed_on' => '2026-12-14', 'basis' => 'Observeret selvstændig installation', 'submission_id' => Str::uuid()->toString()])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('competency_assessments', ['user_id' => $c['employee']->id, 'level' => 3]);
        $this->get('/kompetencer')->assertSee('Kan arbejde selvstændigt');
    }

    public function test_changed_agreement_needs_two_new_confirmations_and_preserves_old_course(): void
    {
        $c = $this->context();
        $this->confirmBoth($c, $this->prepare($c));
        $changed = $c['shared'];
        $changed['goals'][0]['result'] = 'Nyt bekræftet mål';
        $version = $this->prepare($c, $changed);
        $this->actingAs($c['employee'])->post('/mus/'.$c['id'].'/bekraeft', ['version' => $version, 'consent' => 1])->assertRedirect();
        $this->assertDatabaseHas('mus_conversations', ['id' => $c['id'], 'confirmed_version' => 1]);
        $this->assertDatabaseMissing('development_goals', ['description' => "Nyt bekræftet mål\nTræning på installation"]);
        $this->actingAs($c['leader'])->post('/mus/'.$c['id'].'/bekraeft', ['version' => $version, 'consent' => 1])->assertRedirect();
        $this->assertDatabaseCount('course_records', 1);
        $this->assertDatabaseCount('mus_confirmations', 4);
        $this->assertDatabaseCount('mus_agreement_versions', 2);
        $this->assertDatabaseHas('development_goals', ['description' => "Nyt bekræftet mål\nTræning på installation"]);
    }

    public function test_stale_autosave_returns_409_and_does_not_overwrite_answers(): void
    {
        $c = $this->context();
        $payload = ['revision' => 1, 'preparation_revision' => 0, 'shared' => $c['shared'], 'preparation' => []];
        $this->actingAs($c['employee'])->postJson('/mus/'.$c['id'].'/gem', $payload)->assertOk();
        $payload['shared']['same'] = 'Must not overwrite';
        $this->postJson('/mus/'.$c['id'].'/gem', $payload)->assertConflict();
        $this->assertStringNotContainsString('Must not overwrite', DB::table('mus_conversations')->where('id', $c['id'])->value('shared_answers'));
    }

    public function test_four_goals_invalid_scores_and_joint_impersonation_are_rejected(): void
    {
        $c = $this->context();
        $shared = $c['shared'];
        $shared['goals'] = array_fill(0, 4, $shared['goals'][0]);
        $this->actingAs($c['employee'])->postJson('/mus/'.$c['id'].'/gem', ['revision' => 1, 'preparation_revision' => 0, 'shared' => $shared])->assertUnprocessable()->assertJsonValidationErrors('shared.goals');
        $this->postJson('/mus/'.$c['id'].'/gem', ['revision' => 1, 'preparation_revision' => 0, 'shared' => $c['shared'], 'preparation' => ['behaviour' => [0]]])->assertUnprocessable();
        $shared = $c['shared'];
        $shared['joint'] = ['pulse' => [5]];
        $this->postJson('/mus/'.$c['id'].'/gem', ['revision' => 1, 'preparation_revision' => 0, 'shared' => $shared])->assertUnprocessable();
        $this->assertDatabaseCount('mus_preparations', 0);
    }

    public function test_leader_can_record_joint_answers_without_confirming_as_employee(): void
    {
        $c = $this->context();
        $shared = $c['shared'];
        $shared['joint'] = ['pulse' => [4], 'start' => ['Fælles registrering'], 'behaviour' => [3]];
        $this->actingAs($c['leader'])->postJson('/mus/'.$c['id'].'/gem', ['revision' => 1, 'preparation_revision' => 0, 'shared' => $shared, 'preparation' => ['behaviour' => [5]]])->assertOk();
        $this->actingAs($c['employee'])->get('/mus/'.$c['id'])->assertDontSee('name="shared[joint]', false);
        $this->assertDatabaseCount('mus_confirmations', 0);
    }

    public function test_existing_open_course_can_be_linked_without_duplication(): void
    {
        $c = $this->context();
        $record = DB::table('course_records')->insertGetId(['user_id' => $c['employee']->id, 'topic' => 'Installation af ladestandere', 'updated_by' => $c['admin']->id]);
        $shared = $c['shared'];
        $shared['goals'][0]['link_course_id'] = $record;
        $this->confirmBoth($c, $this->prepare($c, $shared));
        $this->assertDatabaseCount('course_records', 1);
        $this->assertDatabaseHas('mus_course_links', ['course_record_id' => $record]);
    }

    public function test_course_history_keeps_years_intervals_and_unknown_dates_distinct(): void
    {
        $c = $this->context();
        foreach ([['year', '2023'], ['interval', '2006–2008'], ['unknown', 'løbende']] as [$precision,$original]) {
            $this->actingAs($c['admin'])->post('/kompetencekort/'.$c['employee']->id.'/kursus', ['topic' => 'Gentaget kursus', 'status' => 'Gennemført', 'date_precision' => $precision, 'original_date' => $original, 'submission_id' => Str::uuid()->toString()])->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->assertDatabaseCount('course_records', 3);
        $this->assertSame(0, DB::table('course_records')->whereNotNull('completed_on')->count());
        $this->actingAs($c['employee'])->get('/kompetencekort/'.$c['employee']->id)->assertSee('løbende · kræver afklaring')->assertSee('2006–2008')->assertSee('Gyldighed ikke oplyst');
    }

    public function test_leader_cannot_change_formal_levels_or_see_private_course_note(): void
    {
        $c = $this->context();
        DB::table('course_records')->insert(['user_id' => $c['employee']->id, 'topic' => 'Kursus', 'updated_by' => $c['admin']->id, 'note' => 'Kun medarbejder og KLS']);
        $this->actingAs($c['leader'])->get('/kompetencekort/'.$c['employee']->id)->assertDontSee('Kun medarbejder og KLS');
        $this->post('/kompetencekort/'.$c['employee']->id.'/vurdering', [])->assertForbidden();
        $this->post('/kompetencekort/'.$c['employee']->id.'/kursus', [])->assertForbidden();
        $this->get('/katalog')->assertForbidden();
        $this->assertDatabaseCount('competency_assessments', 0);
    }

    public function test_admin_manages_structured_credentials_and_links_them_to_competencies(): void
    {
        $c = $this->context();
        $this->actingAs($c['admin'])->post('/kompetencekort/'.$c['employee']->id.'/grunduddannelse', ['title' => 'Elektriker'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/kompetencekort/'.$c['employee']->id.'/koerekort', ['category' => 'be', 'expires_on' => '2026-11-20'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/kompetencekort/'.$c['employee']->id.'/certifikat', ['title' => 'Liftuddannelse', 'expires_on' => '2026-09-01'])->assertRedirect()->assertSessionHasNoErrors();
        $license = DB::table('employee_driving_licenses')->where('user_id', $c['employee']->id)->first();
        $this->post('/kompetencekort/'.$c['employee']->id.'/certifikat-link', ['competency_id' => $c['competency'], 'certificate' => 'driving_license:'.$license->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('employee_educations', ['user_id' => $c['employee']->id, 'title' => 'Elektriker']);
        $this->assertDatabaseHas('employee_driving_licenses', ['user_id' => $c['employee']->id, 'category' => 'BE', 'expires_on' => '2026-11-20']);
        $this->assertDatabaseHas('employee_safety_certificates', ['user_id' => $c['employee']->id, 'title' => 'Liftuddannelse']);
        $this->assertDatabaseHas('competency_certificate_links', ['user_id' => $c['employee']->id, 'competency_id' => $c['competency'], 'certificate_type' => 'driving_license', 'certificate_id' => $license->id]);
        $this->get('/kompetencekort/'.$c['employee']->id)->assertSee('Kørekort BE')->assertSee('Udløber 20/11/2026')->assertSee('Udløbet 01/09/2026')->assertSee('Tilknyttede certificeringer');
        $this->actingAs($c['employee'])->post('/kompetencekort/'.$c['employee']->id.'/koerekort', ['category' => 'B', 'expires_on' => '2027-01-01'])->assertForbidden();
    }

    public function test_print_escapes_shared_text_and_excludes_private_drafts(): void
    {
        $c = $this->context();
        $shared = $c['shared'];
        $shared['results'] = '<script>alert("xss")</script>';
        $this->prepare($c, $shared);
        $this->get('/mus/'.$c['id'].'/udskriv/1')->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert("xss")</script>', false);
    }

    public function test_partial_goal_cannot_disappear_when_publishing(): void
    {
        $c = $this->context();
        $shared = $c['shared'];
        unset($shared['goals'][0]['result']);
        $this->actingAs($c['employee'])->postJson('/mus/'.$c['id'].'/gem', ['revision' => 1, 'preparation_revision' => 0, 'shared' => $shared])->assertOk();
        $this->post('/mus/'.$c['id'].'/klargoer', ['revision' => 2])->assertSessionHasErrors('goals');
        $this->assertDatabaseCount('mus_agreement_versions', 0);
    }

    public function test_followup_requires_the_leader_and_current_confirmed_version(): void
    {
        $c = $this->context();
        $version = $this->prepare($c);
        $this->confirmBoth($c, $version);
        $followup = DB::table('mus_followups')->first();
        $goal = DB::table('mus_goals')->first();
        $payload = ['version' => 99, 'next_step' => 'Ny opgave', 'goals' => [$goal->id => ['status' => 'I gang', 'note' => 'Opgave startet', 'progressed' => 1]]];
        $this->actingAs($c['employee'])->post('/mus/'.$c['id'].'/opfoelgning/'.$followup->id, $payload)->assertForbidden();
        $this->actingAs($c['leader'])->post('/mus/'.$c['id'].'/opfoelgning/'.$followup->id, $payload)->assertConflict();
        $this->assertDatabaseHas('mus_followups', ['id' => $followup->id, 'completed_on' => null]);
    }

    public function test_removed_goal_is_retired_only_after_both_confirm_new_version(): void
    {
        $c = $this->context();
        $this->confirmBoth($c, $this->prepare($c));
        $shared = $c['shared'];
        $shared['goals'] = [];
        $shared['no_goals_reason'] = 'Vi prioriterer den almindelige drift.';
        $version = $this->prepare($c, $shared);
        $this->assertDatabaseHas('development_goals', ['status' => 'Aftalt']);
        $this->confirmBoth($c, $version);
        $this->assertDatabaseHas('development_goals', ['status' => 'Udgået']);
        $this->assertDatabaseCount('course_records', 1);
    }

    public function test_archiving_keeps_confirmations_courses_and_open_goals(): void
    {
        $c = $this->context();
        $this->confirmBoth($c, $this->prepare($c));
        $this->actingAs($c['leader'])->post('/mus/'.$c['id'].'/arkiver')->assertRedirect();
        $this->assertDatabaseCount('course_records', 1);
        $this->assertDatabaseCount('mus_confirmations', 2);
        $this->assertDatabaseHas('mus_goals', ['status' => 'Aftalt']);
        $this->postJson('/mus/'.$c['id'].'/gem', [])->assertConflict();
    }
}
