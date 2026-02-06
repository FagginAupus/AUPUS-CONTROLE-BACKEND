<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Tabela de resumo mensal
        DB::statement("
            CREATE TABLE historico_mensal_resumo (
                id VARCHAR(36) PRIMARY KEY,
                ano_mes VARCHAR(7) NOT NULL,
                total_associados INTEGER NOT NULL DEFAULT 0,
                novos_no_mes INTEGER NOT NULL DEFAULT 0,
                saidas_no_mes INTEGER NOT NULL DEFAULT 0,
                total_esteira INTEGER NOT NULL DEFAULT 0,
                total_em_andamento INTEGER NOT NULL DEFAULT 0,
                total_associado INTEGER NOT NULL DEFAULT 0,
                total_saindo INTEGER NOT NULL DEFAULT 0,
                total_com_ug INTEGER NOT NULL DEFAULT 0,
                total_sem_ug INTEGER NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT unique_ano_mes UNIQUE (ano_mes)
            )
        ");

        // Tabela de snapshots individuais por mês
        DB::statement("
            CREATE TABLE historico_mensal_associados (
                id VARCHAR(36) PRIMARY KEY,
                ano_mes VARCHAR(7) NOT NULL,
                resumo_id VARCHAR(36) NOT NULL REFERENCES historico_mensal_resumo(id) ON DELETE CASCADE,
                controle_id VARCHAR(36) NOT NULL,
                proposta_id VARCHAR(36),
                uc_id VARCHAR(36),
                ug_id VARCHAR(36),
                nome_cliente VARCHAR(200),
                numero_uc VARCHAR(50),
                numero_proposta VARCHAR(50),
                apelido_uc VARCHAR(100),
                status_troca VARCHAR(50) NOT NULL,
                ug_nome VARCHAR(200),
                consumo_medio NUMERIC(10,2),
                consumo_calibrado NUMERIC(10,2),
                calibragem NUMERIC(5,2),
                desconto_tarifa VARCHAR(10),
                desconto_bandeira VARCHAR(10),
                consultor VARCHAR(200),
                data_entrada_controle TIMESTAMP,
                data_assinatura TIMESTAMP,
                data_em_andamento TIMESTAMP,
                data_titularidade DATE,
                data_alocacao_ug TIMESTAMP,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_resumo FOREIGN KEY (resumo_id) REFERENCES historico_mensal_resumo(id) ON DELETE CASCADE
            )
        ");

        // Indexes
        DB::statement("CREATE INDEX idx_hma_ano_mes ON historico_mensal_associados(ano_mes)");
        DB::statement("CREATE INDEX idx_hma_resumo ON historico_mensal_associados(resumo_id)");
        DB::statement("CREATE INDEX idx_hma_controle ON historico_mensal_associados(controle_id)");
        DB::statement("CREATE INDEX idx_hmr_ano_mes ON historico_mensal_resumo(ano_mes)");
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS historico_mensal_associados");
        DB::statement("DROP TABLE IF EXISTS historico_mensal_resumo");
    }
};
