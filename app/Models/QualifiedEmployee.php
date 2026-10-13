<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class QualifiedEmployee extends \Illuminate\Database\Eloquent\Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = ['name', 'ein'];

    protected $keyType = 'string';

    public $incrementing = false;
}
