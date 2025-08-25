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
            // ✅ NOVOS CAMPOS: Inflação e Tarifa com Tributos
            $table->decimal('inflacao', 5, 2)
                  ->default(2.00)
                  ->after('bandeira')
                  ->comment('Percentual de inflação anual');
            
            $table->decimal('tarifa_tributos', 8, 4)
                  ->nullable()
                  ->after('inflacao')
                  ->comment('Valor da tarifa com tributos em R$/kWh');
            
            // ✅ ÍNDICES PARA CONSULTAS (se necessário)
            $table->index('inflacao');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('propostas', function (Blueprint $table) {
            // Remover índice primeiro
            $table->dropIndex(['propostas_inflacao_index']);
            
            // Remover colunas
            $table->dropColumn(['inflacao', 'tarifa_tributos']);
        });
    }
};