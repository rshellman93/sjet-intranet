<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class IntranetAccessTest extends TestCase
{
    use RefreshDatabase;

    private function person(array $extra = []): User
    {
        return User::factory()->create(array_merge(['active' => true, 'password' => 'Local-Test-Password-82'], $extra));
    }

    private function admin(): User
    {
        return $this->person(['is_admin' => true]);
    }

    private function department(): int
    {
        return DB::table('departments')->insertGetId(['name' => 'Sydals']);
    }

    private function record(int $owner): int
    {
        Storage::fake('local');
        Storage::disk('local')->put('references/private.pdf', '%PDF-1.4 test');

        return DB::table('private_files')->insertGetId(['owner_id' => $owner, 'uploaded_by' => $owner, 'name' => 'privat.pdf', 'path' => 'references/private.pdf', 'mime' => 'application/pdf', 'size' => 13, 'purpose' => 'reference', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_guest_redirect_and_no_public_registration(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/register')->assertNotFound();
        $this->post('/register')->assertNotFound();
    }

    public function test_real_password_login_and_first_login_timestamp(): void
    {
        $user = $this->person();
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors();
        $this->assertGuest();
        $this->post('/login', ['email' => $user->email, 'password' => 'Local-Test-Password-82'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $first = $user->fresh()->first_login_at;
        $this->assertNotNull($first);
        $this->post('/logout');
        $this->travel(2)->minutes();
        $this->post('/login', ['email' => $user->email, 'password' => 'Local-Test-Password-82']);
        $this->assertEquals($first, $user->fresh()->first_login_at);
        $this->assertTrue($user->fresh()->last_login_at->gt($first));
    }

    public function test_deactivated_user_cannot_login_or_keep_session(): void
    {
        $u = $this->person(['active' => false]);
        $this->post('/login', ['email' => $u->email, 'password' => 'Local-Test-Password-82'])->assertSessionHasErrors();
        $this->actingAs($u)->get('/profil')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_employee_can_open_colleague_contact_profile_but_not_private_development_data(): void
    {
        $u = $this->person();
        $other = $this->person();
        $file = $this->record($other->id);
        $this->actingAs($u)->get('/profil')->assertOk();
        $this->get('/profil/'.$other->id)->assertOk()->assertSee($other->email)->assertSee('Kompetencer, uddannelser, kørekort og certifikater er private')->assertDontSee('Private referencefiler');
        $this->get('/kompetencekort/'.$other->id)->assertForbidden();
        foreach (['/filer/'.$file, '/administration', '/administration/adgang.csv', '/mit-team'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->get('/storage/references/private.pdf')->assertNotFound();
    }

    public function test_leader_has_team_access_without_mfa_but_must_be_explicitly_assigned(): void
    {
        $leader = $this->person(['is_leader' => true]);
        $a = $this->person();
        $b = $this->person();
        $file = $this->record($a->id);
        DB::table('team_assignments')->insert(['leader_id' => $leader->id, 'employee_id' => $a->id]);
        $this->actingAs($leader)->get('/mit-team')->assertOk();
        $this->get('/profil/'.$a->id)->assertOk();
        $this->get('/filer/'.$file)->assertOk();
        $this->get('/profil/'.$b->id)->assertOk()->assertSee('Kompetencer, uddannelser, kørekort og certifikater er private');
        $this->get('/kompetencekort/'.$b->id)->assertForbidden();
    }

    #[TestWith(['admin', false])]
    #[TestWith(['leader', false])]
    #[TestWith(['employee', false])]
    #[TestWith(['admin', true])]
    public function test_all_roles_log_in_with_password_only_including_previously_enrolled_accounts(string $role, bool $previouslyEnrolled): void
    {
        $u = $this->person(['is_admin' => $role === 'admin', 'is_leader' => $role === 'leader', 'two_factor_secret' => $previouslyEnrolled ? encrypt('JBSWY3DPEHPK3PXP') : null, 'two_factor_confirmed_at' => $previouslyEnrolled ? now() : null]);
        $this->post('/login', ['email' => $u->email, 'password' => 'Local-Test-Password-82'])->assertRedirect('/');
        $this->assertAuthenticatedAs($u);
        $this->get('/')->assertOk();
        if ($role === 'admin') {
            $this->get('/administration')->assertOk();
        }
        if ($role === 'leader') {
            $this->get('/mit-team')->assertOk();
        }
        $this->assertNotNull($u->fresh()->first_login_at);
    }

    public function test_two_factor_enrollment_challenge_and_recovery_endpoints_are_removed(): void
    {
        $u = $this->person(['is_admin' => true]);
        $this->actingAs($u)->get('/sikkerhed')->assertOk()->assertSee('mailadresse og adgangskode')->assertDontSee('Opsæt totrinslogin');
        $this->get('/two-factor-challenge')->assertNotFound();
        $this->post('/two-factor-challenge', ['code' => '123456'])->assertNotFound();
        $this->post('/user/two-factor-authentication')->assertNotFound();
        $this->post('/user/confirmed-two-factor-authentication', ['code' => '123456'])->assertNotFound();
        $this->get('/user/two-factor-qr-code')->assertNotFound();
        $this->get('/user/two-factor-recovery-codes')->assertNotFound();
        $this->assertNull($u->fresh()->two_factor_secret);
    }

    public function test_admin_can_create_update_and_export_but_cannot_overwrite_stale_revision(): void
    {
        $a = $this->admin();

        $this->actingAs($a);
        $data = ['name' => 'Fiktiv Testperson', 'email' => 'fiktiv@eltekniq.example', 'active' => 1, 'is_admin' => 0, 'is_leader' => 0, 'password' => 'Separate-Test-Password-42'];
        $this->post('/administration/medarbejder', $data)->assertRedirect('/administration');
        $u = User::where('email', $data['email'])->firstOrFail();
        unset($data['password']);
        $data['revision'] = $u->revision;
        $data['job_title'] = 'Elektriker';
        $this->post('/administration/medarbejder/'.$u->id, $data)->assertRedirect('/administration');
        $this->post('/administration/medarbejder/'.$u->id, $data)->assertSessionHasErrors('revision');
        $this->get('/administration')->assertOk();
        $this->get('/administration/medarbejder/'.$u->id)->assertOk();
        $response = $this->get('/administration/adgang.csv')->assertOk();
        $this->assertStringContainsString('Eksporttidspunkt', $response->streamedContent());
        $this->assertStringNotContainsString('Afdeling', $response->streamedContent());
        foreach (['/', '/kollegaer', '/profil', '/administration', '/administration/medarbejder/'.$u->id] as $url) {
            $this->get($url)->assertOk()->assertDontSee('Afdeling')->assertDontSee('department_id', false);
        }
        $this->assertDatabaseHas('audit_events', ['event' => 'user_created', 'subject_id' => $u->id]);
    }

    public function test_disabling_account_deletes_sessions_without_deleting_history(): void
    {
        $a = $this->admin();
        $d = $this->department();
        $u = $this->person(['department_id' => $d, 'first_login_at' => now()]);
        DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $u->id, 'payload' => '', 'last_activity' => time()]);
        $this->actingAs($a)->post('/administration/medarbejder/'.$u->id, ['name' => $u->name, 'email' => $u->email, 'department_id' => $d, 'active' => 0, 'revision' => $u->revision])->assertRedirect('/administration');
        $this->assertDatabaseMissing('sessions', ['user_id' => $u->id]);
        $this->assertFalse($u->fresh()->active);
        $this->assertNotNull($u->fresh()->first_login_at);
    }

    public function test_reassigning_leaders_does_not_grant_historical_mus_access(): void
    {
        $admin = $this->admin();
        $d = $this->department();
        $old = $this->person(['is_leader' => true]);
        $new = $this->person(['is_leader' => true]);
        $u = $this->person(['department_id' => $d]);
        $id = DB::table('mus_conversations')->insertGetId(['employee_id' => $u->id, 'leader_id' => $old->id, 'employee_name' => $u->name, 'leader_name' => $old->name, 'scheduled_on' => '2026-09-15']);
        $this->actingAs($admin)->post('/administration/medarbejder/'.$u->id, ['name' => $u->name, 'email' => $u->email, 'department_id' => $d, 'active' => 1, 'revision' => $u->revision, 'leaders' => [$new->id]])->assertRedirect('/administration');
        $this->assertDatabaseHas('mus_conversations', ['id' => $id, 'leader_id' => $old->id]);
    }

    public function test_private_upload_rejects_wrong_types_and_owner_can_download_pdf(): void
    {
        $admin = $this->admin();
        $u = $this->person();
        Storage::fake('local');
        $this->actingAs($admin);
        $this->post('/profil/'.$u->id.'/filer', ['file' => UploadedFile::fake()->create('script.php', 2, 'text/plain')])->assertSessionHasErrors('file');
        $this->post('/profil/'.$u->id.'/filer', ['file' => UploadedFile::fake()->create('reference.pdf', 10, 'application/pdf')])->assertRedirect();
        $file = DB::table('private_files')->first();
        $this->assertNotNull($file);
        Storage::disk('local')->assertExists($file->path);
        $this->actingAs($u)->get('/filer/'.$file->id)->assertDownload('reference.pdf');
    }

    public function test_public_password_recovery_refers_to_office_and_mail_reset_is_disabled(): void
    {
        $this->get('/forgot-password')->assertOk()->assertSee('Richard Hellman')->assertDontSee('Opret nulstillingslink');
        $this->post('/forgot-password', ['email' => 'person@example.com'])->assertStatus(405);
        $this->post('/reset-password')->assertNotFound();
        $this->get('/reset-password/token')->assertNotFound();
    }
}
