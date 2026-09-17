<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class Audit
{
    public static function record(string $event, string $type, ?int $id, array $metadata = []): void
    {
        DB::table('audit_events')->insert(['actor_id' => auth()->id(), 'event' => $event, 'subject_type' => $type, 'subject_id' => $id, 'metadata' => json_encode($metadata), 'created_at' => now()]);
    }
}
