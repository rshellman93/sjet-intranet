@extends('layout')
@section('title','Vælg din adgangskode')
@section('content')
<section class="card auth-card auth-single"><h1>Vælg din personlige adgangskode</h1><p>Før du får adgang til intranettet, skal du udskifte startkoden med din egen kode på mindst 12 tegn.</p><form method="post" action="{{ route('password.first.update') }}">@csrf<label>Startkode<input type="password" name="current_password" required autocomplete="current-password"></label><label>Ny personlig adgangskode<input type="password" name="password" minlength="12" maxlength="200" required autocomplete="new-password"></label><label>Gentag adgangskoden<input type="password" name="password_confirmation" minlength="12" maxlength="200" required autocomplete="new-password"></label><button class="btn" type="submit">Gem min adgangskode</button></form></section>
@endsection
