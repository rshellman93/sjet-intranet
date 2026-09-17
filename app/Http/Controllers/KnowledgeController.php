<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Audit;
use App\Support\Knowledge;
use App\Support\KnowledgeFolders;
use App\Support\PdfText;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class KnowledgeController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate(['q' => 'nullable|string|max:150', 'mappe' => 'nullable|integer|exists:knowledge_folders,id', 'kategori' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1']);
        $folder = $request->integer('mappe') ?: null;
        if (! $folder && $request->filled('kategori')) {
            $folder = DB::table('knowledge_folders')->where('slug', $data['kategori'])->value('id');
        }
        $results = Knowledge::search($data['q'] ?? '', $folder);
        $page = $request->integer('page', 1);
        $items = new LengthAwarePaginator($results->forPage($page, 12)->values(), $results->count(), 12, $page, ['path' => $request->url(), 'query' => $request->query()]);
        $folders = KnowledgeFolders::all();
        $counts = DB::table('documents')->join('document_versions as v', 'v.id', '=', 'documents.current_version_id')->where('v.status', 'Publiceret')->selectRaw('v.folder_id, count(*) as total')->groupBy('v.folder_id')->pluck('total', 'folder_id');

        return view('knowledge', compact('items', 'folders', 'counts', 'folder') + ['children' => $folders->where('parent_id', $folder), 'trail' => KnowledgeFolders::trail($folders, $folder)]);
    }

    public function manage(Request $request): View
    {
        abort_unless($request->user()->is_admin, 403);
        $documents = DB::table('documents')->orderByDesc('updated_at')->get();
        $versions = DB::table('document_versions')->orderByDesc('version')->get()->groupBy('document_id');

        return view('knowledge.manage', compact('documents', 'versions') + ['folders' => KnowledgeFolders::all()]);
    }

    public function folder(Request $request, ?int $id = null): RedirectResponse
    {
        abort_unless($request->user()->is_admin, 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:80', Rule::unique('knowledge_folders', 'name')->ignore($id)], 'revision' => $id ? 'required|integer' : 'nullable', 'parent_id' => $id ? 'prohibited' : 'nullable|integer|exists:knowledge_folders,id']);
        DB::transaction(function () use ($id, $data) {
            if ($id) {
                abort_unless(DB::table('knowledge_folders')->where('id', $id)->where('revision', $data['revision'])->update(['name' => $data['name'], 'revision' => DB::raw('revision + 1'), 'updated_at' => now()]), 409, 'Mappen er ændret. Genindlæs siden.');
            } else {
                $id = DB::table('knowledge_folders')->insertGetId(['name' => $data['name'], 'parent_id' => $data['parent_id'] ?? null, 'slug' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
            }
            Audit::record('knowledge_folder_saved', 'knowledge_folder', $id);
        });

        return back()->with('success', 'Mappen er gemt.');
    }

    public function edit(Request $request, ?int $id = null, ?int $number = null): View
    {
        abort_unless($request->user()->is_admin, 403);
        $version = $id ? Knowledge::version($request->user(), $id, $number) : null;
        abort_if($version && $version->status !== 'Kladde', 409, 'Publicerede versioner kan ikke redigeres. Opret en ny kladde.');

        return view('knowledge.edit', ['version' => $version, 'document' => $id ? DB::table('documents')->find($id) : null, 'folders' => KnowledgeFolders::all(), 'people' => User::where('active', true)->orderBy('name')->get(), 'file' => $version?->file_id ? DB::table('private_files')->find($version->file_id) : null]);
    }

    public function save(Request $request, ?int $id = null, ?int $number = null): RedirectResponse
    {
        abort_unless($request->user()->is_admin, 403);
        $version = $id ? Knowledge::version($request->user(), $id, $number) : null;
        abort_if($version && $version->status !== 'Kladde', 409, 'Publicerede versioner kan ikke overskrives.');
        $data = $request->validate(['title' => 'required|string|max:190', 'folder_id' => 'required|integer|exists:knowledge_folders,id', 'type' => ['required', Rule::in(['article', 'pdf'])], 'description' => 'required|string|max:1000', 'body' => 'nullable|required_if:type,article|string|max:100000', 'keywords' => 'nullable|string|max:1000', 'responsible_id' => 'required|integer|exists:users,id', 'review_on' => 'nullable|date_format:Y-m-d', 'changes' => 'nullable|string|max:2000', 'file' => 'nullable|file|mimes:pdf|max:20480', 'revision' => $id ? 'required|integer' : 'nullable', 'document_revision' => $id ? 'required|integer' : 'nullable', 'creation_token' => $id ? 'nullable' : 'required|uuid']);
        if ($data['type'] === 'pdf' && ! $request->hasFile('file') && ! $version?->file_id) {
            return back()->withInput()->withErrors(['file' => 'Vælg en PDF-fil.']);
        }
        if ($data['type'] === 'article' && $request->hasFile('file')) {
            return back()->withInput()->withErrors(['file' => 'Vælg PDF som indholdstype for at uploade en fil.']);
        }
        $newPath = null;
        $extraction = [];
        if ($request->hasFile('file')) {
            $newPath = $request->file('file')->store('knowledge', 'local');
            $extraction = PdfText::extract(Storage::disk('local')->path($newPath));
        }
        try {
            $saved = DB::transaction(function () use ($request, $id, $number, $data, $newPath, $extraction) {
                if (! $id && ($existing = DB::table('documents')->where('creation_token', $data['creation_token'])->first())) {
                    return ['document' => $existing->id, 'version' => 1, 'duplicate' => true];
                }
                if ($id) {
                    $version = Knowledge::version($request->user(), $id, $number);
                    abort_unless($version->status === 'Kladde' && (int) $version->revision === (int) $data['revision'], 409, 'Kladde ændret i en anden fane. Genindlæs før du fortsætter.');
                    abort_unless(DB::table('documents')->where('id', $id)->where('revision', $data['document_revision'])->update(['revision' => DB::raw('revision + 1'), 'updated_at' => now()]), 409, 'Dokumentet er ændret. Genindlæs siden.');
                } else {
                    $id = DB::table('documents')->insertGetId(['title' => $data['title'], 'category' => 'folder', 'creation_token' => $data['creation_token'], 'created_at' => now(), 'updated_at' => now()]);
                    $version = null;
                    $number = 1;
                }
                $fileId = $version?->file_id;
                if ($newPath) {
                    $upload = $request->file('file');
                    $fileId = DB::table('private_files')->insertGetId(['owner_id' => $request->user()->id, 'uploaded_by' => $request->user()->id, 'purpose' => 'knowledge', 'name' => $upload->getClientOriginalName(), 'path' => $newPath, 'mime' => 'application/pdf', 'size' => $upload->getSize(), 'created_at' => now(), 'updated_at' => now()]);
                }
                $values = array_intersect_key($data, array_flip(['title', 'folder_id', 'type', 'description', 'keywords', 'responsible_id', 'review_on', 'changes']));
                $values += ['body' => $data['type'] === 'article' ? $data['body'] : null, 'file_id' => $data['type'] === 'pdf' ? $fileId : null, 'updated_at' => now(), 'revision' => ($version?->revision ?? 0) + 1];
                if ($data['type'] === 'pdf' && $newPath) {
                    $values = array_merge($values, $extraction);
                }
                if ($data['type'] === 'article') {
                    $values += ['pdf_text' => null, 'pdf_text_status' => null, 'page_count' => null];
                }
                if ($version) {
                    DB::table('document_versions')->where('id', $version->id)->update($values);
                } else {
                    DB::table('document_versions')->insert($values + ['document_id' => $id, 'version' => 1, 'status' => 'Kladde', 'created_at' => now()]);
                }
                Audit::record('knowledge_draft_saved', 'document', $id, ['version' => $number]);

                return ['document' => $id, 'version' => $number, 'duplicate' => false];
            });
        } catch (\Throwable $error) {
            if ($newPath) {
                Storage::disk('local')->delete($newPath);
            }
            throw $error;
        }
        if ($newPath && $saved['duplicate']) {
            Storage::disk('local')->delete($newPath);
        }

        return redirect()->route('knowledge.version', [$saved['document'], $saved['version']])->with('success', 'Kladde gemt. Gennemgå indholdet, og publicér når det er klar.');
    }

    public function show(Request $request, int $id, ?int $number = null): View
    {
        $version = Knowledge::version($request->user(), $id, $number);

        return view('knowledge.show', ['version' => $version, 'document' => DB::table('documents')->find($id), 'folder' => DB::table('knowledge_folders')->find($version->folder_id), 'trail' => KnowledgeFolders::trail(KnowledgeFolders::all(), $version->folder_id), 'responsible' => User::find($version->responsible_id), 'file' => $version->file_id ? DB::table('private_files')->find($version->file_id) : null, 'history' => $request->user()->is_admin ? DB::table('document_versions')->where('document_id', $id)->orderByDesc('version')->get() : collect()]);
    }

    public function publish(Request $request, int $id, int $number): RedirectResponse
    {
        abort_unless($request->user()->is_admin, 403);
        $data = $request->validate(['revision' => 'required|integer', 'document_revision' => 'required|integer']);
        DB::transaction(function () use ($request, $id, $number, $data) {
            $version = Knowledge::version($request->user(), $id, $number);
            $document = DB::table('documents')->find($id);
            if ($version->status === 'Publiceret' && (int) $document->current_version_id === (int) $version->id) {
                return;
            }
            abort_unless($version->status === 'Kladde' && (int) $version->revision === (int) $data['revision'] && (int) $document->revision === (int) $data['document_revision'], 409, 'Indholdet er ændret. Gennemgå den nye kladde før publicering.');
            abort_unless($version->title && $version->folder_id && $version->description && ($version->type === 'pdf' ? $version->file_id : $version->body), 422, 'Kladde mangler indhold.');
            if ($document->current_version_id) {
                DB::table('document_versions')->where('id', $document->current_version_id)->update(['status' => 'Arkiveret', 'updated_at' => now()]);
            }
            DB::table('document_versions')->where('id', $version->id)->update(['status' => 'Publiceret', 'published_at' => now(), 'updated_at' => now()]);
            DB::table('documents')->where('id', $id)->update(['current_version_id' => $version->id, 'title' => $version->title, 'revision' => DB::raw('revision + 1'), 'updated_at' => now()]);
            Audit::record('knowledge_published', 'document', $id, ['version' => $number]);
        });

        return redirect()->route('knowledge.show', $id)->with('success', 'Dokumentet er publiceret og kan nu læses af alle aktive medarbejdere.');
    }

    public function newVersion(Request $request, int $id, int $number): RedirectResponse
    {
        abort_unless($request->user()->is_admin, 403);
        $data = $request->validate(['document_revision' => 'required|integer']);
        $next = DB::transaction(function () use ($request, $id, $number, $data) {
            $source = Knowledge::version($request->user(), $id, $number);
            $existing = DB::table('document_versions')->where('document_id', $id)->where('status', 'Kladde')->first();
            if ($existing) {
                return $existing->version;
            }
            abort_unless(DB::table('documents')->where('id', $id)->where('revision', $data['document_revision'])->update(['revision' => DB::raw('revision + 1'), 'updated_at' => now()]), 409, 'Dokumentet er ændret. Genindlæs siden.');
            $next = (int) DB::table('document_versions')->where('document_id', $id)->max('version') + 1;
            $copy = (array) $source;
            unset($copy['id']);
            $copy = array_merge($copy, ['version' => $next, 'status' => 'Kladde', 'revision' => 1, 'published_at' => null, 'changes' => null, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('document_versions')->insert($copy);
            Audit::record('knowledge_version_created', 'document', $id, ['version' => $next]);

            return $next;
        });

        return redirect()->route('knowledge.edit', [$id, $next])->with('success', 'Ny kladde oprettet. Den gældende version er fortsat tilgængelig, indtil du publicerer.');
    }

    public function archive(Request $request, int $id, int $number): RedirectResponse
    {
        abort_unless($request->user()->is_admin, 403);
        $data = $request->validate(['document_revision' => 'required|integer']);
        DB::transaction(function () use ($request, $id, $number, $data) {
            $version = Knowledge::version($request->user(), $id, $number);
            if ($version->status === 'Arkiveret') {
                return;
            }
            $document = DB::table('documents')->find($id);
            abort_unless((int) $document->revision === (int) $data['document_revision'], 409, 'Dokumentet er ændret. Genindlæs siden.');
            DB::table('document_versions')->where('id', $version->id)->update(['status' => 'Arkiveret', 'updated_at' => now()]);
            DB::table('documents')->where('id', $id)->update(['current_version_id' => (int) $document->current_version_id === (int) $version->id ? null : $document->current_version_id, 'revision' => DB::raw('revision + 1'), 'updated_at' => now()]);
            Audit::record('knowledge_archived', 'document', $id, ['version' => $number]);
        });

        return redirect()->route('knowledge.manage')->with('success', 'Versionen er arkiveret. Historikken er bevaret.');
    }

    public function pdf(Request $request, int $id, int $number): BinaryFileResponse
    {
        $version = Knowledge::version($request->user(), $id, $number);
        abort_unless($version->type === 'pdf' && $version->file_id, 404);
        $file = DB::table('private_files')->where('purpose', 'knowledge')->find($version->file_id);
        abort_unless($file && Storage::disk('local')->exists($file->path), 404);
        $response = response()->file(Storage::disk('local')->path($file->path), ['Content-Type' => 'application/pdf', 'Cache-Control' => 'no-store, private']);
        $response->setContentDisposition($request->boolean('download') ? 'attachment' : 'inline', 'dokument-'.$id.'-v'.$number.'.pdf');

        return $response;
    }
}
