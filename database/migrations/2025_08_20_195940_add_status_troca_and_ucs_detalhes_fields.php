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
        // ✅ 1. ADICIONAR CAMPOS NA TABELA controle_clube
        Schema::table('controle_clube', function (Blueprint $table) {
            // Status da troca de titularidade
            $table->enum('status_troca', ['Aguardando', 'Em andamento', 'Finalizado'])
                  ->default('Aguardando')
                  ->after('ug_id');
            
            // Data da titularidade (padrão hoje, mas editável para datas passadas)
            $table->date('data_titularidade')
                  ->default(now()->toDateString())
                  ->after('status_troca');
            
            // Índice para otimizar consultas por status
            $table->index('status_troca');
        });

        // ✅ 2. ADICIONAR CAMPO JSON NA TABELA unidades_consumidoras (para UGs)
        Schema::table('unidades_consumidoras', function (Blueprint $table) {
            // JSON com detalhes das UCs atribuídas a esta UG
            // Formato: [{"uc_id": "uuid", "numero_uc": "123456", "apelido": "Casa", "media": 350.50, "media_calibrada": 385.55}]
            $table->json('ucs_atribuidas_detalhes')
                  ->default('[]')
                  ->after('media_consumo_atribuido');
        });

        // ✅ 3. POPULAR DADOS EXISTENTES COM VALORES PADRÃO
        DB::statement("
            UPDATE controle_clube 
            SET status_troca = 'Aguardando', 
                data_titularidade = CURRENT_DATE 
            WHERE status_troca IS NULL OR data_titularidade IS NULL
        ");

        DB::statement("
            UPDATE unidades_consumidoras 
            SET ucs_atribuidas_detalhes = '[]' 
            WHERE ucs_atribuidas_detalhes IS NULL
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // ✅ REVERTER ALTERAÇÕES
        Schema::table('controle_clube', function (Blueprint $table) {
            $table->dropIndex(['status_troca']); // Remover índice primeiro
            $table->dropColumn(['status_troca', 'data_titularidade']);
        });

        Schema::table('unidades_consumidoras', function (Blueprint $table) {
            $table->dropColumn('ucs_atribuidas_detalhes');
        });
    }
};