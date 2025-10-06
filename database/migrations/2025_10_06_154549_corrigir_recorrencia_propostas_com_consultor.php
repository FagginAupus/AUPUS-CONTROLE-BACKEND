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
     * Corrige a recorrência das propostas que possuem consultor_id setado
     * mas estão com recorrência 0%. Esses casos devem ter recorrência 3%.
     *
     * Apenas propostas sem consultor devem ter recorrência 0%.
     */
    public function up(): void
    {
        // Caso 1: Atualiza propostas com consultor_id preenchido mas recorrência 0%
        $corrigidas_com_consultor = DB::table('propostas')
            ->whereNotNull('consultor_id')
            ->where('recorrencia', '0%')
            ->update(['recorrencia' => '3%']);

        // Caso 2: Atualiza propostas sem consultor_id mas com recorrência 3%
        $corrigidas_sem_consultor = DB::table('propostas')
            ->whereNull('consultor_id')
            ->where('recorrencia', '3%')
            ->update(['recorrencia' => '0%']);

        \Log::info("Migration corrigir_recorrencia_propostas_com_consultor executada", [
            'propostas_com_consultor_corrigidas' => $corrigidas_com_consultor,
            'propostas_sem_consultor_corrigidas' => $corrigidas_sem_consultor,
            'data' => now()
        ]);
    }

    /**
     * Reverse the migrations.
     *
     * Reverte a correção, voltando para 0% as propostas que foram alteradas.
     * OBS: Não é possível saber exatamente quais foram alteradas, então
     * esta reversão não é 100% precisa.
     */
    public function down(): void
    {
        // Não fazemos rollback pois não conseguimos distinguir
        // quais propostas tinham 0% antes e quais foram corrigidas
        \Log::warning("Rollback de corrigir_recorrencia_propostas_com_consultor não implementado", [
            'motivo' => 'Não é possível determinar quais registros foram corrigidos pela migration',
            'data' => now()
        ]);
    }
};
