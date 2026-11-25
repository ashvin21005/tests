@extends('layouts.app')
@section('title', 'Vaccination Schedule')
@section('content')
    <div class="page-header operations-heading"><div><p class="eyebrow">Follow-up care</p><h1 class="page-title">Vaccination Schedule</h1><p class="page-subtitle">EPI target dates are generated from each child's date of birth and verified dose history.</p></div></div>
    <section class="operations-summary">
        <div class="operations-metric"><span>Pending</span><strong>{{ $summary['pending'] }}</strong></div><div class="operations-metric"><span>Due</span><strong>{{ $summary['due'] }}</strong></div><div class="operations-metric"><span>Overdue</span><strong>{{ $summary['overdue'] }}</strong></div>
    </section>
    <section class="panel operations-panel">
        <div class="operations-toolbar"><div><h2 class="panel-title">Scheduled doses</h2><span class="operations-count">{{ $schedules->total() }} entries</span></div>
            <form method="GET" class="operations-filters">
                <input type="search" name="search" value="{{ $search }}" placeholder="Search child or vaccine" aria-label="Search schedule">
                <select name="status" aria-label="Filter by status"><option value="">All statuses</option>@foreach (['PENDING', 'DUE', 'OVERDUE', 'COMPLETED', 'INVALID'] as $option)<option value="{{ $option }}" @selected($status === $option)>{{ ucfirst(strtolower($option)) }}</option>@endforeach</select>
                <select name="vaccine" aria-label="Filter by vaccine"><option value="">All vaccines</option>@foreach ($vaccines as $option)<option value="{{ $option }}" @selected($vaccine === $option)>{{ $option }}</option>@endforeach</select>
                <input type="date" name="date" value="{{ request('date') }}" aria-label="Filter by date">
                <button class="btn btn-secondary" type="submit">Filter</button>
            </form>
        </div>
        @if ($schedules->count())
            <div class="table-scroll"><table class="data-table operations-table"><thead><tr><th>Child</th><th>Guardian</th><th>Vaccine</th><th>Dose</th><th>DOH due date</th><th>Target date</th><th>Status</th><th>Actions</th></tr></thead><tbody>
                @foreach ($schedules as $schedule)
                    @php($displayStatus = $schedule->display_status)
                    <tr>
                        <td><span class="vt-person-link"><span class="vt-table-avatar" aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($schedule->child->first_name, 0, 1).\Illuminate\Support\Str::substr($schedule->child->last_name, 0, 1)) }}</span>{{ $schedule->child->full_name }}</span></td>
                        <td>{{ $schedule->child->guardian->full_name }}</td>
                        <td>{{ $schedule->vaccine }}</td>
                        <td>Dose {{ $schedule->dose_number }}</td>
                        <td>{{ $schedule->due_date?->format('M d, Y') ?? $schedule->scheduled_date->format('M d, Y') }}</td>
                        <td>{{ $schedule->scheduled_date->format('M d, Y') }}</td>
                        <td>
                            <span class="status-pill status-{{ in_array($displayStatus, ['OVERDUE', 'INVALID'], true) ? 'danger' : (in_array($displayStatus, ['DUE'], true) ? 'warning' : (in_array($displayStatus, ['COMPLETED', 'PENDING'], true) ? 'good' : 'neutral')) }}">{{ $displayStatus }}</span>
                            @if ($schedule->review_required)<small class="field-error">Dose history requires clinical review.</small>@endif
                        </td>
                        <td>
                            @if (auth()->user()->isBhw())
                                <div class="schedule-actions">
                            @if ($schedule->generated_by_protocol && $schedule->vaccine_id && ! in_array($displayStatus, ['COMPLETED', 'INVALID'], true))
                                <form method="POST" action="{{ route('api.v1.patients.immunizations.store', ['child' => $schedule->child_id]) }}"
                                    data-api-form data-api-resource="scheduled-immunization" data-api-method="POST"
                                    data-api-url="{{ route('api.v1.patients.immunizations.store', ['child' => '__child_id__']) }}"
                                    data-success-url="{{ route('schedule.index', request()->query()) }}"
                                    data-modal-success="record"
                                    data-success-title="Vaccination confirmed"
                                    data-success-message="The immunization was recorded and one dose was deducted from station inventory."
                                    data-confirm-message="Confirm that {{ $schedule->child->full_name }} received {{ $schedule->vaccine }} dose {{ $schedule->dose_number }} today? One dose will be deducted from station inventory.">
                                    @csrf
                                    <input type="hidden" name="child_id" value="{{ $schedule->child_id }}">
                                    <input type="hidden" name="vaccine_id" value="{{ $schedule->vaccine_id }}">
                                    <input type="hidden" name="dose_number" value="{{ $schedule->dose_number }}">
                                    <input type="hidden" name="administered_at" value="{{ today()->toDateString() }}">
                                    <button class="btn btn-sm btn-primary" type="button" data-confirmation-trigger>Confirm vaccinated</button>
                                </form>
                            @endif
                            <details class="schedule-date-edit">
                                        <summary>Change date</summary>
                                        <form method="POST" action="{{ route('schedule.update', $schedule) }}">
                                            @csrf @method('PATCH')
                                            <label>New date<input type="date" name="scheduled_date" value="{{ $schedule->scheduled_date->toDateString() }}" required></label>
                                            <button class="btn btn-sm btn-secondary" type="submit">Update date</button>
                                        </form>
                                    </details>
                                    <form method="POST" action="{{ route('schedule.destroy', $schedule) }}" data-confirm-message="Remove this scheduled dose? Pending reminders will also be removed; sent reminder history will be kept.">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-secondary schedule-remove-button" type="submit">Remove dose</button>
                                    </form>
                                </div>
                            @else
                                <span>—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody></table></div>
            <div class="table-footer"><span>Showing {{ $schedules->firstItem() }}–{{ $schedules->lastItem() }} of {{ $schedules->total() }}</span>{{ $schedules->links('pagination::bootstrap-4') }}</div>
        @else<div class="operations-empty"><strong>No schedule entries found</strong><span>Register a child or adjust your filters.</span></div>@endif
    </section>
@endsection