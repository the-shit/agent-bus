<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE bus_events ALTER COLUMN occurred_at TYPE timestamptz USING CASE WHEN occurred_at IS NULL THEN NULL ELSE occurred_at AT TIME ZONE 'UTC' END");
        DB::statement("ALTER TABLE bus_events ALTER COLUMN received_at TYPE timestamptz USING received_at AT TIME ZONE 'UTC'");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE bus_events ALTER COLUMN occurred_at TYPE timestamp(0) without time zone USING occurred_at AT TIME ZONE 'UTC'");
        DB::statement("ALTER TABLE bus_events ALTER COLUMN received_at TYPE timestamp(0) without time zone USING received_at AT TIME ZONE 'UTC'");
    }
};
