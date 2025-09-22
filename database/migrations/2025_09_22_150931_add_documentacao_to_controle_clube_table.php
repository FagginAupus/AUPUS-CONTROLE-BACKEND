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
            $table->string('documentacao_troca_titularidade')->nullable()->comment('Nome do arquivo da declaração de troca de titularidade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('controle_clube', function (Blueprint $table) {
            $table->dropColumn('documentacao_troca_titularidade');
        });
    }
};
