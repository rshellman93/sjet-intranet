<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

class DateFormat
{
    public static function date(null|string|CarbonInterface $value): string
    {
        if (! $value) {
            return '—';
        }

        return Carbon::parse($value)->timezone('Europe/Copenhagen')->format('d/m/Y');
    }

    public static function dateTime(null|string|CarbonInterface $value): string
    {
        if (! $value) {
            return '—';
        }

        return Carbon::parse($value)->timezone('Europe/Copenhagen')->format('d/m/Y H:i');
    }
}
