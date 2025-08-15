<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ✅ ADICIONAR COLUNA DOCUMENTACAO JSON E REMOVER REFERÊNCIAS DE STATUS
     */
    public function up(): void
    {
        Schema::table('propostas', function (Blueprint $table) {
            // ✅ ADICIONAR coluna documentacao como JSON
            $table->json('documentacao')->nullable()->after('beneficios')
                  ->comment('Documentação da proposta: CPF/CNPJ, contratos, endereços, etc.');
        });

        // ✅ REMOVER coluna status se ainda existir
        if (Schema::hasColumn('propostas', 'status')) {
            Schema::table('propostas', function (Blueprint $table) {
                $table->dropColumn('status');
            });
        }

        echo "✅ Coluna 'documentacao' adicionada como JSON\n";
        echo "✅ Coluna 'status' removida (agora apenas dentro de unidades_consumidoras)\n";
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('propostas', function (Blueprint $table) {
            // Remover coluna documentacao
            $table->dropColumn('documentacao');
            
            // Recriar coluna status se necessário
            $table->string('status', 20)->default('Aguardando')->after('observacoes');
        });
    }
};