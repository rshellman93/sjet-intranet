<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ToolController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate([
            'q' => 'nullable|string|max:100',
            'status' => ['nullable', Rule::in(['available', 'loaned', 'inactive'])],
            'user_id' => 'nullable|integer|exists:users,id',
            'sort' => ['nullable', Rule::in(['asset_number', 'name', 'category', 'status'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
        $query = DB::table('shared_tools as tools')
            ->leftJoin('shared_tool_loans as loans', fn ($join) => $join->on('loans.tool_id', '=', 'tools.id')->whereNull('loans.returned_at'))
            ->leftJoin('users', 'users.id', '=', 'loans.user_id')
            ->select('tools.*', 'loans.id as loan_id', 'loans.user_id', 'loans.site', 'loans.checked_out_at', 'users.name as employee_name');
        if ($request->filled('q')) {
            $term = '%'.$data['q'].'%';
            $query->where(fn ($q) => $q->where('tools.asset_number', 'like', $term)->orWhere('tools.name', 'like', $term)
                ->orWhere('tools.category', 'like', $term)->orWhereExists(fn ($serials) => $serials->selectRaw('1')->from('shared_tool_serial_numbers as serials')->whereColumn('serials.tool_id', 'tools.id')->where('serials.serial_number', 'like', $term))
                ->orWhere('users.name', 'like', $term)->orWhere('loans.site', 'like', $term));
        }
        if (($data['status'] ?? null) === 'available') {
            $query->where('tools.active', true)->whereNull('loans.id');
        } elseif (($data['status'] ?? null) === 'loaned') {
            $query->whereNotNull('loans.id');
        } elseif (($data['status'] ?? null) === 'inactive') {
            $query->where('tools.active', false);
        } else {
            $query->where('tools.active', true);
        }
        if ($request->filled('user_id')) {
            $query->where('loans.user_id', $data['user_id']);
        }

        $sort = $data['sort'] ?? 'asset_number';
        $direction = $data['direction'] ?? 'asc';
        match ($sort) {
            'name' => $query->orderBy('tools.name', $direction),
            'category' => $query->orderBy('tools.category', $direction)->orderBy('tools.name'),
            'status' => $query->orderByRaw('loans.id IS NULL '.($direction === 'asc' ? 'ASC' : 'DESC'))->orderBy('tools.name'),
            default => $query->orderByRaw("CAST(REGEXP_SUBSTR(tools.asset_number, '[0-9]+') AS UNSIGNED) {$direction}")->orderBy('tools.asset_number', $direction),
        };
        $tools = $query->get();
        $serials = DB::table('shared_tool_serial_numbers')->whereIn('tool_id', $tools->pluck('id'))->orderBy('id')->get()->groupBy('tool_id');
        $tools->each(fn ($tool) => $tool->serial_numbers = $serials->get($tool->id, collect()));

        return view('tools.index', [
            'tools' => $tools,
            'people' => User::where('active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(int $id): View
    {
        $tool = DB::table('shared_tools')->find($id);
        abort_unless($tool, 404);
        $serialNumbers = DB::table('shared_tool_serial_numbers')->where('tool_id', $id)->orderBy('id')->pluck('serial_number');
        $current = DB::table('shared_tool_loans as loans')->join('users', 'users.id', '=', 'loans.user_id')
            ->where('loans.tool_id', $id)->whereNull('loans.returned_at')->select('loans.*', 'users.name as employee_name')->first();
        $history = DB::table('shared_tool_loans as loans')->join('users', 'users.id', '=', 'loans.user_id')
            ->leftJoin('users as checkout_admin', 'checkout_admin.id', '=', 'loans.checked_out_by')
            ->leftJoin('users as return_admin', 'return_admin.id', '=', 'loans.returned_by')
            ->where('loans.tool_id', $id)->orderByDesc('loans.checked_out_at')
            ->select('loans.*', 'users.name as employee_name', 'checkout_admin.name as checkout_admin_name', 'return_admin.name as return_admin_name')->get();

        return view('tools.show', ['tool' => $tool, 'serialNumbers' => $serialNumbers, 'current' => $current, 'history' => $history,
            'people' => User::where('active', true)->orderBy('name')->get(['id', 'name'])]);
    }

    public function edit(Request $request, ?int $id = null): View
    {
        abort_unless($request->user()->is_admin, 403);
        $tool = $id ? DB::table('shared_tools')->find($id) : null;
        abort_if($id && ! $tool, 404);

        return view('tools.edit', ['tool' => $tool, 'serialNumbers' => $tool ? DB::table('shared_tool_serial_numbers')->where('tool_id', $id)->orderBy('id')->pluck('serial_number') : collect()]);
    }

    public function save(Request $request, ?int $id = null): RedirectResponse
    {
        abort_unless($request->user()->is_admin, 403);
        $data = $request->validate([
            'asset_number' => ['required', 'string', 'max:80', Rule::unique('shared_tools')->ignore($id)],
            'name' => 'required|string|max:190', 'category' => 'nullable|string|max:120',
            'manufacturer' => 'nullable|string|max:120', 'model' => 'nullable|string|max:120',
            'serial_numbers' => 'nullable|string|max:5000', 'note' => 'nullable|string|max:2000', 'active' => 'nullable|boolean',
            'is_fleet' => 'nullable|boolean', 'is_leased' => 'nullable|boolean',
        ]);
        $serialNumbers = collect(preg_split('/[+\r\n]+/', $data['serial_numbers'] ?? ''))->map(fn ($serial) => trim($serial))->filter()->unique()->values();
        if ($serialNumbers->count() > 50 || $serialNumbers->contains(fn ($serial) => mb_strlen($serial) > 190)) {
            throw ValidationException::withMessages(['serial_numbers' => 'Hvert serienummer må højst være 190 tegn, og et kit kan højst have 50 serienumre.']);
        }
        if ($request->boolean('is_fleet') && $request->boolean('is_leased')) {
            throw ValidationException::withMessages(['is_leased' => 'Vælg enten Fleet eller leaset – ikke begge.']);
        }
        $duplicate = DB::table('shared_tool_serial_numbers')->whereIn('serial_number', $serialNumbers)->when($id, fn ($query) => $query->where('tool_id', '!=', $id))->value('serial_number');
        if ($duplicate) {
            throw ValidationException::withMessages(['serial_numbers' => 'Serienummeret '.$duplicate.' er allerede registreret på et andet værktøj.']);
        }
        unset($data['serial_numbers']);
        foreach (['asset_number', 'name', 'category', 'manufacturer', 'model', 'note'] as $field) {
            $data[$field] = isset($data[$field]) ? trim($data[$field]) : null;
        }
        $data['serial_number'] = $serialNumbers->join(' + ') ?: null;
        $data['active'] = $request->boolean('active');
        $data['is_fleet'] = $request->boolean('is_fleet');
        $data['is_leased'] = $request->boolean('is_leased');
        $data['updated_by'] = $request->user()->id;
        $data['updated_at'] = now();
        DB::transaction(function () use ($id, $data, $serialNumbers): void {
            if ($id) {
                $current = DB::table('shared_tools')->lockForUpdate()->find($id);
                abort_unless($current, 404);
                if (! $data['active']) {
                    abort_if(DB::table('shared_tool_loans')->where('tool_id', $id)->whereNull('returned_at')->exists(), 409, 'Værktøjet skal registreres retur, før det kan deaktiveres.');
                }
                DB::table('shared_tools')->where('id', $id)->update($data);
                $toolId = $id;
                Audit::record('shared_tool_updated', 'shared_tool', $id);
            } else {
                $toolId = DB::table('shared_tools')->insertGetId($data + ['created_at' => now()]);
                Audit::record('shared_tool_created', 'shared_tool', $toolId);
            }
            DB::table('shared_tool_serial_numbers')->where('tool_id', $toolId)->delete();
            foreach ($serialNumbers as $serialNumber) {
                DB::table('shared_tool_serial_numbers')->insert(['tool_id' => $toolId, 'serial_number' => $serialNumber, 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        return redirect()->route('tools.index')->with('success', 'Værktøjet er gemt.');
    }

    public function checkout(Request $request, int $id): RedirectResponse
    {
        abort_unless($request->user()->is_admin, 403);
        $data = $request->validate(['user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('active', true)],
            'site' => 'nullable|string|max:190', 'checkout_note' => 'nullable|string|max:2000']);
        DB::transaction(function () use ($request, $id, $data): void {
            $tool = DB::table('shared_tools')->lockForUpdate()->find($id);
            abort_unless($tool && $tool->active, 404);
            abort_if(DB::table('shared_tool_loans')->where('tool_id', $id)->whereNull('returned_at')->exists(), 409, 'Værktøjet er allerede udlånt.');
            $loanId = DB::table('shared_tool_loans')->insertGetId(['tool_id' => $id, 'user_id' => $data['user_id'],
                'site' => trim($data['site'] ?? '') ?: null, 'checkout_note' => trim($data['checkout_note'] ?? '') ?: null,
                'checked_out_at' => now(), 'checked_out_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            Audit::record('shared_tool_checked_out', 'shared_tool_loan', $loanId, ['tool_id' => $id, 'user_id' => $data['user_id']]);
        });

        return back()->with('success', 'Udlånet er registreret med tidspunkt.');
    }

    public function returnTool(Request $request, int $id): RedirectResponse
    {
        abort_unless($request->user()->is_admin, 403);
        $data = $request->validate(['return_note' => 'nullable|string|max:2000']);
        DB::transaction(function () use ($request, $id, $data): void {
            DB::table('shared_tools')->where('id', $id)->lockForUpdate()->firstOrFail();
            $loan = DB::table('shared_tool_loans')->where('tool_id', $id)->whereNull('returned_at')->lockForUpdate()->first();
            abort_unless($loan, 409, 'Værktøjet er allerede registreret retur.');
            DB::table('shared_tool_loans')->where('id', $loan->id)->update(['returned_at' => now(), 'returned_by' => $request->user()->id,
                'return_note' => trim($data['return_note'] ?? '') ?: null, 'updated_at' => now()]);
            Audit::record('shared_tool_returned', 'shared_tool_loan', $loan->id, ['tool_id' => $id]);
        });

        return back()->with('success', 'Værktøjet er registreret retur med tidspunkt.');
    }
}
