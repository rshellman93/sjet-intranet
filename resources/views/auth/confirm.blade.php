@extends('layout')
@section('title','Bekræft adgangskode')
@section('content')
<section class="card auth-card auth-single"><h2>Bekræft, at det er dig</h2><p class="muted">Indtast din adgangskode for at ændre sikkerhedsindstillinger.</p><form method="post" action="/user/confirm-password">@csrf<label>Adgangskode<input type="password" name="password" required autocomplete="current-password" autofocus></label><button class="btn" type="submit">Fortsæt</button></form></section>
@endsection
