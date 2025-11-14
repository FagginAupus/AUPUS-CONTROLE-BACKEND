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
            $table->timestamp('data_em_andamento')->nullable()->after('data_assinatura');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('controle_clube', function (Blueprint $table) {
            $table->dropColumn('data_em_andamento');
        });
    }
};
