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
        Schema::table('auditoria', function (Blueprint $table) {
            // Adicionar campos específicos para eventos detalhados
            $table->string('evento_tipo', 50)->nullable()->after('sub_acao')
                ->comment('Tipo específico do evento (ex: NOTIFICACAO_LIDA, TERMO_ENVIADO)');

            $table->text('descricao_evento')->nullable()->after('evento_tipo')
                ->comment('Descrição legível do evento para humanos');

            $table->string('modulo', 30)->nullable()->after('descricao_evento')
                ->comment('Módulo do sistema (dashboard, propostas, controle, ugs, etc)');

            $table->jsonb('dados_contexto')->nullable()->after('metadados')
                ->comment('Dados de contexto específicos do evento');

            $table->boolean('evento_critico')->default(false)->after('dados_contexto')
                ->comment('Indica se é um evento crítico que requer atenção');

            // Adicionar índices para performance
            $table->index('evento_tipo');
            $table->index('modulo');
            $table->index(['usuario_id', 'data_acao']);
            $table->index(['entidade', 'evento_tipo']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('auditoria', function (Blueprint $table) {
            // Remover índices
            $table->dropIndex(['evento_tipo']);
            $table->dropIndex(['modulo']);
            $table->dropIndex(['usuario_id', 'data_acao']);
            $table->dropIndex(['entidade', 'evento_tipo']);

            // Remover campos
            $table->dropColumn([
                'evento_tipo',
                'descricao_evento',
                'modulo',
                'dados_contexto',
                'evento_critico'
            ]);
        });
    }
};
