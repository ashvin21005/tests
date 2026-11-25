<?php

namespace App\Services;

use App\Models\Child;
use App\Models\ImmunizationRecord;
use App\Models\SmsNotification;
use App\Models\User;
use App\Models\VaccinationSchedule;
use App\Models\Vaccine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EpiScheduleService
{
    public function protocols(): array
    {
        return config('immunization.epi', []);
    }

    public function historicalVaccines()
    {
        return Vaccine::whereNotNull('epi_code')->where('is_active', true)->orderBy('name')->get();
    }

    public function historicalDoseRules(string $prefix = ''): array
    {
        return [
            $prefix.'vaccine_id' => [
                'required',
                'integer',
                Rule::exists('vaccines', 'id')->where(fn ($query) => $query->whereNotNull('epi_code')->where('is_active', true)),
            ],
            $prefix.'dose_number' => ['required', 'integer', 'min:1', 'max:3'],
            $prefix.'date_precision' => ['required', Rule::in(['DAY', 'MONTH'])],
            $prefix.'administered_at' => ['required_if:'.$prefix.'date_precision,DAY', 'nullable', 'date', 'before_or_equal:today'],
            $prefix.'administered_month' => ['required_if:'.$prefix.'date_precision,MONTH', 'nullable', 'integer', 'min:1', 'max:12'],
            $prefix.'administered_year' => ['required_if:'.$prefix.'date_precision,MONTH', 'nullable', 'integer', 'min:1900', 'max:'.today()->year],
            $prefix.'verified' => ['required', 'accepted'],
        ];
    }

    public function historicalImmunizationsRules(): array
    {
        return [
            'historical_immunizations' => ['sometimes', 'array', 'max:15'],
            'historical_immunizations.*' => ['required', 'array'],
            ...$this->historicalDoseRules('historical_immunizations.*.'),
        ];
    }

    public function createHistoricalDose(Child $child, array $data, User $user): ImmunizationRecord
    {
        $vaccine = Vaccine::whereKey($data['vaccine_id'])
            ->whereNotNull('epi_code')
            ->where('is_active', true)
            ->firstOrFail();
        $protocol = $this->protocolFor($vaccine);
        $doseNumber = (int) $data['dose_number'];

        if (! $protocol || ! isset($protocol['doses'][$doseNumber - 1])) {
            throw ValidationException::withMessages([
                'dose_number' => 'The selected vaccine does not have this dose in the EPI schedule.',
            ]);
        }

        if ($child->immunizationRecords()
            ->where('vaccine_id', $vaccine->id)
            ->where('dose_number', $doseNumber)
            ->exists()) {
            throw ValidationException::withMessages([
                'dose_number' => 'This vaccine dose is already recorded for this child.',
            ]);
        }

        $record = $child->immunizationRecords()->create([
            'vaccine_id' => $vaccine->id,
            'vaccine' => $vaccine->name,
            'dose_number' => $doseNumber,
            'administered_at' => $data['date_precision'] === 'DAY' ? $data['administered_at'] : null,
            'administered_month' => $data['date_precision'] === 'MONTH' ? $data['administered_month'] : null,
            'administered_year' => $data['date_precision'] === 'MONTH' ? $data['administered_year'] : null,
            'date_precision' => $data['date_precision'],
            'record_type' => 'HISTORICAL',
            'validation_status' => 'VALID',
            'verified_by' => $user->id,
            'verified_at' => now(),
            'recorded_by' => $user->id,
            'status' => 'COMPLETED',
        ]);

        $previousRecord = $doseNumber > 1
            ? $child->immunizationRecords()->where('vaccine_id', $vaccine->id)->where('dose_number', $doseNumber - 1)->first()
            : null;
        $validation = $this->validateAdministration($child, $vaccine, $doseNumber, $record, $previousRecord);
        $record->update([
            'validation_status' => $validation['status'],
            'status' => $validation['status'] === 'INVALID' ? 'INVALID' : 'COMPLETED',
        ]);

        return $record->fresh();
    }

    public function validationForLiveDose(
        Child $child,
        Vaccine $vaccine,
        int $doseNumber,
        string $administeredAt,
        ?ImmunizationRecord $ignore = null
    ): array {
        $record = new ImmunizationRecord([
            'date_precision' => 'DAY',
            'administered_at' => $administeredAt,
        ]);
        $previous = $doseNumber > 1
            ? $child->immunizationRecords()
                ->where('vaccine_id', $vaccine->id)
                ->where('dose_number', $doseNumber - 1)
                ->when($ignore, fn ($query) => $query->where('id', '!=', $ignore->id))
                ->first()
            : null;

        return $this->validateAdministration($child, $vaccine, $doseNumber, $record, $previous);
    }

    public function syncChild(Child $child): void
    {
        DB::transaction(function () use ($child) {
            $child->load('immunizationRecords');

            foreach ($this->protocols() as $code => $protocol) {
                $vaccine = Vaccine::where('epi_code', $code)->first();
                if (! $vaccine) {
                    continue;
                }

                $previousRecord = null;
                $previousTarget = null;
                $previousInvalid = false;
                $previousUnverifiable = false;

                foreach ($protocol['doses'] as $index => $minimumAgeDays) {
                    $doseNumber = $index + 1;
                    $record = $child->immunizationRecords
                        ->first(fn ($item) => $item->vaccine_id === $vaccine->id && (int) $item->dose_number === $doseNumber);
                    $ageDue = $child->date_of_birth->copy()->addDays($minimumAgeDays);
                    $interval = (int) ($protocol['interval_days'][$index - 1] ?? 0);

                    if ($record) {
                        $validation = $this->validateAdministration(
                            $child,
                            $vaccine,
                            $doseNumber,
                            $record,
                            $previousRecord
                        );
                        $record->update([
                            'validation_status' => $validation['status'],
                            'status' => $validation['status'] === 'INVALID' ? 'INVALID' : 'COMPLETED',
                        ]);
                        [, $rangeEnd] = $this->administrationRange($record);
                        $dueDate = $ageDue;
                        if ($previousRecord && $interval > 0) {
                            [, $previousEnd] = $this->administrationRange($previousRecord);
                            $dueDate = $this->laterDate($dueDate, $previousEnd->copy()->addDays($interval));
                        }
                        $schedule = $this->scheduleFor($child, $vaccine, $doseNumber);
                        $schedule->fill([
                            'child_id' => $child->id,
                            'vaccine' => $vaccine->name,
                            'scheduled_date' => $rangeEnd->toDateString(),
                            'due_date' => $dueDate->toDateString(),
                            'generated_by_protocol' => true,
                            'review_required' => $validation['status'] !== 'VALID',
                            'status' => $validation['status'] === 'INVALID' ? 'INVALID' : 'COMPLETED',
                        ])->save();
                        $this->removePendingReminders($schedule);
                        $previousInvalid = $validation['status'] === 'INVALID';
                        $previousUnverifiable = $validation['status'] === 'UNVERIFIABLE';
                        $previousRecord = $record;
                        $previousTarget = $rangeEnd;

                        continue;
                    }

                    $dueDate = $ageDue;
                    if ($interval > 0 && $previousRecord) {
                        [, $previousEnd] = $this->administrationRange($previousRecord);
                        $dueDate = $this->laterDate($dueDate, $previousEnd->copy()->addDays($interval));
                    } elseif ($interval > 0 && $previousTarget) {
                        $dueDate = $this->laterDate($dueDate, $previousTarget->copy()->addDays($interval));
                    }

                    $targetDate = $dueDate->lt(today()) ? today() : $dueDate->copy();
                    $schedule = $this->scheduleFor($child, $vaccine, $doseNumber);
                    $schedule->fill([
                        'child_id' => $child->id,
                        'vaccine' => $vaccine->name,
                        'scheduled_date' => $targetDate->toDateString(),
                        'due_date' => $dueDate->toDateString(),
                        'generated_by_protocol' => true,
                        'review_required' => $previousInvalid || $previousUnverifiable,
                        'status' => 'UPCOMING',
                    ])->save();
                    $this->syncReminders($schedule);
                    $previousTarget = $targetDate;
                    $previousRecord = null;
                }
            }
        });
    }

    public function validateAdministration(
        Child $child,
        Vaccine $vaccine,
        int $doseNumber,
        ImmunizationRecord $record,
        ?ImmunizationRecord $previousRecord
    ): array {
        $protocol = $this->protocolFor($vaccine);
        if (! $protocol) {
            return ['status' => 'VALID', 'note' => null];
        }
        if (! isset($protocol['doses'][$doseNumber - 1])) {
            return ['status' => 'INVALID', 'note' => 'Dose is not in the configured EPI protocol.'];
        }

        [$start, $end] = $this->administrationRange($record);
        $minimumAgeDate = $child->date_of_birth->copy()->addDays($protocol['doses'][$doseNumber - 1]);

        if ($end->lt($minimumAgeDate)) {
            return ['status' => 'INVALID', 'note' => 'Administration was before the minimum age.'];
        }

        if ($start->lt($minimumAgeDate)) {
            return ['status' => 'UNVERIFIABLE', 'note' => 'Month/year precision overlaps the minimum-age date.'];
        }

        if ($doseNumber === 1) {
            return ['status' => 'VALID', 'note' => null];
        }

        if (! $previousRecord) {
            return ['status' => 'UNVERIFIABLE', 'note' => 'The preceding dose is not recorded.'];
        }

        $minimumInterval = (int) ($protocol['interval_days'][$doseNumber - 2] ?? 0);
        if ($minimumInterval === 0) {
            return ['status' => 'VALID', 'note' => null];
        }

        [$previousStart, $previousEnd] = $this->administrationRange($previousRecord);
        $shortestPossibleInterval = $previousEnd->diffInDays($start, false);
        $longestPossibleInterval = $previousStart->diffInDays($end, false);

        if ($longestPossibleInterval < $minimumInterval) {
            return ['status' => 'INVALID', 'note' => 'The minimum interval between doses was not met.'];
        }

        if ($shortestPossibleInterval < $minimumInterval) {
            return ['status' => 'UNVERIFIABLE', 'note' => 'Month/year precision cannot verify the minimum interval.'];
        }

        return ['status' => 'VALID', 'note' => null];
    }

    private function protocolFor(Vaccine $vaccine): ?array
    {
        return $this->protocols()[$vaccine->epi_code] ?? null;
    }

    private function scheduleFor(Child $child, Vaccine $vaccine, int $doseNumber): VaccinationSchedule
    {
        return VaccinationSchedule::where('child_id', $child->id)
            ->where('vaccine_id', $vaccine->id)
            ->where('dose_number', $doseNumber)
            ->first()
            ?? VaccinationSchedule::where('child_id', $child->id)
                ->where('vaccine', $vaccine->name)
                ->where('dose_number', $doseNumber)
                ->first()
            ?? new VaccinationSchedule([
                'child_id' => $child->id,
                'vaccine_id' => $vaccine->id,
                'vaccine' => $vaccine->name,
                'dose_number' => $doseNumber,
            ]);
    }

    private function administrationRange(ImmunizationRecord $record): array
    {
        if ($record->date_precision === 'MONTH') {
            $start = Carbon::create((int) $record->administered_year, (int) $record->administered_month, 1)->startOfDay();

            return [$start, $start->copy()->endOfMonth()->startOfDay()];
        }

        $date = Carbon::parse($record->administered_at)->startOfDay();

        return [$date, $date->copy()];
    }

    private function laterDate(Carbon $left, Carbon $right): Carbon
    {
        return $left->gte($right) ? $left : $right;
    }

    private function syncReminders(VaccinationSchedule $schedule): void
    {
        if ($schedule->scheduled_date->lt(today())) {
            return;
        }

        foreach ([7, 3, 1] as $days) {
            SmsNotification::firstOrCreate([
                'vaccination_schedule_id' => $schedule->id,
                'reminder_days' => $days,
            ], [
                'child_id' => $schedule->child_id,
                'scheduled_date' => $schedule->scheduled_date,
                'status' => 'PENDING',
            ]);
        }

        SmsNotification::where('vaccination_schedule_id', $schedule->id)
            ->where('status', 'PENDING')
            ->update(['scheduled_date' => $schedule->scheduled_date]);
    }

    private function removePendingReminders(VaccinationSchedule $schedule): void
    {
        SmsNotification::where('vaccination_schedule_id', $schedule->id)
            ->where('status', 'PENDING')
            ->delete();
    }
}
