@extends('layout')
@section('title','Ny adgangskode')
@section('content')
<section class="card auth-card auth-single"><h2>Vælg en ny adgangskode</h2><form method="post" action="/reset-password">@csrf<input type="hidden" name="token" value="{{ $request->route('token') }}"><label>Mailadresse<input name="email" type="email" value="{{ old('email',$request->email) }}" required autocomplete="username"></label><label>Ny adgangskode<input name="password" type="password" minlength="12" required autocomplete="new-password"></label><label>Gentag adgangskoden<input name="password_confirmation" type="password" minlength="12" required autocomplete="new-password"></label><button type="submit" class="btn">Gem ny adgangskode</button></form></section>
@endsection
