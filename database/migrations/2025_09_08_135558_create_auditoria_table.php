<?php
// database/migrations/2025_09_08_130000_create_auditoria_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Criar tabela de auditoria genérica para rastrear mudanças em qualquer entidade
     */
    public function up(): void
    {
        echo "🔄 Criando tabela de auditoria genérica...\n";
        
        Schema::create('auditoria', function (Blueprint $table) {
            // Identificação única do log
            $table->string('id', 36)->primary();
            
            // Identificação da entidade auditada
            $table->string('entidade', 50)->index(); // 'controle_clube', 'propostas', 'usuarios', etc
            $table->string('entidade_id', 36)->index(); // ID do registro afetado
            $table->string('entidade_relacionada', 50)->nullable(); // Entidade relacionada (opcional)
            $table->string('entidade_relacionada_id', 36)->nullable(); // ID da entidade relacionada
            
            // Ação realizada
            $table->string('acao', 30)->index(); // 'CRIADO', 'REMOVIDO', 'REATIVADO', 'ALTERADO', 'EXCLUIDO'
            $table->string('sub_acao', 50)->nullable(); // Detalhamento da ação (opcional)
            
            // Dados da mudança
            $table->jsonb('dados_anteriores')->nullable(); // Estado anterior (JSON)
            $table->jsonb('dados_novos')->nullable(); // Estado novo (JSON)
            $table->jsonb('metadados')->nullable(); // Informações extras (JSON)
            
            // Contexto da operação
            $table->string('usuario_id', 36)->nullable()->index(); // Quem executou a ação
            $table->string('sessao_id', 100)->nullable(); // ID da sessão (opcional)
            $table->string('ip_address', 45)->nullable(); // IP do usuário
            $table->string('user_agent', 500)->nullable(); // Browser/App usado
            
            // Timestamps
            $table->timestamp('data_acao')->useCurrent()->index(); // Quando aconteceu
            $table->timestamp('created_at')->useCurrent();
            
            // Observações adicionais
            $table->text('observacoes')->nullable();
        });
        
        // Índices compostos para consultas eficientes
        DB::statement('CREATE INDEX idx_auditoria_entidade_data ON auditoria(entidade, data_acao DESC)');
        DB::statement('CREATE INDEX idx_auditoria_entidade_id_data ON auditoria(entidade, entidade_id, data_acao DESC)');
        DB::statement('CREATE INDEX idx_auditoria_usuario_data ON auditoria(usuario_id, data_acao DESC)');
        DB::statement('CREATE INDEX idx_auditoria_acao_data ON auditoria(acao, data_acao DESC)');
        
        echo "✅ Tabela 'auditoria' criada com sucesso!\n";
        echo "📊 Índices criados para performance otimizada\n";
        
        // Adicionar constraints de referência (opcional - comentado para flexibilidade)
        /*
        Schema::table('auditoria', function (Blueprint $table) {
            $table->foreign('usuario_id')->references('id')->on('usuarios')->onDelete('set null');
        });
        */
        
        echo "🎯 Tabela pronta para auditar qualquer entidade do sistema!\n";
    }

    /**
     * Reverter a migração
     */
    public function down(): void
    {
        echo "🔄 Removendo tabela de auditoria...\n";
        
        Schema::dropIfExists('auditoria');
        
        echo "✅ Tabela 'auditoria' removida\n";
    }
};