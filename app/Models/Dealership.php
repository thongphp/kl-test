<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;

class Dealership extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = ['name', 'timezone', 'active'];

    protected $casts = ['active' => 'boolean'];

    protected $keyType = 'string';

    public $incrementing = false;

    public function technicians()
    {
        return $this->hasMany(Technician::class);
    }

    public function serviceBays()
    {
        return $this->hasMany(ServiceBay::class);
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }
}
