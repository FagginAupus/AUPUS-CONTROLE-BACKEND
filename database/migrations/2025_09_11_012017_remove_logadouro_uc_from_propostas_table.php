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
        Schema::table('propostas', function (Blueprint $table) {
            // Remover apenas a coluna logadouro_uc
            if (Schema::hasColumn('propostas', 'logadouro_uc')) {
                $table->dropColumn('logadouro_uc');
            }
        });
        
        \Log::info('Migration: coluna logadouro_uc removida da tabela propostas');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('propostas', function (Blueprint $table) {
            // Recriar a coluna se necessário (rollback)
            $table->text('logadouro_uc')->nullable()
                  ->comment('Descrição detalhada do logradouro da UC');
        });
        
        \Log::info('Migration: coluna logadouro_uc recriada na tabela propostas');
    }
};