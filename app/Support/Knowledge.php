<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Knowledge
{
    public const EXTRACTION = ['ready' => 'PDF-teksten er søgbar.', 'empty' => 'Ingen tekst fundet. Filen kan være scannet; supplér beskrivelse og søgeord.', 'failed' => 'PDF-teksten kunne ikke udtrækkes. Kontrollér forhåndsvisningen og supplér søgeord.', 'partial' => 'Kun en del af PDF-teksten er indekseret. Supplér søgeord.'];

    public static function version(User $user, int $documentId, ?int $number = null): object
    {
        $document = DB::table('documents')->find($documentId);
        abort_unless($document, 404);
        $query = DB::table('document_versions')->where('document_id', $documentId);
        $version = $number === null ? $query->where('id', $document->current_version_id)->first() : $query->where('version', $number)->first();
        abort_unless($version, 404);
        abort_unless($user->is_admin || ($version->status === 'Publiceret' && (int) $document->current_version_id === (int) $version->id), 404);

        return $version;
    }

    public static function search(string $query, ?int $folder): Collection
    {
        $versions = DB::table('documents')->join('document_versions as v', 'v.id', '=', 'documents.current_version_id')->where('v.status', 'Publiceret');
        if ($folder) {
            $versions->whereIn('v.folder_id', KnowledgeFolders::subtree(KnowledgeFolders::all(), $folder));
        }
        $terms = preg_split('/\s+/u', mb_strtolower(trim($query)), -1, PREG_SPLIT_NO_EMPTY);

        return $versions->select('v.*')->orderByDesc('v.published_at')->get()->map(function ($version) use ($terms) {
            $fields = [$version->title, $version->keywords ?? '', $version->description, $version->body ?? '', $version->pdf_text ?? ''];
            $lower = array_map(fn ($text) => mb_strtolower($text), $fields);
            $score = 0;
            foreach ($terms as $term) {
                $matched = false;
                foreach ($lower as $index => $text) {
                    if (str_contains($text, $term)) {
                        $matched = true;
                        $score += [100, 70, 20, 5, 5][$index];
                    }
                }
                if (! $matched) {
                    return null;
                }
            }
            $snippet = $version->description;
            foreach ([2, 3, 4, 0, 1] as $index) {
                foreach ($terms as $term) {
                    $position = mb_strpos($lower[$index], $term);
                    if ($position !== false) {
                        $start = max(0, $position - 50);
                        $snippet = ($start ? '…' : '').mb_substr($fields[$index], $start, 200);
                        break 2;
                    }
                }
            }
            $version->snippet = mb_strlen($snippet) > 200 ? mb_substr($snippet, 0, 200).'…' : $snippet;
            $version->score = $score;

            return $version;
        })->filter()->sortByDesc('score')->values();
    }
}
