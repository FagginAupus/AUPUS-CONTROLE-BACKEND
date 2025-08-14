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
        Schema::table('propostas', function (Blueprint $table) {
            // Remover coluna status
            $table->dropColumn('status');
        });
        
        // Tentar remover índice apenas se existir (PostgreSQL)
        try {
            DB::statement('DROP INDEX IF EXISTS idx_propostas_status');
        } catch (\Exception $e) {
            // Índice não existe, ignorar erro
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('propostas', function (Blueprint $table) {
            $table->string('status', 20)->default('Aguardando');
            $table->index('status', 'idx_propostas_status');
        });
    }
};