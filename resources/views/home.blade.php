@extends('layout')
@section('title','Hjem')
@section('content')
<div class="page-head"><div><div class="eyebrow">DIN ARBEJDSDAG STARTER HER</div><h1>Hej, {{ explode(' ',auth()->user()->name)[0] }}<span style="color:var(--brand-green)">.</span></h1><p>Velkommen til dit fælles sted for viden og kollegaer.</p></div><div class="date">{{ now()->timezone('Europe/Copenhagen')->translatedFormat('l \d\e\n j. F Y') }}</div></div>
<section class="hero"><div class="eyebrow">VI BYGGER VORES FÆLLES INTRANET</div><h2>Godt arbejde begynder<br>med et godt overblik.</h2><p>Få overblik over dine kompetencer, kurser og udviklingsmål.<br>Din MUS og opfølgning ligger samlet under din profil.</p><a class="btn" href="/profil">Se min profil <span aria-hidden="true">↗</span></a></section>
<div class="section-head"><h2>Find din viden</h2><span class="muted" style="font-size:12px">Artikler og PDF-filer</span></div>
<div class="grid three">
    <a class="card shortcut" href="/viden?kategori=haandbog"><span class="card-icon" aria-hidden="true">▤</span><h3>Personalehåndbog <span>↗</span></h3><p>Rammerne for vores hverdag</p></a>
    <a class="card shortcut" href="/viden?kategori=sikkerhed"><span class="card-icon" aria-hidden="true">◇</span><h3>APV og sikkerhed <span>↗</span></h3><p>Et sikkert sted at arbejde</p></a>
    <a class="card shortcut" href="/viden?kategori=kemi"><span class="card-icon" aria-hidden="true">◈</span><h3>Kemi <span>↗</span></h3><p>Produkter og sikkerhedsdatablade</p></a>
</div>
<div class="grid two" style="margin-top:25px"><section class="card"><div class="eyebrow">MENNESKERNE BAG ARBEJDET</div><h2>Vi er {{ $colleagues }} kollegaer</h2><p class="muted">Find kontaktoplysninger på dine kollegaer.</p><a class="btn secondary" href="/kollegaer">Find en kollega ↗</a></section><section class="card"><div class="eyebrow">DIN ADGANG</div><div class="info-row"><div><strong>Personligt login</strong><p>Du er logget ind med din personlige konto.</p></div><span class="pill">Aktiv</span></div><div class="info-row"><div><strong>Min profil</strong><p>{{ auth()->user()->job_title ?? 'Stilling ikke angivet' }}</p></div><a href="/profil" aria-label="Åbn min profil">↗</a></div></section></div>
@endsection


