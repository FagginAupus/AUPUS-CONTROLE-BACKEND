<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('controle_clube', function (Blueprint $table) {
            $table->timestamp('data_assinatura')->nullable()->after('data_titularidade');
        });

        // ✅ PREENCHER DADOS HISTÓRICOS
        // Lógica: documents.proposta_id + documents.numero_uc -> unidades_consumidoras.numero_unidade
        // -> unidades_consumidoras.id -> controle_clube.uc_id
        DB::statement("
            UPDATE controle_clube cc
            SET data_assinatura = d.updated_at
            FROM documents d
            INNER JOIN unidades_consumidoras uc
                ON d.numero_uc::bigint = uc.numero_unidade
                AND d.proposta_id = uc.proposta_id
            WHERE cc.uc_id = uc.id
                AND d.status = 'signed'
                AND cc.data_assinatura IS NULL
        ");

        // Log para verificar quantos registros foram atualizados
        $count = DB::selectOne("
            SELECT COUNT(*) as total
            FROM controle_clube
            WHERE data_assinatura IS NOT NULL
        ");

        \Log::info('Migration: data_assinatura preenchida', [
            'registros_atualizados' => $count->total ?? 0
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('controle_clube', function (Blueprint $table) {
            $table->dropColumn('data_assinatura');
        });
    }
};
