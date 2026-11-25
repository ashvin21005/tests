<h2 class="form-section-title">Child Information</h2>
<div class="form-grid">
    <div class="field">
        <label for="first_name">First Name <span class="req">*</span></label>
        <input type="text" id="first_name" name="first_name" value="{{ old('first_name', $child->first_name) }}" class="@error('first_name') is-invalid @enderror">
        @error('first_name')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field">
        <label for="middle_name">Middle Name</label>
        <input type="text" id="middle_name" name="middle_name" value="{{ old('middle_name', $child->middle_name) }}" class="@error('middle_name') is-invalid @enderror">
        @error('middle_name')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field">
        <label for="last_name">Last Name <span class="req">*</span></label>
        <input type="text" id="last_name" name="last_name" value="{{ old('last_name', $child->last_name) }}" class="@error('last_name') is-invalid @enderror">
        @error('last_name')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field">
        <label for="sex">Sex <span class="req">*</span></label>
        <select id="sex" name="sex" class="@error('sex') is-invalid @enderror">
            <option value="">Select sex</option>
            @foreach (['Male', 'Female'] as $option)
                <option value="{{ $option }}" @selected(old('sex', $child->sex) === $option)>{{ $option }}</option>
            @endforeach
        </select>
        @error('sex')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field">
        <label for="date_of_birth">Date of Birth <span class="req">*</span></label>
        <input type="date" id="date_of_birth" name="date_of_birth" max="{{ date('Y-m-d') }}"
            value="{{ old('date_of_birth', $child->date_of_birth?->format('Y-m-d')) }}" class="@error('date_of_birth') is-invalid @enderror">
        @error('date_of_birth')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field">
        <label for="place_of_birth">Place of Birth</label>
        <input type="text" id="place_of_birth" name="place_of_birth" value="{{ old('place_of_birth', $child->place_of_birth) }}" class="@error('place_of_birth') is-invalid @enderror">
        @error('place_of_birth')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field">
        <label for="birth_weight">Birth Weight (kg)</label>
        <input type="number" step="0.01" min="0.5" max="9.99" id="birth_weight" name="birth_weight" value="{{ old('birth_weight', $child->birth_weight) }}" class="@error('birth_weight') is-invalid @enderror">
        @error('birth_weight')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field">
        <label for="barangay">Barangay <span class="req">*</span></label>
        <select id="barangay" name="barangay" class="@error('barangay') is-invalid @enderror" required>
            <option value="">Select a barangay</option>
            @if ($child->exists && $child->barangay && ! $barangays->contains('official_name', $child->barangay))
                <option value="{{ $child->barangay }}" @selected(old('barangay', $child->barangay) === $child->barangay)>{{ $child->barangay }} (existing record)</option>
            @endif
            @foreach ($barangays as $option)
                <option value="{{ $option->official_name }}" @selected(old('barangay', $child->barangay) === $option->official_name)>{{ $option->official_name }}@if ($option->name) - {{ $option->name }}@endif</option>
            @endforeach
        </select>
        @error('barangay')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    @if (auth()->user()->isCho())
        <div class="field">
            <label for="health_station_id">Health Station</label>
            <select id="health_station_id" name="health_station_id">
                <option value="">Not assigned</option>
                @foreach ($stations as $station)
                    <option value="{{ $station->id }}" @selected((string) old('health_station_id', $child->health_station_id) === (string) $station->id)>{{ $station->name }}</option>
                @endforeach
            </select>
        </div>
    @endif

    @if ($child->exists)
        <div class="field">
            <label for="status">Patient Status <span class="req">*</span></label>
            <select id="status" name="status" required>
                @foreach (['ACTIVE', 'INACTIVE'] as $option)
                    <option value="{{ $option }}" @selected(old('status', $child->status) === $option)>{{ ucfirst(strtolower($option)) }}</option>
                @endforeach
            </select>
            @error('status')<p class="field-error">{{ $message }}</p>@enderror
        </div>
    @endif

    <div class="field field-wide">
        <label for="address">Address (Purok / Street)</label>
        <input type="text" id="address" name="address" value="{{ old('address', $child->address) }}" class="@error('address') is-invalid @enderror">
        @error('address')<p class="field-error">{{ $message }}</p>@enderror
    </div>
</div>

<h2 class="form-section-title">Guardian Information</h2>
<div class="form-grid">
    <div class="field">
        <label for="guardian_first_name">First Name <span class="req">*</span></label>
        <input type="text" id="guardian_first_name" name="guardian_first_name" value="{{ old('guardian_first_name', $guardian->first_name) }}" class="@error('guardian_first_name') is-invalid @enderror">
        @error('guardian_first_name')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field">
        <label for="guardian_last_name">Last Name <span class="req">*</span></label>
        <input type="text" id="guardian_last_name" name="guardian_last_name" value="{{ old('guardian_last_name', $guardian->last_name) }}" class="@error('guardian_last_name') is-invalid @enderror">
        @error('guardian_last_name')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field">
        <label for="guardian_relationship">Relationship to Child <span class="req">*</span></label>
        <select id="guardian_relationship" name="guardian_relationship" class="@error('guardian_relationship') is-invalid @enderror">
            <option value="">Select relationship</option>
            @foreach (['Mother', 'Father', 'Grandparent', 'Legal Guardian', 'Other'] as $option)
                <option value="{{ $option }}" @selected(old('guardian_relationship', $guardian->relationship) === $option)>{{ $option }}</option>
            @endforeach
        </select>
        @error('guardian_relationship')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field">
        <label for="guardian_contact_number">Contact Number <span class="req">*</span></label>
        <input type="tel" id="guardian_contact_number" name="guardian_contact_number" placeholder="09XXXXXXXXX"
            value="{{ old('guardian_contact_number', $guardian->contact_number) }}" class="@error('guardian_contact_number') is-invalid @enderror">
        @error('guardian_contact_number')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field">
        <label for="guardian_occupation">Occupation</label>
        <input type="text" id="guardian_occupation" name="guardian_occupation" value="{{ old('guardian_occupation', $guardian->occupation) }}" class="@error('guardian_occupation') is-invalid @enderror">
        @error('guardian_occupation')<p class="field-error">{{ $message }}</p>@enderror
    </div>
</div>