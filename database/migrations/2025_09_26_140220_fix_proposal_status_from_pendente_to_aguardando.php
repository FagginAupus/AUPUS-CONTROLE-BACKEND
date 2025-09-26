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
        // Atualizar status "Pendente" para "Aguardando" no JSON unidades_consumidoras
        DB::statement("
            UPDATE propostas
            SET unidades_consumidoras = replace(unidades_consumidoras::text, '\"status\":\"Pendente\"', '\"status\":\"Aguardando\"')::jsonb
            WHERE unidades_consumidoras::text LIKE '%\"status\":\"Pendente\"%'
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reverter status "Aguardando" para "Pendente" no JSON unidades_consumidoras
        DB::statement("
            UPDATE propostas
            SET unidades_consumidoras = replace(unidades_consumidoras::text, '\"status\":\"Aguardando\"', '\"status\":\"Pendente\"')::jsonb
            WHERE unidades_consumidoras::text LIKE '%\"status\":\"Aguardando\"%'
        ");
    }
};
