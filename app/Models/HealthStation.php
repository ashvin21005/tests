<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HealthStation extends Model
{
    protected $fillable = ['name', 'barangay', 'address', 'status'];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function children()
    {
        return $this->hasMany(Child::class);
    }

    public function vaccineInventory()
    {
        return $this->hasMany(VaccineInventory::class);
    }
}
