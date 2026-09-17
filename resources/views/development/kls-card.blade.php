@extends('layout')
@section('title','KLS-kompetencevurdering')
@section('content')
@php($ratedSkills=$skills->where('kind','rated'))
<div class="page-head"><div><div class="eyebrow">KLS / KOMPETENCEKORT</div><h1>{{ $person->name }}</h1><p>{{ $person->jobRolesLabel() }}</p></div><a class="btn secondary" href="{{ route('kls.matrix') }}">Tilbage til matrix</a></div>
<div class="kls-overview"><div><strong>{{ $records->whereIn('skill_id',$ratedSkills->pluck('id'))->count() }}/{{ $ratedSkills->count() }}</strong><span>faglige vurderinger</span></div><div><strong>{{ $records->where('value','>',0)->count() }}</strong><span>aktive registreringer</span></div></div>
<p class="notice">Import fra Excel er en overførsel af eksisterende oplysninger. Niveauerne 0–4 vises som registreret.</p>
<div class="kls-groups">@foreach($skills->groupBy('category') as $category=>$group)<details class="card kls-group" open><summary><strong>{{ $category }}</strong><small>{{ $group->filter(fn($skill)=>$records->has($skill->id))->count() }} af {{ $group->count() }} registreret</small></summary><div class="kls-list">@foreach($group as $skill) @php($record=$records->get($skill->id))<div class="kls-entry"><div><strong>{{ $skill->name }}</strong><p>@if(!$record)Ikke registreret @elseif($skill->kind==='boolean'){{ $record->value ? 'Ja':'Nej' }} @else Niveau {{ $record->value }} @endif</p></div>@if($record)<small>{{ $record->assessed_on ? \Carbon\Carbon::parse($record->assessed_on)->format('d/m/Y') : 'Dato ikke oplyst' }}</small>@endif</div>@endforeach</div></details>@endforeach</div>
@include('development.kls-scale')
@endsection
