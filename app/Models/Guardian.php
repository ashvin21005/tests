<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Guardian extends Model
{
    protected $fillable = ['first_name', 'last_name', 'relationship', 'contact_number', 'occupation'];

    public function children()
    {
        return $this->hasMany(Child::class);
    }

    public function immunizationRecords()
    {
        return $this->hasManyThrough(ImmunizationRecord::class, Child::class);
    }

    public function getFullNameAttribute(): string
    {
        return $this->first_name.' '.$this->last_name;
    }
}
