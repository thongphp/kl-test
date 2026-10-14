<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;

class Vehicle extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = ['customer_id', 'name', 'brand', 'colors', 'pin', 'type', 'status'];

    protected $casts = [
        'colors' => 'array',
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }
}
