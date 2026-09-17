<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ImportKlsMatrix extends Command
{
    protected $signature = 'kls:import {file} {--apply} {--password-file=}';

    protected $description = 'Importér valideret KLS-matrix; standard er en læsekontrol uden ændringer.';

    public function handle(): int
    {
        $data = json_decode(file_get_contents($this->argument('file')), true, 512, JSON_THROW_ON_ERROR);
        Validator::make($data, ['source' => 'required|string', 'sheet' => 'required|string', 'employees' => 'required|array|min:1', 'employees.*.name' => 'required|string|max:120', 'employees.*.email' => 'required|email|distinct', 'employees.*.skills' => 'required|array', 'employees.*.skills.*.name' => 'required|string|max:190', 'employees.*.skills.*.kind' => 'required|in:rated,boolean', 'employees.*.skills.*.category' => 'required|string|max:100', 'employees.*.skills.*.value' => 'required', 'employees.*.skills.*.source_cell' => 'required|string'])->validate();
        $admin = User::where('email', 'rsh@sydjysk-eltekniq.dk')->where('is_admin', true)->firstOrFail();
        $new = 0;
        $existing = 0;
        $ratings = 0;
        $positive = 0;
        foreach ($data['employees'] as $employee) {
            User::where('email', mb_strtolower($employee['email']))->exists() ? $existing++ : $new++;
            foreach ($employee['skills'] as $skill) {
                if ($skill['kind'] === 'rated') {
                    if (! is_int($skill['value']) || $skill['value'] < 0 || $skill['value'] > 4) {
                        throw new \RuntimeException('Ugyldig eller manglende rating');
                    }
                    $ratings++;
                } else {
                    if (! is_bool($skill['value'])) {
                        throw new \RuntimeException('Boolean-værdi forventet');
                    }
                    if ($skill['value']) {
                        $positive++;
                    }
                }
            }
        }
        $this->info("Nye brugere: $new; eksisterende: $existing; ratings: $ratings; positive markeringer: $positive");
        if (! $this->option('apply')) {
            return self::SUCCESS;
        }
        $password = trim(file_get_contents($this->option('password-file')));
        if (strlen($password) < 12) {
            throw new \RuntimeException('Startkode skal være mindst 12 tegn');
        }
        DB::transaction(function () use ($data, $admin, $password) {
            foreach ($data['employees'] as $employee) {
                $email = mb_strtolower($employee['email']);
                $user = User::where('email', $email)->first();
                if (! $user) {
                    $user = new User;
                    $user->forceFill(['name' => $employee['name'], 'email' => $email, 'password' => $password, 'must_change_password' => true, 'active' => true, 'activated_at' => now(), 'is_admin' => false, 'is_leader' => false])->save();
                }
                foreach ($employee['skills'] as $skill) {
                    $definition = DB::table('kls_skills')->where('name', $skill['name'])->first();
                    if ($definition && ($definition->kind !== $skill['kind'] || $definition->category !== $skill['category'])) {
                        throw new \RuntimeException('Eksisterende kompetence har en anden type eller kategori');
                    }
                    $skillId = $definition?->id ?? DB::table('kls_skills')->insertGetId(['name' => $skill['name'], 'category' => $skill['category'], 'kind' => $skill['kind'], 'created_at' => now(), 'updated_at' => now()]);
                    if ($skill['kind'] === 'boolean' && ! $skill['value']) {
                        continue;
                    }
                    $key = hash('sha256', $data['source'].'|'.$email.'|'.$skill['name']);
                    if (DB::table('kls_records')->where('import_key', $key)->exists()) {
                        continue;
                    }
                    if (DB::table('kls_records')->where('user_id', $user->id)->where('skill_id', $skillId)->exists()) {
                        throw new \RuntimeException('Nyere registrering findes allerede; kræver manuel afstemning');
                    }
                    $basis = 'Importeret fra '.$data['source'].' / '.$data['sheet'].' / '.$skill['source_cell'].'. Oprindelig vurderingsdato og udløb ikke oplyst.';
                    if (isset($skill['correction'])) {
                        $basis .= ' '.$skill['correction'];
                    }
                    DB::table('kls_records')->insert(['user_id' => $user->id, 'skill_id' => $skillId, 'value' => (int) $skill['value'], 'recorded_by' => $admin->id, 'basis' => $basis, 'import_key' => $key, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            DB::table('audit_events')->insert(['actor_id' => $admin->id, 'event' => 'kls_matrix_imported', 'subject_type' => 'kls_matrix', 'subject_id' => null, 'metadata' => json_encode(['source' => $data['source'], 'employees' => count($data['employees'])]), 'created_at' => now()]);
        });
        $this->info('Import afsluttet. Eksisterende adgangskoder og roller er bevaret.');

        return self::SUCCESS;
    }
}
