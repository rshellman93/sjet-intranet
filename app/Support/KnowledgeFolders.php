<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class KnowledgeFolders
{
    public static function all(): Collection
    {
        $folders = DB::table('knowledge_folders')->orderBy('name')->get();
        foreach ($folders as $folder) {
            $folder->path = self::trail($folders, $folder->id)->pluck('name')->implode(' / ');
        }

        return $folders->sortBy('path', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    public static function trail(Collection $folders, ?int $id): Collection
    {
        $trail = collect();
        $seen = [];
        while ($id && ! isset($seen[$id]) && ($folder = $folders->firstWhere('id', $id))) {
            $seen[$id] = true;
            $trail->prepend($folder);
            $id = $folder->parent_id;
        }

        return $trail;
    }

    public static function subtree(Collection $folders, int $id): array
    {
        $ids = [$id];
        for ($index = 0; $index < count($ids); $index++) {
            foreach ($folders->where('parent_id', $ids[$index]) as $child) {
                if (! in_array($child->id, $ids, true)) {
                    $ids[] = $child->id;
                }
            }
        }

        return $ids;
    }
}
