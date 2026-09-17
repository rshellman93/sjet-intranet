@extends('layout')
@section('title',$person->exists ? 'Redigér medarbejder':'Opret medarbejder')
@section('content')
<div class="eyebrow">ADMINISTRATION / MEDARBEJDERE</div><h1>{{ $person->exists ? 'Redigér medarbejder':'Opret medarbejder' }}</h1><p class="muted">{{ $person->exists ? 'Opdatér kontaktoplysninger, adgang og ledertilknytning.' : 'Opret en personlig konto med eget login.' }}</p>
<form method="post" action="/administration/medarbejder{{ $person->exists ? '/'.$person->id:'' }}">@csrf
@if($person->exists)<input type="hidden" name="revision" value="{{ old('revision',$person->revision) }}">@endif
<div class="grid two" style="margin-top:26px"><section class="card"><h2>Medarbejderoplysninger</h2><label>Navn<input name="name" value="{{ old('name',$person->name) }}" required maxlength="120" autocomplete="name"></label><label>Personlig mailadresse<input name="email" type="email" value="{{ old('email',$person->email) }}" required maxlength="190" autocomplete="email"></label><fieldset class="role-picker"><legend>Stillingsbetegnelser</legend><p class="form-note">Vælg gerne flere, hvis medarbejderen har flere funktioner.</p>@php($selectedRoles=request()->old() ? old('job_roles',[]) : ($person->job_roles ?? []))<div class="role-options">@foreach(\App\Models\User::JOB_ROLES as $role)<label class="check role-option"><input type="checkbox" name="job_roles[]" value="{{ $role }}" @checked(in_array($role,$selectedRoles,true))>{{ $role }}</label>@endforeach</div></fieldset><label>Arbejdstelefon<input name="phone" type="tel" value="{{ old('phone',$person->phone) }}" maxlength="40"></label>@unless($person->exists)<label>Adgangskode<input name="password" type="password" required minlength="12" maxlength="200" autocomplete="new-password"><small>Mindst 12 tegn. Brug en unik adgangskode til hver konto.</small></label>@endunless</section>
<section class="card"><h2>Adgang og roller</h2><input type="hidden" name="active" value="0"><label class="check"><input type="checkbox" name="active" value="1" @checked(old('active',$person->active))>Kontoen er aktiv</label><p class="form-note">Deaktivering tilbagekalder aktive sessioner. Historik og private referencefiler bevares.</p><hr style="border:0;border-top:1px solid var(--line);margin:23px 0"><p>Alle konti har rollen Medarbejder.</p><input type="hidden" name="is_leader" value="0"><label class="check"><input type="checkbox" name="is_leader" value="1" @checked(old('is_leader',$person->is_leader))>Leder</label><input type="hidden" name="is_admin" value="0"><label class="check"><input type="checkbox" name="is_admin" value="1" @checked(old('is_admin',$person->is_admin))>Administrator og KLS-ansvarlig</label><p class="form-note">Alle roller logger ind med mailadresse og adgangskode.</p><hr style="border:0;border-top:1px solid var(--line);margin:23px 0"><h3>Medarbejderens ledere</h3><p class="form-note">Tildel de personer, der skal kunne se medarbejderens faglige profil. Dette ændrer ikke adgang til MUS-forløb.</p>@forelse($leaders as $leader)<label class="check"><input type="checkbox" name="leaders[]" value="{{ $leader->id }}" @checked(in_array($leader->id,old('leaders',request()->old() ? []:$assigned)))>{{ $leader->name }}</label>@empty<p class="muted">Der er endnu ingen aktive ledere.</p>@endforelse</section></div>
<div class="actions" style="margin-top:24px"><button type="submit" class="btn">Gem medarbejder</button><a class="btn secondary" href="/administration">Annullér</a></div></form>
@if($person->exists)
<section class="card" style="margin-top:28px"><h2>Nulstil adgangskode</h2><p>Giv medarbejderen den nye adgangskode personligt. Aktive sessioner bliver tilbagekaldt.</p>
<form method="post" action="/administration/medarbejder/{{ $person->id }}/adgangskode">@csrf
<input type="hidden" name="revision" value="{{ $person->revision }}">
<label>Din egen adgangskode<input type="password" name="current_password" required autocomplete="current-password"></label>
<label>Ny adgangskode<input type="password" name="password" required minlength="12" maxlength="200" autocomplete="new-password"></label>
<label>Gentag ny adgangskode<input type="password" name="password_confirmation" required minlength="12" maxlength="200" autocomplete="new-password"></label>
<button class="btn" type="submit">Nulstil adgangskode</button></form></section>
@endif
@endsection



