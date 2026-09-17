<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Development
{
    public const LEVELS = [1 => 'Ikke vurderet', 2 => 'Under oplæring', 3 => 'Kan arbejde selvstændigt', 4 => 'Kan oplære andre'];

    public const STATUSES = ['Behov', 'Planlagt', 'Gennemført', 'Aflyst'];

    public const EDUCATIONS = ['Elektriker', 'Elinstallatør', 'Maskinmester', 'Ingeniør/Konstruktør'];

    public const SAFETY_CERTIFICATES = ['Liftuddannelse', 'Stilladsuddannelse', 'Vejen som arbejdsplads', 'Truckcertifikat'];

    public static function latestAssessments(int $userId): Collection
    {
        return DB::table('competency_assessments')->where('user_id', $userId)->orderByDesc('assessed_on')->orderByDesc('id')->get()->unique('competency_id')->keyBy('competency_id');
    }

    public static function validity(object $record): string
    {
        if ($record->status !== 'Gennemført') {
            return 'Ikke gennemført';
        }
        if (! $record->valid_until) {
            return 'Gyldighed ikke oplyst';
        }
        $today = now()->timezone('Europe/Copenhagen')->toDateString();
        if ($record->valid_until < $today) {
            return 'Udløbet';
        }

        return $record->valid_until <= now()->timezone('Europe/Copenhagen')->addDays(90)->toDateString() ? 'Udløber snart' : 'Gyldigt til '.Carbon::parse($record->valid_until)->format('d/m/Y');
    }

    public static function courseDate(object $record): string
    {
        if ($record->date_precision === 'date' && $record->completed_on) {
            return Carbon::parse($record->completed_on)->format('d/m/Y');
        }

        return $record->original_date ? $record->original_date.($record->date_precision === 'unknown' ? ' · kræver afklaring' : '') : 'Dato ikke oplyst · kræver afklaring';
    }

    public static function expiryStatus(?string $date): string
    {
        if (! $date) {
            return 'missing';
        }

        $today = now()->timezone('Europe/Copenhagen')->toDateString();
        if ($date < $today) {
            return 'expired';
        }

        return $date <= now()->timezone('Europe/Copenhagen')->addMonths(3)->toDateString() ? 'soon' : 'valid';
    }

    public static function expiryLabel(?string $date): string
    {
        return match (self::expiryStatus($date)) {
            'expired' => 'Udløbet '.DateFormat::date($date),
            'soon' => 'Udløber '.DateFormat::date($date),
            'valid' => 'Gyldigt til '.DateFormat::date($date),
            default => 'Udløbsdato mangler',
        };
    }
}
