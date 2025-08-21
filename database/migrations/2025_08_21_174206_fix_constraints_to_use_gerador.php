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
        // Remover constraint antiga que usa is_ug
        DB::statement("ALTER TABLE unidades_consumidoras DROP CONSTRAINT IF EXISTS chk_ug_fields");
        
        // Criar nova constraint usando gerador
        DB::statement("
            ALTER TABLE unidades_consumidoras 
            ADD CONSTRAINT chk_ug_fields_gerador 
            CHECK (
                (gerador = false) OR 
                (gerador = true AND nome_usina IS NOT NULL AND potencia_cc IS NOT NULL AND fator_capacidade IS NOT NULL)
            )
        ");
        
        // Atualizar trigger para garantir que está usando gerador
        DB::statement("
            CREATE OR REPLACE FUNCTION calculate_capacidade_ug()
            RETURNS TRIGGER AS $$
            BEGIN
                IF NEW.gerador = true AND NEW.potencia_cc IS NOT NULL AND NEW.fator_capacidade IS NOT NULL THEN
                    NEW.capacidade_calculada := 720 * NEW.potencia_cc * (NEW.fator_capacidade / 100);
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        ");
        
        // Executar a atualização das UGs existentes se necessário
        DB::statement("
            UPDATE unidades_consumidoras 
            SET gerador = true 
            WHERE nome_usina IS NOT NULL 
            AND potencia_cc IS NOT NULL 
            AND gerador = false
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE unidades_consumidoras DROP CONSTRAINT IF EXISTS chk_ug_fields_gerador");
        
        // Recriar constraint antiga (se necessário)
        DB::statement("
            ALTER TABLE unidades_consumidoras 
            ADD CONSTRAINT chk_ug_fields 
            CHECK (
                (gerador = false) OR 
                (gerador = true AND nome_usina IS NOT NULL AND potencia_cc IS NOT NULL AND fator_capacidade IS NOT NULL)
            )
        ");
    }
};