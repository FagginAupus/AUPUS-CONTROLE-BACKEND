<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * ✅ OBJETIVO: Preencher campos desconto_tarifa e desconto_bandeira no controle_clube
     * com os valores da proposta correspondente, apenas para registros que ainda não têm esses valores.
     */
    public function up(): void
    {
        Log::info('🔄 Iniciando migration: Preencher descontos ausentes no controle_clube');

        // Contar registros antes da atualização
        $totalRegistros = DB::table('controle_clube')
            ->whereNull('deleted_at')
            ->count();

        $registrosSemDesconto = DB::table('controle_clube')
            ->whereNull('deleted_at')
            ->whereNull('desconto_tarifa')
            ->count();

        Log::info("📊 Estatísticas antes da migration:", [
            'total_registros' => $totalRegistros,
            'registros_sem_desconto' => $registrosSemDesconto,
            'registros_com_desconto' => $totalRegistros - $registrosSemDesconto
        ]);

        // ✅ ATUALIZAR registros sem desconto_tarifa ou desconto_bandeira
        // Preencher com os valores da proposta correspondente
        $registrosAtualizados = DB::update("
            UPDATE controle_clube cc
            SET
                desconto_tarifa = COALESCE(p.desconto_tarifa, '20%'),
                desconto_bandeira = COALESCE(p.desconto_bandeira, '20%'),
                updated_at = NOW()
            FROM propostas p
            WHERE cc.proposta_id = p.id
              AND cc.deleted_at IS NULL
              AND (cc.desconto_tarifa IS NULL OR cc.desconto_bandeira IS NULL)
        ");

        Log::info("✅ Migration concluída com sucesso!", [
            'registros_atualizados' => $registrosAtualizados
        ]);

        // Verificar resultado
        $registrosAindaSemDesconto = DB::table('controle_clube')
            ->whereNull('deleted_at')
            ->whereNull('desconto_tarifa')
            ->count();

        Log::info("📊 Estatísticas após a migration:", [
            'registros_ainda_sem_desconto' => $registrosAindaSemDesconto,
            'registros_agora_com_desconto' => $totalRegistros - $registrosAindaSemDesconto
        ]);

        // ✅ EXIBIR AMOSTRA dos registros atualizados
        $amostra = DB::table('controle_clube as cc')
            ->join('propostas as p', 'cc.proposta_id', '=', 'p.id')
            ->whereNull('cc.deleted_at')
            ->whereNotNull('cc.desconto_tarifa')
            ->select('p.numero_proposta', 'cc.desconto_tarifa', 'cc.desconto_bandeira')
            ->limit(5)
            ->get();

        Log::info("📋 Amostra de registros atualizados:", [
            'amostra' => $amostra->toArray()
        ]);
    }

    /**
     * Reverse the migrations.
     *
     * ⚠️ ATENÇÃO: Este rollback define os descontos como NULL novamente
     * Use com cuidado pois pode causar perda de dados se houver descontos customizados
     */
    public function down(): void
    {
        Log::warning('⚠️ Executando rollback: Limpando descontos do controle_clube');

        // ⚠️ Este rollback limpa TODOS os descontos, não apenas os que foram preenchidos pela migration
        // Se você quiser preservar descontos customizados, não execute este rollback

        $registrosLimpos = DB::update("
            UPDATE controle_clube
            SET
                desconto_tarifa = NULL,
                desconto_bandeira = NULL,
                updated_at = NOW()
            WHERE deleted_at IS NULL
              AND (desconto_tarifa IS NOT NULL OR desconto_bandeira IS NOT NULL)
        ");

        Log::warning("⚠️ Rollback concluído", [
            'registros_limpos' => $registrosLimpos
        ]);
    }
};
