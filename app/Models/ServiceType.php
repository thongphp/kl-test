<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;

class ServiceType extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = ['name', 'duration', 'active'];

    protected $casts = ['duration' => 'integer', 'active' => 'boolean'];

    protected $keyType = 'string';

    public $incrementing = false;

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }
}
