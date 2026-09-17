<!doctype html>
<html lang="da">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><meta name="theme-color" content="#10284e"><title>@yield('title','Personaleintranet') · Sydjysk Eltekniq</title><link rel="icon" href="/brand/logo.png" type="image/png"><link rel="stylesheet" href="/app.css"><link rel="stylesheet" href="/brand.css?v=1"><link rel="stylesheet" href="/development.css?v=1"><link rel="stylesheet" href="/knowledge.css?v=1"><script src="/app.js" defer></script></head>
<body class="@guest guest @endguest">
<a class="skip" href="#main">Gå til indhold</a>
@auth
<aside class="sidebar">
    <a class="brand" href="/"><img class="brand-logo" src="/brand/logo-white.png" alt="Sydjysk Eltekniq" width="250" height="86"></a>
    <span class="nav-label">PERSONALEINTRANET</span>
    <nav aria-label="Hovednavigation">
        @foreach([['/','⌂','Hjem'],['/viden','▤','Viden'],['/kollegaer','♧','Kollegaer'],['/profil','◉','Min profil']] as [$url,$icon,$label])
        <a class="nav-item {{ request()->is($url==='/' ? '/' : ltrim($url,'/').'*') ? 'selected':'' }}" href="{{ $url }}"><span aria-hidden="true">{{ $icon }}</span>{{ $label }}</a>
        @endforeach
        <a class="nav-item {{ request()->is('kalender*') ? 'selected':'' }}" href="{{ route('calendar.index') }}"><span aria-hidden="true">▦</span>Kalender</a>
        <a class="nav-item {{ request()->is('vaerktoej*') ? 'selected':'' }}" href="{{ route('tools.index') }}"><span aria-hidden="true">⌁</span>Værktøj</a>
        @if(auth()->user()->is_leader)<a class="nav-item {{ request()->is('mit-team*') ? 'selected':'' }}" href="/mit-team"><span aria-hidden="true">◎</span>Mit team</a>@endif
        @if(auth()->user()->is_admin)<div class="nav-rule"></div><a class="nav-item {{ request()->is('administration*') ? 'selected':'' }}" href="/administration"><span aria-hidden="true">⚙</span>Administration</a>@endif
    </nav>
    <div class="sidebar-bottom"><div class="local-dot">{{ app()->environment('local') ? 'Lokalt på din computer' : 'Medarbejderpilot' }}</div><p>Et fælles sted til viden,<br>mennesker og udvikling.</p><a href="/sikkerhed">Login og sikkerhed ↗</a></div>
</aside>
@endauth
<div class="workspace">
<header class="topbar"><span class="breadcrumb">Sydjysk Eltekniq <span>/</span> @yield('title','Intranet')</span><div class="top-right"><span class="test-badge">{{ app()->environment('local') ? 'LOKAL TESTVERSION' : 'MEDARBEJDERPILOT' }}</span>@auth<span class="user-initials" aria-hidden="true">{{ mb_substr(auth()->user()->name,0,1) }}</span><form method="post" action="/logout">@csrf<button class="logout" type="submit">Log ud</button></form>@endauth</div></header>
<main id="main">
    @if(session('success'))<div class="alert success" role="status">{{ session('success') }}</div>@endif
    @if(session('status'))<div class="alert success" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="alert error" role="alert"><strong>Kontrollér oplysningerne</strong><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>@endif
    @yield('content')
</main>
<footer>SYDJYSK ELTEKNIQ <span>·</span> {{ app()->environment('local') ? 'Kun fiktive testdata' : 'Personaleintranet' }} <span>·</span> {{ now()->timezone('Europe/Copenhagen')->format('Y') }}</footer>
</div>
</body></html>


