# Sydjysk Eltekniq intranet

Privat medarbejderintranet bygget med Laravel, Blade, Vite og React-baserede komponenter.

## Funktioner

- Medarbejderprofiler, roller og adgangsstyring
- Kompetencer, KLS-vurderinger, uddannelser og certifikater
- Vidensbibliotek med mapper og PDF-læser
- Fælles kalender til ferie, kurser og skoleophold
- Fællesværktøj med serienumre, udlån, retur og placering

## Lokal opsætning

Krav: PHP 8.4, Composer, Node.js og en understøttet database.

```bash
composer install
npm install
copy .env.example .env
php artisan key:generate
php artisan migrate
npm run build
php artisan serve
```

På macOS eller Linux bruges `cp .env.example .env` i stedet for `copy`.

## Fortrolige data

Repositoryet indeholder ikke produktionsmiljøets `.env`, database, logfiler, uploadede dokumenter, adgangskoder eller SSH-nøgler. Disse skal opsættes særskilt på hvert miljø.

