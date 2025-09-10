<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('controle_clube', function (Blueprint $table) {
            $table->decimal('calibragem_individual', 10, 2)->nullable()->after('ug_id')
                  ->comment('Calibragem individual da UC (sobrescreve calibragem global quando preenchida)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('controle_clube', function (Blueprint $table) {
            $table->dropColumn('calibragem_individual');
        });
    }
};