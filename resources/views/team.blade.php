@extends('layout')
@section('title','Mit team')
@section('content')
<div class="eyebrow">DINE TILDELTE MEDARBEJDERE</div><h1>Mit team</h1><p class="muted">Dit team følger de medarbejdere, du er tildelt som leder.</p>
<div class="actions"><a class="btn" href="/mus">MUS og opfølgning</a><a class="btn secondary" href="/kompetencer">Teamets kompetencer</a></div><div class="grid three" style="margin-top:28px">@forelse($people as $person)<article class="card"><div class="avatar" style="margin-bottom:15px">{{ mb_substr($person->name,0,1) }}</div><h3>{{ $person->name }}</h3><p class="muted">{{ $person->job_title }}</p><span class="pill {{ $person->active ? '':'inactive' }}">{{ $person->active ? 'Aktiv':'Deaktiveret' }}</span><div style="margin-top:20px"><a class="btn secondary" href="/profil/{{ $person->id }}">Åbn profil ↗</a></div></article>@empty<div class="card wide empty"><h3>Du har endnu ikke et team</h3><p>En administrator kan tilknytte medarbejdere til dig.</p></div>@endforelse</div><p class="notice">Nye ledertilknytninger giver ikke adgang til historiske samtaler.</p>
@endsection

