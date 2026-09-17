@extends('layout')
@section('title','Kalenderregistrering')
@section('content')
<script src="/calendar-forms.js" defer></script>
<div class="page-head"><div><h1>{{ $entry ? 'Rediger registrering' : 'Ny registrering' }}</h1><p>Alle medarbejdere kan se kalenderens oplysninger.</p></div><a class="btn secondary" href="{{ route('calendar.index') }}">Tilbage til kalender</a></div>
<form class="card" method="post" action="{{ route('calendar.save',$entry?->id) }}" id="calendar-form">@csrf
@if($entry)<input type="hidden" name="revision" value="{{ $entry->revision }}">@endif
<div class="form-grid"><label>Medarbejder<select name="user_id" required>@foreach($people as $person)<option value="{{ $person->id }}" @selected((string)old('user_id',$entry?->user_id)===(string)$person->id)>{{ $person->name }}</option>@endforeach</select></label>
<label>Type<select name="type" required>@foreach(['school'=>'Skoleophold','course'=>'Kursus','holiday'=>'Ferie'] as $value=>$label)<option value="{{ $value }}" @selected(old('type',$entry?->type)===$value)>{{ $label }}</option>@endforeach</select></label>
<label>Startdato<input name="starts_on" type="date" value="{{ old('starts_on',$entry?->starts_on ?? now()->toDateString()) }}" required></label><label>Slutdato (sidste dag inklusive)<input name="ends_on" type="date" value="{{ old('ends_on',$entry?->ends_on ?? now()->toDateString()) }}" required></label></div>
<fieldset data-kind="school"><legend>Skoleophold</legend><label>Forløb<select name="school_stage">@foreach($stages as $stage)<option @selected(old('school_stage',$entry?->school_stage)===$stage)>{{ $stage }}</option>@endforeach</select></label>
<label data-module>Modul<select name="module_code"><option value="">Vælg ét modul</option>@foreach($modules as $code=>$name)<option value="{{ $code }}" @selected(old('module_code',$entry?->module_code)===(string)$code)>{{ $code }} · {{ $name }}</option>@endforeach</select></label>
<label>Skole (valgfri)<input name="school" maxlength="190" value="{{ old('school',$entry?->school) }}"></label></fieldset>
<fieldset data-kind="course"><legend>Kursus</legend><label>Kursusnavn<input name="title" maxlength="190" value="{{ old('title',$entry?->title) }}"></label></fieldset>
<label>Note (valgfri, synlig for alle)<textarea name="note" maxlength="2000">{{ old('note',$entry?->note) }}</textarea></label>
<button class="btn">Gem registrering</button>
</form>
@if($entry)<details class="card disclosure"><summary>Aflys registreringen</summary><p>Den fjernes fra kalenderen. Historikken bevares.</p><form method="post" action="{{ route('calendar.cancel',$entry->id) }}">@csrf<input type="hidden" name="revision" value="{{ $entry->revision }}"><button class="btn secondary">Aflys registrering</button></form></details>@endif
@endsection
