<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;

class Technician extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = ['dealership_id', 'name', 'active'];

    protected $casts = ['active' => 'boolean'];

    protected $keyType = 'string';

    public $incrementing = false;

    public function dealership()
    {
        return $this->belongsTo(Dealership::class);
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }
}
