<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Remover colunas de calibragem da tabela controle_clube
        Schema::table('controle_clube', function (Blueprint $table) {
            $table->dropColumn(['calibragem', 'valor_calibrado']);
        });

        // 2. Adicionar configuração de calibragem global
        DB::table('configuracoes')->insert([
            'id' => (string) Str::ulid(),
            'chave' => 'calibragem_global',
            'valor' => '0.00',
            'tipo' => 'number',
            'descricao' => 'Percentual de calibragem global aplicado a todas as propostas do controle (%)',
            'grupo' => 'controle',
            'updated_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3. Adicionar outras configurações opcionais do controle
        DB::table('configuracoes')->insert([
            [
                'id' => (string) Str::ulid(),
                'chave' => 'calibragem_max_permitida',
                'valor' => '50.00',
                'tipo' => 'number',
                'descricao' => 'Valor máximo permitido para calibragem (%)',
                'grupo' => 'controle',
                'updated_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::ulid(),
                'chave' => 'controle_auto_refresh',
                'valor' => 'false',
                'tipo' => 'boolean',
                'descricao' => 'Se deve atualizar automaticamente os dados do controle',
                'grupo' => 'controle',
                'updated_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 1. Restaurar colunas na tabela controle_clube
        Schema::table('controle_clube', function (Blueprint $table) {
            $table->decimal('calibragem', 5, 2)->default(0.00)->after('ug_id');
            $table->decimal('valor_calibrado', 10, 2)->nullable()->after('calibragem');
        });

        // 2. Remover configurações adicionadas
        DB::table('configuracoes')->whereIn('chave', [
            'calibragem_global',
            'calibragem_max_permitida', 
            'controle_auto_refresh'
        ])->delete();
    }
};