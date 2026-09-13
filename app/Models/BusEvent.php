<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }
}
