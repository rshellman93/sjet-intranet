<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Audit;
use App\Support\SchoolModules;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CalendarController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['month' => 'nullable|date_format:Y-m', 'user_id' => 'nullable|integer|exists:users,id', 'type' => ['nullable', Rule::in(['school', 'course', 'holiday'])]]);
        $start = Carbon::createFromFormat('!Y-m', $data['month'] ?? now()->format('Y-m'))->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $entries = DB::table('calendar_entries')->join('users', 'users.id', '=', 'calendar_entries.user_id')
            ->whereNull('cancelled_at')->where('starts_on', '<=', $end->toDateString())->where('ends_on', '>=', $start->toDateString())
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $data['user_id']))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $data['type']))
            ->orderBy('starts_on')->select('calendar_entries.*', 'users.name as employee_name')->get();
        $people = User::where(fn ($q) => $q->where('active', true)->orWhereIn('id', $entries->pluck('user_id')))
            ->when($request->filled('user_id'), fn ($q) => $q->whereKey($data['user_id']))->orderBy('name')->get(['id', 'name']);
        $payload = ['start' => $start->toDateString(), 'end' => $end->copy()->addDay()->toDateString(),
            'people' => $people, 'events' => $entries->map(fn ($entry) => [
                'id' => $entry->id, 'group' => $entry->user_id, 'start' => $entry->starts_on,
                'end' => Carbon::parse($entry->ends_on)->addDay()->toDateString(),
                'content' => self::label($entry), 'className' => $entry->type,
            ])];

        return view('calendar.index', compact('start', 'end', 'entries', 'people', 'payload'));
    }

    public static function label(object $entry): string
    {
        return match ($entry->type) {
            'holiday' => 'Ferie',
            'course' => 'Kursus'.($entry->title ? ': '.$entry->title : ''),
            default => $entry->school_stage === 'Modul' ? 'Modul '.$entry->module_code.' · '.(SchoolModules::ALL[$entry->module_code] ?? '') : 'Skole · '.$entry->school_stage,
        };
    }

    public function edit(Request $request, ?int $id = null)
    {
        abort_unless($request->user()->is_admin, 403);
        $entry = $id ? DB::table('calendar_entries')->whereNull('cancelled_at')->find($id) : null;
        abort_if($id && ! $entry, 404);

        return view('calendar.edit', ['entry' => $entry, 'people' => User::where('active', true)->when($entry, fn ($q) => $q->orWhere('id', $entry->user_id))->orderBy('name')->get(), 'stages' => SchoolModules::STAGES, 'modules' => SchoolModules::ALL]);
    }

    public function save(Request $request, ?int $id = null)
    {
        abort_unless($request->user()->is_admin, 403);
        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'type' => ['required', Rule::in(['school', 'course', 'holiday'])],
            'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on',
            'title' => 'nullable|required_if:type,course|string|max:190',
            'school' => 'nullable|string|max:190',
            'school_stage' => ['nullable', 'required_if:type,school', Rule::in(SchoolModules::STAGES)],
            'module_code' => ['nullable', Rule::requiredIf($request->input('type') === 'school' && $request->input('school_stage') === 'Modul'), Rule::in(array_keys(SchoolModules::ALL))],
            'note' => 'nullable|string|max:2000', 'revision' => $id ? 'required|integer' : 'nullable|integer',
        ]);
        if ($data['type'] !== 'school') {
            $data['school'] = $data['school_stage'] = $data['module_code'] = null;
        } elseif ($data['school_stage'] !== 'Modul') {
            $data['module_code'] = null;
        }
        if ($data['type'] !== 'course') {
            $data['title'] = null;
        }
        DB::transaction(function () use ($data, $id, $request): void {
            $current = $id ? DB::table('calendar_entries')->whereNull('cancelled_at')->lockForUpdate()->find($id) : null;
            abort_if($id && ! $current, 404);
            abort_if($current && (int) $current->revision !== (int) $data['revision'], 409, 'Registreringen er ændret. Genindlæs siden.');
            $data['revision'] = ($current?->revision ?? 0) + 1;
            $data['updated_by'] = $request->user()->id;
            $data['updated_at'] = now();
            if ($id) {
                DB::table('calendar_entries')->where('id', $id)->update($data);
            } else {
                $id = DB::table('calendar_entries')->insertGetId($data + ['created_at' => now()]);
            }
            Audit::record('calendar_saved', 'calendar_entry', $id);
        });

        return redirect()->route('calendar.index', ['month' => substr($data['starts_on'], 0, 7)])->with('success', 'Kalenderen er opdateret.');
    }

    public function cancel(Request $request, int $id)
    {
        abort_unless($request->user()->is_admin, 403);
        $data = $request->validate(['revision' => 'required|integer']);
        $changed = DB::table('calendar_entries')->where('id', $id)->where('revision', $data['revision'])->whereNull('cancelled_at')
            ->update(['cancelled_at' => now(), 'updated_by' => $request->user()->id, 'revision' => DB::raw('revision + 1'), 'updated_at' => now()]);
        abort_unless($changed, 409, 'Registreringen er ændret. Genindlæs siden.');
        Audit::record('calendar_cancelled', 'calendar_entry', $id);

        return redirect()->route('calendar.index')->with('success', 'Registreringen er aflyst.');
    }
}
