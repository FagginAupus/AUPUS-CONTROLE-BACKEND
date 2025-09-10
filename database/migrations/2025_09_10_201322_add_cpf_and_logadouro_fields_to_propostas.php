<?php
/**
 * Migration: Adicionar campos para documentação CPF e Logadouro
 * Arquivo: database/migrations/2025_09_10_120000_add_cpf_and_logadouro_fields_to_propostas.php
 */

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
        // Verificar se a tabela propostas existe
        if (!Schema::hasTable('propostas')) {
            throw new \Exception('Tabela propostas não encontrada. Execute as migrations anteriores primeiro.');
        }

        Schema::table('propostas', function (Blueprint $table) {
            // ===== CAMPOS PARA DOCUMENTAÇÃO CPF =====
            // Como já existe tipoDocumento, cpf, nomeRepresentante, 
            // vamos adicionar apenas os campos que não existem

            // Verificar se o campo 'logadouro_uc' não existe antes de adicionar
            if (!Schema::hasColumn('propostas', 'logadouro_uc')) {
                $table->text('logadouro_uc')->nullable()->after('endereco_representante')
                      ->comment('Descrição detalhada do logadouro da UC');
            }

            // ===== ÍNDICES PARA PERFORMANCE =====
            // Adicionar índice no campo CPF se não existir
            if (Schema::hasColumn('propostas', 'cpf') && !$this->hasIndex('propostas', 'cpf')) {
                $table->index('cpf', 'idx_propostas_cpf');
            }

            // Adicionar índice no campo tipoDocumento se não existir
            if (Schema::hasColumn('propostas', 'tipoDocumento') && !$this->hasIndex('propostas', 'tipoDocumento')) {
                $table->index('tipoDocumento', 'idx_propostas_tipo_documento');
            }
        });

        // ===== LOG DA MIGRAÇÃO =====
        \Log::info('Migration add_cpf_and_logadouro_fields_to_propostas executada com sucesso', [
            'campos_adicionados' => [
                'logadouro_uc' => 'Descrição detalhada do logadouro da UC'
            ],
            'indices_adicionados' => [
                'idx_propostas_cpf' => 'Índice para campo CPF',
                'idx_propostas_tipo_documento' => 'Índice para tipoDocumento'
            ]
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('propostas', function (Blueprint $table) {
            // ===== REMOVER ÍNDICES =====
            if ($this->hasIndex('propostas', 'cpf')) {
                $table->dropIndex('idx_propostas_cpf');
            }

            if ($this->hasIndex('propostas', 'tipoDocumento')) {
                $table->dropIndex('idx_propostas_tipo_documento');
            }

            // ===== REMOVER CAMPOS =====
            if (Schema::hasColumn('propostas', 'logadouro_uc')) {
                $table->dropColumn('logadouro_uc');
            }
        });

        \Log::info('Migration add_cpf_and_logadouro_fields_to_propostas revertida com sucesso');
    }

    /**
     * Verificar se um índice existe na tabela
     */
    private function hasIndex(string $table, string $column): bool
    {
        $indexes = Schema::getConnection()->getDoctrineSchemaManager()
            ->listTableIndexes($table);
        
        $indexName = "idx_{$table}_{$column}";
        
        return isset($indexes[$indexName]);
    }
};