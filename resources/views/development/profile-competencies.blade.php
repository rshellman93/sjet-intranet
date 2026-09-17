@extends('layout')
@section('title','Kompetencer')
@section('content')
@php($isAdmin=auth()->user()->is_admin)
@php($ratedSkills=$skills->where('kind','rated'))
<link rel="stylesheet" href="/planning.css?v=20260916-2">
<script src="/profile-forms.js" defer></script>
<div class="page-head"><div><h1>{{ $person->name }}</h1><p>{{ $person->jobRolesLabel() }}</p></div></div>
<nav class="actions" aria-label="Medarbejderprofil"><a class="btn secondary" href="/profil/{{ $person->id }}">Profil</a><a class="btn" aria-current="page" href="/profil/{{ $person->id }}?tab=kompetencer">Kompetencer</a></nav>
<div class="section-head"><h2>Kompetencer</h2>@if($isAdmin || auth()->user()->is_leader)<a class="btn secondary" href="{{ route('development.matrix') }}">Samlet kompetencematrix</a>@endif</div>
@foreach($skills->reject(fn($skill)=>in_array($skill->name,\App\Support\Development::SAFETY_CERTIFICATES))->groupBy('category') as $category=>$group)
<details class="card disclosure" @if($group->contains('kind','rated')) open @endif><summary>{{ $category==='Uddannelse og certifikater' ? 'Uddannelse og kørekort' : $category }}</summary>
@foreach($group as $skill)
@php($record=$skillRecords->get($skill->id))
<div class="info-row"><div><strong>{{ $skill->name }}</strong><p>{{ !$record ? 'Ikke registreret' : ($skill->kind==='rated' ? 'Niveau '.$record->value : ($record->value ? 'Ja':'Nej')) }} · {{ $record?->assessed_on ? \App\Support\DateFormat::date($record->assessed_on) : 'Dato ikke oplyst' }}</p>
@if($record?->basis)<details><summary>Note</summary><p>{{ $record->basis }}</p></details>@endif
@if($isAdmin || ($skill->kind==='rated' && auth()->user()->canAssess((int)$person->id)))
<details><summary>Rediger {{ $skill->kind==='rated' ? 'vurdering':'registrering' }}</summary><form method="post" action="{{ route('kls.save',$person->id) }}">@csrf
<input type="hidden" name="skill_id" value="{{ $skill->id }}"><input type="hidden" name="previous_id" value="{{ $record?->id ?? 0 }}">
<div class="form-grid"><label>{{ $skill->kind==='rated' ? 'Niveau':'Status' }}<select name="value">@foreach($skill->kind==='rated' ? [0=>'0',1=>'1',2=>'2',3=>'3',4=>'4'] : [0=>'Nej',1=>'Ja'] as $value=>$label)<option value="{{ $value }}" @selected($record && (int)$record->value===$value)>{{ $label }}</option>@endforeach</select></label>
<label>Vurderingsdato<input type="date" name="assessed_on" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}" required></label></div>
<label>Note (valgfri)<textarea name="basis" maxlength="2000"></textarea></label><button class="btn">Gem</button></form></details>@endif
</div></div>
@endforeach
@if($group->contains('kind','rated') && $isAdmin)
<button class="btn secondary" type="button" data-toggle="add-skill" aria-expanded="false" aria-controls="add-skill">＋ Tilføj faglig kompetence</button>
<form id="add-skill" hidden method="post" action="{{ route('kls.skill') }}">@csrf<input type="hidden" name="kind" value="rated"><input type="hidden" name="category" value="Faglige kompetencer"><label>Kompetencens navn<input name="name" maxlength="190" required></label><p class="muted">Kompetencen bliver tilgængelig for alle medarbejdere.</p><button class="btn">Opret kompetence</button></form>
@endif
</details>@endforeach
@include('development.kls-scale')
@if($educations->isNotEmpty() || $licenses->isNotEmpty())
<section class="card"><h2>Øvrige uddannelser og kørekort</h2>@foreach($educations as $education)<p>{{ $education->title }} @if($education->note) · {{ $education->note }} @endif</p>@endforeach @foreach($licenses as $license)<p>Kørekort {{ $license->category }} @if($license->note) · {{ $license->note }} @endif</p>@endforeach</section>
@endif
@if($isAdmin)
<details class="card disclosure"><summary>Tilføj uddannelse eller kørekort</summary>
<div class="grid two"><form method="post" action="{{ route('development.education.save',$person->id) }}">@csrf<label>Grunduddannelse<select name="title">@foreach($educationOptions as $option)<option>{{ $option }}</option>@endforeach</select></label><label>Note (valgfri)<textarea name="note" maxlength="2000"></textarea></label><button class="btn secondary">Gem uddannelse</button></form>
<form method="post" action="{{ route('development.license.save',$person->id) }}">@csrf<label>Kørekortkategori<input name="category" maxlength="20" placeholder="B, BE, C" required></label><label>Note (valgfri)<textarea name="note" maxlength="2000"></textarea></label><button class="btn secondary">Gem kørekort</button></form></div></details>
@endif
<div class="section-head"><h2>Certifikater</h2></div>
<section class="card">
@forelse($profileCertificates as $certificate)
<div class="info-row"><div><strong>{{ $certificate->title }}</strong><p><span class="expiry {{ $certificate->never_expires ? 'valid' : \App\Support\Development::expiryStatus($certificate->expires_on) }}">{{ $certificate->never_expires ? 'Udløber ikke' : \App\Support\Development::expiryLabel($certificate->expires_on) }}</span></p>
@if($certificate->note)<p>{{ $certificate->note }}</p>@endif
@if($certificate->file_id)<a href="/filer/{{ $certificate->file_id }}">Hent PDF-bevis</a>@endif
@if($isAdmin)<details><summary>Rediger certifikat</summary><form method="post" action="{{ route('profile.certificate.save',$person->id) }}" enctype="multipart/form-data" data-certificate-form>@csrf<input type="hidden" name="mode" value="save"><input type="hidden" name="certificate_id" value="{{ $certificate->id }}">
@include('development.certificate-fields',['certificate'=>$certificate])
<button class="btn secondary">Gem certifikat</button></form></details>@endif</div></div>
@empty<p class="muted">Ingen certifikater registreret endnu.</p>@endforelse
@if($isAdmin)<details><summary>＋ Tilføj certifikat</summary><form method="post" action="{{ route('profile.certificate.save',$person->id) }}" enctype="multipart/form-data" data-certificate-form>@csrf<input type="hidden" name="mode" value="save">
@include('development.certificate-fields',['certificate'=>null])
<button class="btn">Gem certifikat</button></form></details>@endif
</section>
<div class="section-head"><h2>Tilknyttede certificeringer</h2></div>
<section class="card">
@forelse($profileLinks as $link)
@php($linked=$profileCertificates->firstWhere('id',$link->certificate_id))
<div class="info-row"><div><strong>{{ $link->skill_name }}</strong><p>{{ $linked?->title }} · {{ $linked?->never_expires ? 'Udløber ikke' : \App\Support\Development::expiryLabel($linked?->expires_on) }}</p>@if($linked?->file_id)<a href="/filer/{{ $linked->file_id }}">Hent PDF-bevis</a>@endif</div>
@if($isAdmin)<form method="post" action="{{ route('profile.certificate.unlink',[$person->id,$link->id]) }}">@csrf<button class="btn secondary small">Fjern tilknytning</button></form>@endif</div>
@empty<p class="muted">Ingen tilknyttede certificeringer endnu.</p>@endforelse
@if($isAdmin)
<details><summary>＋ Knyt certifikat til kompetence</summary><form method="post" action="{{ route('profile.certificate.save',$person->id) }}" enctype="multipart/form-data" data-link-form data-certificate-form>@csrf<input type="hidden" name="mode" value="link">
<label>Faglig kompetence<select name="skill_id"><option value="">Opret ny faglig kompetence</option>@foreach($ratedSkills as $skill)<option value="{{ $skill->id }}">{{ $skill->name }}</option>@endforeach</select></label>
<label data-new-skill>Navn på ny kompetence<input name="new_skill" maxlength="190"><small>Tilføjes det fælles katalog, så andre også kan vurderes.</small></label>
<label>Certifikat<select name="certificate_id"><option value="">Opret nyt certifikat</option>@foreach($profileCertificates as $certificate)<option value="{{ $certificate->id }}">{{ $certificate->title }}</option>@endforeach</select></label>
<fieldset data-new-certificate><legend>Nyt certifikat til {{ $person->name }}</legend>@include('development.certificate-fields',['certificate'=>null])</fieldset>
<button class="btn">Gem tilknytning</button></form></details>
@endif
</section>
<details class="card disclosure"><summary>Vurderingshistorik</summary>@foreach($skillHistory as $entry)<p><strong>{{ $skills->firstWhere('id',$entry->skill_id)?->name }} · {{ $entry->value }}</strong><br>{{ \App\Support\DateFormat::dateTime($entry->created_at) }} · {{ $people[$entry->recorded_by] ?? 'Ukendt' }}@if($entry->basis)<br>{{ $entry->basis }}@endif</p>@endforeach</details>
@endsection
