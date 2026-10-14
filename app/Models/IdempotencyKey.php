<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'customer_id',
        'key',
        'request_fingerprint',
        'appointment_id',
        'response_status',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    public function customer() { return $this->belongsTo(Customer::class); }
    public function appointment() { return $this->belongsTo(Appointment::class); }
}
