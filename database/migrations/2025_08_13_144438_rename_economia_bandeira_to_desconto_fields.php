<?php
// database/migrations/2025_08_13_144438_rename_economia_bandeira_to_desconto_fields.php
// ✅ MIGRATION CORRIGIDA SEM COMENTÁRIOS SQL PROBLEMÁTICOS

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * ✅ RENOMEAR E ALTERAR TIPOS DOS CAMPOS DE DESCONTO
     */
    public function up(): void
    {
        echo "🔄 Iniciando alteração dos campos de desconto...\n";
        
        // ✅ 1. RENOMEAR os campos
        Schema::table('propostas', function (Blueprint $table) {
            $table->renameColumn('economia', 'desconto_tarifa');
            $table->renameColumn('bandeira', 'desconto_bandeira');
        });

        echo "✅ Campos renomeados: economia → desconto_tarifa, bandeira → desconto_bandeira\n";

        // ✅ 2. ALTERAR o tipo para VARCHAR com formato de porcentagem
        Schema::table('propostas', function (Blueprint $table) {
            $table->string('desconto_tarifa', 10)->default('20%')->change();
            $table->string('desconto_bandeira', 10)->default('20%')->change();
        });

        echo "✅ Tipos alterados para VARCHAR(10)\n";

        // ✅ 3. CONVERTER dados existentes de número para porcentagem
        $updated = DB::update("
            UPDATE propostas 
            SET 
                desconto_tarifa = CONCAT(desconto_tarifa, '%'),
                desconto_bandeira = CONCAT(desconto_bandeira, '%')
            WHERE 
                desconto_tarifa NOT LIKE '%\%' 
                OR desconto_bandeira NOT LIKE '%\%'
        ");

        echo "✅ Convertidos {$updated} registros para formato de porcentagem\n";

        // ✅ 4. ADICIONAR constraints para garantir formato de porcentagem
        try {
            DB::statement("
                ALTER TABLE propostas 
                ADD CONSTRAINT chk_desconto_tarifa_format 
                CHECK (desconto_tarifa ~ '^[0-9]+(\\.[0-9]+)?%$')
            ");

            DB::statement("
                ALTER TABLE propostas 
                ADD CONSTRAINT chk_desconto_bandeira_format 
                CHECK (desconto_bandeira ~ '^[0-9]+(\\.[0-9]+)?%$')
            ");

            echo "✅ Constraints de formato adicionadas\n";
        } catch (\Exception $e) {
            echo "⚠️ Aviso: Não foi possível adicionar constraints: " . $e->getMessage() . "\n";
        }

        echo "🎉 Migration concluída com sucesso!\n";
    }

    /**
     * ✅ REVERTER as alterações se necessário
     */
    public function down(): void
    {
        echo "🔄 Revertendo alterações...\n";

        // Remover constraints
        try {
            DB::statement("ALTER TABLE propostas DROP CONSTRAINT IF EXISTS chk_desconto_tarifa_format");
            DB::statement("ALTER TABLE propostas DROP CONSTRAINT IF EXISTS chk_desconto_bandeira_format");
            echo "✅ Constraints removidas\n";
        } catch (\Exception $e) {
            echo "⚠️ Aviso ao remover constraints: " . $e->getMessage() . "\n";
        }

        // Converter de volta para números
        DB::statement("
            UPDATE propostas 
            SET 
                desconto_tarifa = REPLACE(desconto_tarifa, '%', ''),
                desconto_bandeira = REPLACE(desconto_bandeira, '%', '')
        ");

        echo "✅ Dados convertidos de volta para números\n";

        // Alterar tipo de volta para DECIMAL
        Schema::table('propostas', function (Blueprint $table) {
            $table->decimal('desconto_tarifa', 5, 2)->default(20.00)->change();
            $table->decimal('desconto_bandeira', 5, 2)->default(20.00)->change();
        });

        echo "✅ Tipos revertidos para DECIMAL\n";

        // Renomear de volta
        Schema::table('propostas', function (Blueprint $table) {
            $table->renameColumn('desconto_tarifa', 'economia');
            $table->renameColumn('desconto_bandeira', 'bandeira');
        });

        echo "✅ Nomes dos campos revertidos\n";
        echo "🎉 Rollback concluído!\n";
    }
};