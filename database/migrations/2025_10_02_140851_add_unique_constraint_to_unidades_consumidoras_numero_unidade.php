<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Esta migration resolve o problema de UCs duplicadas:
     * 1. Remove duplicatas mantendo apenas o registro mais recente
     * 2. Atualiza referências em controle_clube
     * 3. Adiciona constraint UNIQUE para prevenir futuras duplicações
     */
    public function up(): void
    {
        // PASSO 1: Identificar e resolver duplicatas
        DB::statement("
            -- Criar tabela temporária com UCs duplicadas
            CREATE TEMP TABLE ucs_duplicadas AS
            SELECT
                numero_unidade,
                array_agg(id ORDER BY created_at DESC) as ids,
                (array_agg(id ORDER BY created_at DESC))[1] as id_manter
            FROM unidades_consumidoras
            WHERE deleted_at IS NULL
            GROUP BY numero_unidade
            HAVING COUNT(*) > 1;
        ");

        // PASSO 2: Atualizar controle_clube para usar a UC mais recente
        DB::statement("
            -- Para cada UC duplicada, atualizar controle_clube
            UPDATE controle_clube cc
            SET uc_id = ud.id_manter
            FROM ucs_duplicadas ud,
                 unnest(ud.ids) WITH ORDINALITY AS id_antigo(id, ord)
            WHERE cc.uc_id = id_antigo.id
              AND id_antigo.id != ud.id_manter
              AND cc.deleted_at IS NULL;
        ");

        // PASSO 3: Soft delete das UCs duplicadas (mantém apenas a mais recente)
        DB::statement("
            UPDATE unidades_consumidoras uc
            SET deleted_at = NOW()
            FROM ucs_duplicadas ud,
                 unnest(ud.ids) WITH ORDINALITY AS id_duplicado(id, ord)
            WHERE uc.id = id_duplicado.id
              AND id_duplicado.id != ud.id_manter
              AND uc.deleted_at IS NULL;
        ");

        // PASSO 4: Adicionar constraint UNIQUE para prevenir futuras duplicações
        Schema::table('unidades_consumidoras', function (Blueprint $table) {
            // Cria índice único parcial (apenas registros não deletados)
            DB::statement('
                CREATE UNIQUE INDEX unidades_consumidoras_numero_unidade_unique
                ON unidades_consumidoras (numero_unidade)
                WHERE deleted_at IS NULL
            ');
        });

        // Log das ações realizadas
        $duplicatasResolvidas = DB::select("SELECT COUNT(*) as total FROM ucs_duplicadas")[0]->total ?? 0;

        \Log::info('Migration: UCs duplicadas resolvidas', [
            'total_duplicatas' => $duplicatasResolvidas,
            'timestamp' => now()
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remover constraint UNIQUE
        Schema::table('unidades_consumidoras', function (Blueprint $table) {
            DB::statement('DROP INDEX IF EXISTS unidades_consumidoras_numero_unidade_unique');
        });

        // Nota: Não reverter os soft deletes pois isso poderia causar inconsistências
        \Log::warning('Migration rollback: Constraint removida, mas UCs deletadas não foram restauradas para evitar inconsistências');
    }
};
