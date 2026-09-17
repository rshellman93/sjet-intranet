@extends('layout')
@section('title','Login og sikkerhed')
@section('content')
<div class="eyebrow">DIN PERSONLIGE KONTO</div><h1>Login og sikkerhed</h1><p class="muted">Du logger ind med din mailadresse og adgangskode.</p>
<section class="card" style="max-width:660px;margin-top:28px"><h2>Personligt login</h2><dl class="detail-list"><div><dt>Navn</dt><dd>{{ auth()->user()->name }}</dd></div><div><dt>Mailadresse</dt><dd>{{ auth()->user()->email }}</dd></div></dl><p>Brug din egen konto, og log ud, når du er færdig på en delt computer.</p><p class="muted">Hvis du har glemt din adgangskode, kan du vælge “Glemt adgangskode?” på login-siden.</p><a href="/profil" class="btn secondary">Tilbage til min profil</a></section>
@endsection
