@extends('layout')
@section('title','Kollegaer')
@section('content')
<div class="page-head"><div><div class="eyebrow">MENNESKERNE BAG ELTEKNIQ</div><h1>Dine kollegaer</h1><p>Find kontaktoplysninger på dine kollegaer.</p></div><span class="pill">{{ count($people) }} kollegaer</span></div>
<form class="filters" method="get"><label>Søg efter navn eller stilling<input type="search" name="q" placeholder="Hvem leder du efter?" value="{{ request('q') }}" maxlength="100"></label><button type="submit" class="btn">Søg</button><a class="btn secondary" href="/kollegaer">Nulstil</a></form>
<div class="grid three">@forelse($people as $person)<article class="card person-card"><div class="avatar">{{ mb_substr($person->name,0,1) }}{{ mb_substr(explode(' ',$person->name)[1] ?? '',0,1) }}</div><h3>{{ $person->name }}</h3><p class="muted" style="font-size:13px">{{ $person->job_title ?: 'Stilling ikke angivet' }}</p><span class="contact">{{ $person->email }}</span><span class="contact">{{ $person->phone ?: 'Telefon ikke angivet' }}</span><a class="btn secondary small" style="margin-top:20px" href="/profil/{{ $person->id }}">Se profil ↗</a></article>@empty<div class="card wide empty"><h3>Ingen kollegaer fundet</h3><p>Prøv et andet navn eller en anden stilling.</p></div>@endforelse</div>
@endsection

