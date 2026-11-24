<?php

namespace App\Models;

// Simplified model for future use. Currently not needed for dashboard.
// When database persistence is needed, install illuminate/database

class BusEvent
{
    public static function updateOrCreate(array $attributes, array $values = []): self
    {
        // Todo: persist to database when illuminate/database is available
        return new self;
    }

    public static function create(array $attributes): self
    {
        // Todo: persist to database when illuminate/database is available
        return new self;
    }
}
