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
        echo "🔄 Adicionando campos de desconto na tabela controle_clube...\n";
        
        // ✅ 1. ADICIONAR os novos campos de desconto
        Schema::table('controle_clube', function (Blueprint $table) {
            $table->string('desconto_tarifa', 10)->default('20%')->after('calibragem');
            $table->string('desconto_bandeira', 10)->default('20%')->after('desconto_tarifa');
        });

        echo "✅ Campos desconto_tarifa e desconto_bandeira adicionados\n";

        // ✅ 2. POPULAR com valores das propostas correspondentes
        $registrosAtualizados = DB::update("
            UPDATE controle_clube cc
            SET 
                desconto_tarifa = p.desconto_tarifa,
                desconto_bandeira = p.desconto_bandeira
            FROM propostas p
            WHERE cc.proposta_id = p.id
            AND cc.deleted_at IS NULL
            AND p.deleted_at IS NULL
        ");

        echo "✅ Populados {$registrosAtualizados} registros com valores das propostas\n";

        // ✅ 3. ADICIONAR constraints para garantir formato de porcentagem
        try {
            DB::statement("
                ALTER TABLE controle_clube 
                ADD CONSTRAINT chk_controle_desconto_tarifa_format 
                CHECK (desconto_tarifa ~ '^[0-9]+(\\.[0-9]+)?%$')
            ");

            DB::statement("
                ALTER TABLE controle_clube 
                ADD CONSTRAINT chk_controle_desconto_bandeira_format 
                CHECK (desconto_bandeira ~ '^[0-9]+(\\.[0-9]+)?%$')
            ");

            echo "✅ Constraints de formato adicionadas\n";
        } catch (\Exception $e) {
            echo "⚠️ Aviso: Não foi possível adicionar constraints: " . $e->getMessage() . "\n";
        }

        echo "🎉 Migration concluída com sucesso!\n";
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        echo "🔄 Removendo campos de desconto da tabela controle_clube...\n";

        // Remover constraints
        try {
            DB::statement("ALTER TABLE controle_clube DROP CONSTRAINT IF EXISTS chk_controle_desconto_tarifa_format");
            DB::statement("ALTER TABLE controle_clube DROP CONSTRAINT IF EXISTS chk_controle_desconto_bandeira_format");
            echo "✅ Constraints removidas\n";
        } catch (\Exception $e) {
            echo "⚠️ Aviso ao remover constraints: " . $e->getMessage() . "\n";
        }

        // Remover campos
        Schema::table('controle_clube', function (Blueprint $table) {
            $table->dropColumn(['desconto_tarifa', 'desconto_bandeira']);
        });

        echo "✅ Campos removidos\n";
        echo "🎉 Rollback concluído!\n";
    }
};