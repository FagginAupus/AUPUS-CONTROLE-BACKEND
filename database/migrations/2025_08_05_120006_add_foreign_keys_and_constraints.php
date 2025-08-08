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
        // Adicionar foreign key da proposta para unidades_consumidoras
        Schema::table('unidades_consumidoras', function (Blueprint $table) {
            $table->foreign('proposta_id', 'fk_uc_proposta')
                  ->references('id')
                  ->on('propostas')
                  ->onDelete('set null');
        });
        
        // Adicionar constraints de validação
        DB::statement("
            ALTER TABLE unidades_consumidoras 
            ADD CONSTRAINT chk_ug_fields 
            CHECK (
                (is_ug = false) OR 
                (is_ug = true AND nome_usina IS NOT NULL AND potencia_cc IS NOT NULL AND fator_capacidade IS NOT NULL)
            )
        ");
        
        DB::statement("
            ALTER TABLE unidades_consumidoras 
            ADD CONSTRAINT chk_potencia_cc_positive 
            CHECK (potencia_cc IS NULL OR potencia_cc > 0)
        ");
        
        DB::statement("
            ALTER TABLE unidades_consumidoras 
            ADD CONSTRAINT chk_fator_capacidade_range 
            CHECK (fator_capacidade IS NULL OR (fator_capacidade >= 0 AND fator_capacidade <= 100))
        ");
        
        // Trigger para calcular capacidade automaticamente
        DB::statement("
            CREATE OR REPLACE FUNCTION calculate_capacidade_ug()
            RETURNS TRIGGER AS $$
            BEGIN
                IF NEW.is_ug = true AND NEW.potencia_cc IS NOT NULL AND NEW.fator_capacidade IS NOT NULL THEN
                    NEW.capacidade_calculada := 720 * NEW.potencia_cc * (NEW.fator_capacidade / 100);
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        ");
        
        DB::statement("
            CREATE TRIGGER trigger_calculate_capacidade_ug
            BEFORE INSERT OR UPDATE ON unidades_consumidoras
            FOR EACH ROW
            EXECUTE FUNCTION calculate_capacidade_ug();
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remover trigger e função
        DB::statement("DROP TRIGGER IF EXISTS trigger_calculate_capacidade_ug ON unidades_consumidoras;");
        DB::statement("DROP FUNCTION IF EXISTS calculate_capacidade_ug();");
        
        // Remover constraints
        DB::statement("ALTER TABLE unidades_consumidoras DROP CONSTRAINT IF EXISTS chk_ug_fields;");
        DB::statement("ALTER TABLE unidades_consumidoras DROP CONSTRAINT IF EXISTS chk_potencia_cc_positive;");
        DB::statement("ALTER TABLE unidades_consumidoras DROP CONSTRAINT IF EXISTS chk_fator_capacidade_range;");
        
        // Remover foreign key
        Schema::table('unidades_consumidoras', function (Blueprint $table) {
            $table->dropForeign('fk_uc_proposta');
        });
    }
};