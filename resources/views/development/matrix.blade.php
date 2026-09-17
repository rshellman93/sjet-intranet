@extends('layout')
@section('title','Kompetencematrix')
@section('content')
<div class="page-head"><div><div class="eyebrow">FAGLIGT OVERBLIK</div><h1>Kompetencematrix</h1><p>Seneste faglige vurderinger for {{ auth()->user()->is_admin ? 'aktive medarbejdere':'dit tildelte team' }}.</p></div>@if(auth()->user()->is_admin)<a class="btn secondary" href="{{ route('development.catalog') }}">Vedligehold katalog</a>@endif</div><div class="notice">Ikke vurderet betyder ukendt. Det er ikke dokumentation for manglende evne.</div>
<div class="table-wrap matrix"><table><thead><tr><th>Medarbejder</th>@foreach($competencies as $competency)<th>{{ $competency->name }}</th>@endforeach</tr></thead><tbody>@foreach($people as $person)<tr><td><a href="{{ route('development.card',$person->id) }}">{{ $person->name }}</a></td>@foreach($competencies as $competency) @php($level=$assessments[$person->id]->get($competency->id)?->level ?? 1)<td><span class="level level-{{ $level }}">{{ $levels[$level] }}</span></td>@endforeach</tr>@endforeach</tbody></table></div>
@endsection
