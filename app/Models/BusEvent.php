<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusEvent extends Model
{
    public $timestamps = false;

    protected $table = 'bus_events';

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
