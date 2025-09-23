<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop the existing role constraint
        DB::statement('ALTER TABLE usuarios DROP CONSTRAINT IF EXISTS usuarios_role_check');

        // Add the new constraint including 'analista'
        DB::statement("
            ALTER TABLE usuarios
            ADD CONSTRAINT usuarios_role_check
            CHECK (role IN ('admin', 'analista', 'consultor', 'gerente', 'vendedor'))
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop the current constraint
        DB::statement('ALTER TABLE usuarios DROP CONSTRAINT IF EXISTS usuarios_role_check');

        // Restore the original constraint without 'analista'
        DB::statement("
            ALTER TABLE usuarios
            ADD CONSTRAINT usuarios_role_check
            CHECK (role IN ('admin', 'consultor', 'gerente', 'vendedor'))
        ");
    }
};
