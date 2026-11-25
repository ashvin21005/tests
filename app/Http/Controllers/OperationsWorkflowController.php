<?php

namespace App\Http\Controllers;

use App\Models\Child;
use App\Models\HealthStation;
use App\Models\ImmunizationRecord;
use App\Models\SmsNotification;
use App\Models\VaccinationSchedule;
use App\Models\Vaccine;
use App\Models\VaccineInventory;
use App\Services\EpiScheduleService;
use App\Services\VaccineInventoryService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OperationsWorkflowController extends Controller
{
    public function immunizations(Request $request)
    {
        $recordsQuery = ImmunizationRecord::with(['child.guardian', 'recorder'])->latest('administered_at');
        $this->scopeRecordsToUser($recordsQuery);
        $summaryQuery = clone $recordsQuery;
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $recordsQuery->where(function ($query) use ($search) {
                $query->where('vaccine', 'like', "%{$search}%")
                    ->orWhereHas('child', fn ($children) => $children->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%"));
            });
        }

        return view('operations.immunizations', [
            'records' => $recordsQuery->paginate(15)->withQueryString(),
            'search' => $search,
            'children' => $this->childrenForCurrentUser()->orderBy('last_name')->get(),
            'vaccines' => $this->vaccineNames(),
            'vaccineIds' => Vaccine::whereIn('name', $this->vaccineNames())->pluck('id', 'name'),
            'summary' => [
                'this_month' => (clone $summaryQuery)->whereMonth('administered_at', now()->month)->whereYear('administered_at', now()->year)->count(),
                'children' => (clone $summaryQuery)->distinct('child_id')->count('child_id'),
                'latest' => (clone $summaryQuery)->max('administered_at'),
            ],
        ]);
    }

    public function createImmunization()
    {
        return redirect()->route('immunizations.index', ['record' => 1]);
    }

    public function storeImmunization(Request $request)
    {
        $data = $request->validate($this->immunizationRules());
        $this->ensureChildIsInScope((int) $data['child_id']);
        $vaccine = Vaccine::where('name', $data['vaccine'])->where('is_active', true)->firstOrFail();

        $child = DB::transaction(function () use ($request, $data, $vaccine) {
            $child = Child::findOrFail($data['child_id']);
            abort_unless($child->health_station_id, 403);
            $validation = app(EpiScheduleService::class)->validationForLiveDose(
                $child,
                $vaccine,
                (int) $data['dose_number'],
                $data['administered_at']
            );
            app(VaccineInventoryService::class)->deductDose($child->health_station_id, $vaccine);

            ImmunizationRecord::create($data + [
                'vaccine_id' => $vaccine->id,
                'recorded_by' => $request->user()->id,
                'validation_status' => $validation['status'],
                'status' => $validation['status'] === 'INVALID' ? 'INVALID' : 'COMPLETED',
            ]);

            return $child;
        });
        app(EpiScheduleService::class)->syncChild($child->fresh());

        return redirect()->route('immunizations.index')->with('success', 'Immunization record saved.');
    }

    public function editImmunization(ImmunizationRecord $record)
    {
        $this->ensureChildIsInScope($record->child_id);
        $vaccines = $this->vaccineNames(false, $record->vaccine);

        return view('operations.record-form', [
            'record' => $record,
            'children' => $this->childrenForCurrentUser()->orderBy('last_name')->get(),
            'vaccines' => $vaccines,
            'vaccineIds' => Vaccine::whereIn('name', $vaccines)->pluck('id', 'name'),
        ]);
    }

    public function updateImmunization(Request $request, ImmunizationRecord $record)
    {
        $this->ensureChildIsInScope($record->child_id);
        $sourceChildId = (int) $record->child_id;
        $data = $request->validate($this->immunizationRules($record));
        $this->ensureChildIsInScope((int) $data['child_id']);
        $vaccine = Vaccine::where('name', $data['vaccine'])->firstOrFail();
        $targetChild = Child::findOrFail($data['child_id']);
        $validation = app(EpiScheduleService::class)->validationForLiveDose(
            $targetChild,
            $vaccine,
            (int) $data['dose_number'],
            $data['administered_at'],
            $record
        );
        DB::transaction(function () use ($record, $data, $vaccine) {
            $oldDose = [(int) $record->child_id, $record->vaccine, (int) $record->dose_number];
            $record->update($data + [
                'vaccine_id' => $vaccine->id,
                'date_precision' => 'DAY',
                'record_type' => 'LIVE',
                'validation_status' => 'VALID',
                'status' => 'COMPLETED',
            ]);

            if ($oldDose !== [(int) $record->child_id, $record->vaccine, (int) $record->dose_number]) {
                VaccinationSchedule::where('child_id', $oldDose[0])
                    ->where('vaccine', $oldDose[1])
                    ->where('dose_number', $oldDose[2])
                    ->update(['status' => 'UPCOMING']);
            }
            VaccinationSchedule::where('child_id', $record->child_id)
                ->where('vaccine', $record->vaccine)
                ->where('dose_number', $record->dose_number)
                ->update(['status' => 'COMPLETED']);
        });
        $record->update([
            'validation_status' => $validation['status'],
            'status' => $validation['status'] === 'INVALID' ? 'INVALID' : 'COMPLETED',
        ]);
        app(EpiScheduleService::class)->syncChild($targetChild->fresh());
        if ($sourceChildId !== $targetChild->id) {
            app(EpiScheduleService::class)->syncChild(Child::findOrFail($sourceChildId));
        }

        return redirect()->route('immunizations.index')->with('success', 'Immunization record updated.');
    }

    public function destroyImmunization(Request $request, ImmunizationRecord $record)
    {
        $this->ensureChildIsInScope($record->child_id);

        $child = $record->child;
        DB::transaction(function () use ($record) {
            $record->delete();
        });
        app(EpiScheduleService::class)->syncChild($child);

        return redirect()->route('immunizations.index')->with('success', 'Immunization record deleted.');
    }

    public function schedule(Request $request)
    {
        $query = VaccinationSchedule::with(['child.guardian', 'vaccineDefinition'])->latest('scheduled_date');
        $this->scopeSchedulesToUser($query);
        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status');
        $vaccine = $request->query('vaccine');
        if ($search !== '') {
            $query->where(function ($schedules) use ($search) {
                $schedules->where('vaccine', 'like', "%{$search}%")
                    ->orWhereHas('child', fn ($children) => $children->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%"));
            });
        }
        if ($vaccine) {
            $query->where('vaccine', $vaccine);
        }
        if ($request->query('date')) {
            $query->whereDate('scheduled_date', $request->query('date'));
        }
        $allSchedules = $query->get();
        $status = match ($status) {
            'UPCOMING' => 'PENDING',
            'DELAYED', 'MISSED' => 'OVERDUE',
            default => $status,
        };
        if (in_array($status, ['PENDING', 'DUE', 'OVERDUE', 'COMPLETED', 'INVALID'], true)) {
            $allSchedules = $allSchedules->filter(fn ($schedule) => $schedule->display_status === $status)->values();
        }
        $perPage = 15;
        $page = LengthAwarePaginator::resolveCurrentPage();
        $schedules = new LengthAwarePaginator(
            $allSchedules->forPage($page, $perPage)->values(),
            $allSchedules->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('operations.schedule', [
            'schedules' => $schedules,
            'search' => $search,
            'status' => $status,
            'vaccine' => $vaccine,
            'vaccines' => $this->vaccineNames(),
            'children' => $this->childrenForCurrentUser()->orderBy('last_name')->get(),
            'summary' => $this->scheduleSummary(),
        ]);
    }

    public function updateScheduleStatus(Request $request, VaccinationSchedule $schedule)
    {
        $this->ensureChildIsInScope($schedule->child_id);
        $data = $request->validate([
            'status' => ['sometimes', 'required', Rule::in(['UPCOMING', 'MISSED'])],
            'scheduled_date' => ['sometimes', 'required', 'date'],
        ]);
        if ($data === []) {
            throw ValidationException::withMessages(['scheduled_date' => 'Provide a new schedule date or status.']);
        }
        if (isset($data['scheduled_date']) && ! $schedule->generated_by_protocol) {
            $data['due_date'] = $data['scheduled_date'];
        }
        $schedule->update($data);
        if (array_key_exists('scheduled_date', $data)) {
            SmsNotification::where('vaccination_schedule_id', $schedule->id)
                ->where('status', 'PENDING')
                ->update(['scheduled_date' => $data['scheduled_date']]);
        }

        return redirect()->route('schedule.index')->with('success', 'Vaccination schedule updated.');
    }

    public function destroySchedule(Request $request, VaccinationSchedule $schedule)
    {
        $this->ensureChildIsInScope($schedule->child_id);

        DB::transaction(function () use ($schedule) {
            SmsNotification::where('vaccination_schedule_id', $schedule->id)
                ->where('status', 'PENDING')
                ->delete();
            $schedule->delete();
        });

        return redirect()->route('schedule.index')->with('success', 'Scheduled dose removed. Sent reminder history was retained.');
    }

    public function inventory(Request $request)
    {
        $query = VaccineInventory::with(['healthStation', 'vaccineDefinition'])->orderBy('vaccine');
        $this->scopeInventoryToUser($query, $request->user());
        $search = trim((string) $request->query('search', ''));
        $vaccine = $request->query('vaccine');
        if ($search !== '') {
            $query->where('vaccine', 'like', "%{$search}%");
        }
        if ($vaccine && $this->vaccineNames()->contains($vaccine)) {
            $query->where('vaccine', $vaccine);
        }
        $summaryQuery = clone $query;

        return view('operations.inventory', [
            'stocks' => $query->paginate(15)->withQueryString(),
            'search' => $search,
            'vaccine' => $vaccine,
            'stations' => HealthStation::orderBy('name')->get(),
            'vaccines' => $this->vaccineNames(),
            'summary' => [
                'types' => (clone $summaryQuery)->count(),
                'low' => (clone $summaryQuery)->whereColumn('quantity', '<=', 'reorder_level')->where('quantity', '>', 0)->count(),
                'empty' => (clone $summaryQuery)->where('quantity', 0)->count(),
            ],
        ]);
    }

    public function storeInventory(Request $request)
    {
        abort_unless($request->user()->isCho(), 403);
        $data = $request->validate([
            'vaccine' => ['required', Rule::in($this->vaccineNames()->all())],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'reorder_level' => ['required', 'integer', 'min:0', 'max:100000'],
            'health_station_id' => ['nullable', 'exists:health_stations,id'],
        ]);
        $stationId = $data['health_station_id'] ?? null;
        $vaccine = Vaccine::where('name', $data['vaccine'])->where('is_active', true)->firstOrFail();
        DB::transaction(function () use ($data, $vaccine, $stationId) {
            $stockQuery = VaccineInventory::where('vaccine_id', $vaccine->id);
            $stationId === null
                ? $stockQuery->whereNull('health_station_id')
                : $stockQuery->where('health_station_id', $stationId);
            $stock = $stockQuery->lockForUpdate()->first();

            if ($stock) {
                $stock->increment('quantity', $data['quantity']);
            } else {
                VaccineInventory::create([
                    'health_station_id' => $stationId,
                    'vaccine_id' => $vaccine->id,
                    'vaccine' => $vaccine->name,
                    'quantity' => $data['quantity'],
                    'reorder_level' => $data['reorder_level'],
                ]);
            }
        });

        return redirect()->route('inventory.index')->with('success', 'Vaccine stock added or replenished.');
    }

    public function adjustInventory(Request $request, VaccineInventory $stock)
    {
        abort_unless($request->user()->isCho(), 403);
        $data = $request->validate([
            'action' => ['required', Rule::in(['add'])],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);
        DB::transaction(function () use ($stock, $data) {
            $locked = VaccineInventory::whereKey($stock->id)->lockForUpdate()->firstOrFail();
            $locked->increment('quantity', $data['quantity']);
        });

        return redirect()->route('inventory.index')->with('success', 'Vaccine stock replenished.');
    }

    public function sms(Request $request)
    {
        $query = SmsNotification::with(['child.guardian', 'schedule'])->latest();
        $this->scopeSmsToUser($query);
        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status');
        $reminder = $request->query('reminder');
        if ($search !== '') {
            $query->whereHas('child', function ($children) use ($search) {
                $children->whereHas('guardian', fn ($guardians) => $guardians->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('contact_number', 'like', "%{$search}%"))
                    ->orWhereHas('vaccinationSchedules', fn ($schedules) => $schedules->where('vaccine', 'like', "%{$search}%"));
            });
        }
        if ($status) {
            $query->where('status', $status);
        }
        if ($reminder) {
            $query->where('reminder_days', $reminder);
        }
        $summaryQuery = clone $query;

        return view('operations.sms', [
            'messages' => $query->paginate(15)->withQueryString(),
            'search' => $search,
            'status' => $status,
            'reminder' => $reminder,
            'summary' => [
                'sent' => (clone $summaryQuery)->where('status', 'SENT')->count(),
                'pending' => (clone $summaryQuery)->where('status', 'PENDING')->count(),
                'failed' => (clone $summaryQuery)->where('status', 'FAILED')->count(),
            ],
        ]);
    }

    public function updateSmsStatus(Request $request, SmsNotification $notification)
    {
        $this->ensureChildIsInScope($notification->child_id);
        $data = $request->validate(['status' => ['required', Rule::in(['SENT', 'PENDING', 'FAILED'])]]);
        $notification->update(['status' => $data['status'], 'sent_at' => $data['status'] === 'SENT' ? now() : null]);

        return redirect()->route('sms.index')->with('success', 'Reminder status updated.');
    }

    private function immunizationRules(?ImmunizationRecord $record = null): array
    {
        $uniqueDose = Rule::unique('immunization_records', 'dose_number')
            ->where(fn ($query) => $query->where('child_id', request('child_id'))->where('vaccine', request('vaccine')));
        if ($record) {
            $uniqueDose->ignore($record->id);
        }

        return [
            'child_id' => ['required', 'exists:children,id'],
            'vaccine' => ['required', Rule::in($this->vaccineNames(false, $record?->vaccine)->all())],
            'dose_number' => ['required', 'integer', 'min:1', 'max:10', $uniqueDose],
            'administered_at' => ['required', 'date', 'before_or_equal:today'],
        ];
    }

    private function childrenForCurrentUser()
    {
        $query = Child::query();
        if (auth()->user()->isBhw()) {
            $query->where('health_station_id', auth()->user()->health_station_id);
        }

        return $query;
    }

    private function ensureChildIsInScope(int $childId): void
    {
        abort_unless($this->childrenForCurrentUser()->whereKey($childId)->exists(), 403);
    }

    private function scopeRecordsToUser($query): void
    {
        if (auth()->user()->isBhw()) {
            $query->whereHas('child', fn ($children) => $children->where('health_station_id', auth()->user()->health_station_id));
        }
    }

    private function scopeSchedulesToUser($query): void
    {
        if (auth()->user()->isBhw()) {
            $query->whereHas('child', fn ($children) => $children->where('health_station_id', auth()->user()->health_station_id));
        }
    }

    private function scopeSmsToUser($query): void
    {
        if (auth()->user()->isBhw()) {
            $query->whereHas('child', fn ($children) => $children->where('health_station_id', auth()->user()->health_station_id));
        }
    }

    private function scopeInventoryToUser($query, $user): void
    {
        if ($user->isBhw()) {
            $query->where('health_station_id', $user->health_station_id ?: -1);
        } elseif (request()->query('station')) {
            $query->where('health_station_id', request()->query('station'));
        }
    }

    private function scheduleSummary(): array
    {
        $query = VaccinationSchedule::query();
        $this->scopeSchedulesToUser($query);
        $statuses = $query->with('vaccineDefinition')->get()->countBy(fn ($schedule) => $schedule->display_status);

        return [
            'pending' => $statuses->get('PENDING', 0),
            'due' => $statuses->get('DUE', 0),
            'overdue' => $statuses->get('OVERDUE', 0),
        ];
    }

    private function vaccineNames(bool $includeInactive = false, ?string $currentName = null)
    {
        $query = Vaccine::query()->orderBy('name');
        if (! $includeInactive) {
            $query->where('is_active', true);
        }
        $names = $query->pluck('name');

        if ($currentName && ! $names->contains($currentName)) {
            $names->push($currentName);
        }

        return $names;
    }
}
