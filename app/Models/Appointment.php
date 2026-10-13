<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Appointment extends \Illuminate\Database\Eloquent\Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'customer_id', 'vehicle_id', 'service_bay_id', 'qualified_employee_id',
        'start_at', 'end_at', 'service_type',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
    ];

    public function customer() { return $this->belongsTo(Customer::class); }
    public function vehicle() { return $this->belongsTo(Vehicle::class); }
    public function serviceBay() { return $this->belongsTo(ServiceBay::class); }
    public function qualifiedEmployee() { return $this->belongsTo(QualifiedEmployee::class); }

    protected $keyType = 'string';

    public $incrementing = false;
}
