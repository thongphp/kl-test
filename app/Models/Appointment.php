<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;

class Appointment extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'customer_id',
        'vehicle_id',
        'dealership_id',
        'service_type_id',
        'technician_id',
        'service_bay_id',
        'start_at',
        'end_at',
        'status',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    public function customer() { return $this->belongsTo(Customer::class); }
    public function vehicle() { return $this->belongsTo(Vehicle::class); }
    public function dealership() { return $this->belongsTo(Dealership::class); }
    public function serviceType() { return $this->belongsTo(ServiceType::class); }
    public function technician() { return $this->belongsTo(Technician::class); }
    public function serviceBay() { return $this->belongsTo(ServiceBay::class); }
}
