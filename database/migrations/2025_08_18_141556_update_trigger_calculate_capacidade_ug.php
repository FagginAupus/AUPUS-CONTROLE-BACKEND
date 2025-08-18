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
        // Atualizar a função do trigger para usar 'gerador' ao invés de 'is_ug'
        DB::statement("
            CREATE OR REPLACE FUNCTION calculate_capacidade_ug()
            RETURNS TRIGGER AS $$
            BEGIN
                -- Usar 'gerador' ao invés de 'is_ug'
                IF NEW.gerador = true AND NEW.potencia_cc IS NOT NULL AND NEW.fator_capacidade IS NOT NULL THEN
                    NEW.capacidade_calculada := 720 * NEW.potencia_cc * (NEW.fator_capacidade / 100);
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        ");

        // Verificar se o trigger existe e recriar se necessário
        $triggerExists = DB::select("
            SELECT 1 FROM information_schema.triggers 
            WHERE trigger_name = 'trigger_calculate_capacidade_ug' 
            AND event_object_table = 'unidades_consumidoras'
        ");

        if (empty($triggerExists)) {
            // Criar o trigger se não existir
            DB::statement("
                CREATE TRIGGER trigger_calculate_capacidade_ug
                BEFORE INSERT OR UPDATE ON unidades_consumidoras
                FOR EACH ROW
                EXECUTE FUNCTION calculate_capacidade_ug();
            ");
        }

        // Atualizar registros existentes para recalcular capacidade das UGs
        DB::statement("
            UPDATE unidades_consumidoras 
            SET capacidade_calculada = 720 * potencia_cc * (fator_capacidade / 100)
            WHERE gerador = true 
            AND potencia_cc IS NOT NULL 
            AND fator_capacidade IS NOT NULL
            AND (capacidade_calculada IS NULL OR capacidade_calculada = 0);
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restaurar a função original usando 'is_ug' (caso seja necessário reverter)
        DB::statement("
            CREATE OR REPLACE FUNCTION calculate_capacidade_ug()
            RETURNS TRIGGER AS $$
            BEGIN
                -- Reverter para usar 'is_ug' (se o campo ainda existir)
                IF (NEW.gerador = true OR (TG_TABLE_NAME = 'unidades_consumidoras' AND OLD.is_ug IS NOT NULL AND NEW.is_ug = true)) 
                   AND NEW.potencia_cc IS NOT NULL AND NEW.fator_capacidade IS NOT NULL THEN
                    NEW.capacidade_calculada := 720 * NEW.potencia_cc * (NEW.fator_capacidade / 100);
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        ");
    }
};