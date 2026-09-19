<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BusEvent extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'event_id',
        'correlation_id',
        'causation_id',
        'seq',
        'subject',
        'session_id',
        'agent_type',
        'model',
        'repo',
        'type',
        'payload',
        'occurred_at',
        'received_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'seq' => 'integer',
        'occurred_at' => 'datetime',
        'received_at' => 'datetime',
    ];
}
