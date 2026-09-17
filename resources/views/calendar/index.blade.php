@extends('layout')
@section('title','Kalender')
@section('content')
<link rel="stylesheet" href="/planning.css?v=20260917-3">
<div class="page-head"><div><div class="eyebrow">MEDARBEJDEROVERBLIK</div><h1>Kalender</h1><p>Ferie, kurser og skoleophold · hele dage</p></div>@if(auth()->user()->is_admin)<a class="btn" href="{{ route('calendar.edit') }}">＋ Opret registrering</a>@endif</div>
<form class="card calendar-filters" method="get"><label>Måned<input type="month" name="month" value="{{ $start->format('Y-m') }}" required></label>
<label>Medarbejder<select name="user_id"><option value="">Alle medarbejdere</option>@foreach(\App\Models\User::where('active',true)->orderBy('name')->get(['id','name']) as $employee)<option value="{{ $employee->id }}" @selected((string)request('user_id')===(string)$employee->id)>{{ $employee->name }}</option>@endforeach</select></label>
<label>Vis<select name="type"><option value="">Alt</option>@foreach(['holiday'=>'Ferie','course'=>'Kursus','school'=>'Skoleophold'] as $key=>$label)<option value="{{ $key }}" @selected(request('type')===$key)>{{ $label }}</option>@endforeach</select></label><button class="btn secondary">Vis</button></form>
<div class="section-head"><a class="btn secondary" href="{{ route('calendar.index',array_merge(request()->only(['user_id','type']),['month'=>$start->copy()->subMonth()->format('Y-m')])) }}">← Forrige måned</a><h2>{{ $start->locale('da')->translatedFormat('F Y') }}</h2><a class="btn secondary" href="{{ route('calendar.index',array_merge(request()->only(['user_id','type']),['month'=>$start->copy()->addMonth()->format('Y-m')])) }}">Næste måned →</a></div>
<div class="calendar-legend"><span class="holiday">Ferie</span><span class="course">Kursus</span><span class="school">Skoleophold</span></div>
<p class="muted">Én række pr. medarbejder. Træk tidslinjen vandret for at rulle, og brug knapperne til at zoome. Hold Ctrl nede, mens du bruger musehjulet, for at zoome direkte i kalenderen. Klik på en periode for at se oplysningerne nedenfor.</p>
<div class="calendar-controls" role="group" aria-label="Styr kalenderens tidslinje">
 <button class="btn secondary" type="button" data-calendar-action="previous">← Rul tilbage</button>
 <button class="btn secondary" type="button" data-calendar-action="zoom-in">＋ Zoom ind</button>
 <button class="btn secondary" type="button" data-calendar-action="zoom-out">− Zoom ud</button>
 <button class="btn secondary" type="button" data-calendar-action="reset">Vis hele måneden</button>
 <button class="btn secondary" type="button" data-calendar-action="next">Rul frem →</button>
</div>
<p class="muted calendar-scroll-hint">På en smal skærm kan du trække kalenderen vandret eller bruge knapperne. Registreringerne står også i listen nedenunder.</p>
<div class="calendar-scroll" tabindex="0" role="region" aria-label="Kalender, træk eller brug knapperne for at rulle og zoome"><div id="staff-calendar" data-calendar="{{ json_encode($payload,JSON_UNESCAPED_UNICODE) }}" aria-label="Kalender med medarbejdere"></div></div>
@vite('resources/js/staff-calendar.js')
<div class="section-head"><h2>Registreringer i perioden</h2><span>{{ $entries->count() }} registreringer</span></div>
@forelse($entries as $entry)
<article class="card calendar-entry" id="calendar-entry-{{ $entry->id }}" tabindex="-1"><div class="section-head"><div><h3>{{ $entry->employee_name }}</h3><p>{{ \App\Http\Controllers\CalendarController::label($entry) }}</p></div>@if(auth()->user()->is_admin)<a class="btn secondary" href="{{ route('calendar.edit',$entry->id) }}">Rediger</a>@endif</div>
<p><strong>{{ \App\Support\DateFormat::date($entry->starts_on) }} – {{ \App\Support\DateFormat::date($entry->ends_on) }}</strong> · begge dage inklusive</p>@if($entry->school)<p>{{ $entry->school }}</p>@endif @if($entry->note)<p>{{ $entry->note }}</p>@endif</article>
@empty<div class="card empty"><h3>Ingen registreringer i denne periode</h3><p>Administratorer kan registrere aftalt ferie, kurser og skoleophold.</p></div>@endforelse
@endsection
