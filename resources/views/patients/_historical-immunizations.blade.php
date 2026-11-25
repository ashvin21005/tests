<section class="panel historical-immunizations" data-historical-immunizations data-mode="{{ $mode }}">
    <div class="panel-header">
        <div>
            <h2 class="panel-title">Previously administered vaccines</h2>
            <p class="page-subtitle">Optional. Add doses verified against the child's Baby Book. These entries do not deduct current station stock.</p>
        </div>
        @if ($mode === 'collection')
            <button type="button" class="btn btn-secondary" data-add-historical-dose>Add dose</button>
        @endif
    </div>
    <div class="historical-dose-list" data-historical-dose-list></div>
    <template data-historical-dose-template>
        <fieldset class="historical-dose" data-historical-dose>
            <legend>Historical dose</legend>
            <div class="record-form-grid">
                <label>Vaccine
                    <select data-field="vaccine_id" data-historical-vaccine required>
                        <option value="">Select vaccine</option>
                        @foreach ($historicalVaccines as $vaccine)
                            <option value="{{ $vaccine->id }}" data-dose-count="{{ count(config('immunization.epi.'.$vaccine->epi_code.'.doses', [])) }}">{{ $vaccine->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label>Dose number
                    <select data-field="dose_number" required>
                        <option value="">Select dose</option>
                        @foreach (range(1, 3) as $doseNumber)
                            <option value="{{ $doseNumber }}">Dose {{ $doseNumber }}</option>
                        @endforeach
                    </select>
                </label>
                <label>Date precision
                    <select data-field="date_precision" data-date-precision required>
                        <option value="DAY">Exact date known</option>
                        <option value="MONTH">Only month and year known</option>
                    </select>
                </label>
                <label data-exact-date>Administration date
                    <input type="date" data-field="administered_at" max="{{ today()->toDateString() }}">
                </label>
                <label data-month-date hidden>Administration month
                    <select data-field="administered_month">
                        <option value="">Select month</option>
                        @foreach (range(1, 12) as $month)
                            <option value="{{ $month }}">{{ \Illuminate\Support\Carbon::create()->month($month)->format('F') }}</option>
                        @endforeach
                    </select>
                </label>
                <label data-month-date hidden>Administration year
                    <input type="number" data-field="administered_year" min="1900" max="{{ today()->year }}" inputmode="numeric">
                </label>
            </div>
            <label class="historical-dose-confirm">
                <input type="checkbox" data-field="verified" value="1" required>
                I verified this dose in the child's Baby Book.
            </label>
            <button type="button" class="btn btn-sm btn-secondary" data-remove-historical-dose>Remove dose</button>
        </fieldset>
    </template>
</section>
<script src="{{ asset('js/historical-immunizations.js') }}" defer></script>
