<?php
// database/migrations/2025_09_04_151031_change_status_troca_aguardando_to_esteira_finalizado_to_associado.php
// VERSÃO CORRIGIDA para PostgreSQL

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Atualizar valores de status_troca: Aguardando→Esteira, Finalizado→Associado
     */
    public function up(): void
    {
        echo "🔄 Iniciando atualização dos status de troca...\n";
        
        try {
            // 1. VERIFICAR dados existentes antes da alteração
            $statusExistentes = DB::select("
                SELECT status_troca, COUNT(*) as count 
                FROM controle_clube 
                WHERE deleted_at IS NULL 
                GROUP BY status_troca
                ORDER BY count DESC
            ");
            
            echo "📊 Status atuais no banco:\n";
            foreach ($statusExistentes as $status) {
                echo "   - {$status->status_troca}: {$status->count} registros\n";
            }
            
            // 2. REMOVER constraint ANTES de alterar os dados (PostgreSQL)
            echo "\n🔧 Removendo constraint existente...\n";
            DB::statement("ALTER TABLE controle_clube DROP CONSTRAINT IF EXISTS controle_clube_status_troca_check");
            echo "✅ Constraint removida temporariamente\n";
            
            // 3. ATUALIZAR dados existentes (agora sem constraint bloqueando)
            echo "\n🔄 Atualizando dados existentes...\n";
            
            // Aguardando → Esteira
            $aguardandoCount = DB::update("
                UPDATE controle_clube 
                SET status_troca = 'Esteira' 
                WHERE status_troca = 'Aguardando' 
                AND deleted_at IS NULL
            ");
            echo "✅ Convertidos {$aguardandoCount} registros: 'Aguardando' → 'Esteira'\n";
            
            // Finalizado → Associado
            $finalizadoCount = DB::update("
                UPDATE controle_clube 
                SET status_troca = 'Associado' 
                WHERE status_troca = 'Finalizado' 
                AND deleted_at IS NULL
            ");
            echo "✅ Convertidos {$finalizadoCount} registros: 'Finalizado' → 'Associado'\n";
            
            // 4. APLICAR nova constraint (após dados estarem corretos)
            echo "\n🔧 Aplicando nova constraint...\n";
            DB::statement("
                ALTER TABLE controle_clube 
                ADD CONSTRAINT controle_clube_status_troca_check 
                CHECK (status_troca IN ('Esteira', 'Em andamento', 'Associado'))
            ");
            echo "✅ Nova constraint aplicada\n";
            
            // 5. ATUALIZAR valor padrão
            echo "\n🔧 Atualizando valor padrão...\n";
            DB::statement("ALTER TABLE controle_clube ALTER COLUMN status_troca SET DEFAULT 'Esteira'");
            echo "✅ Valor padrão alterado para 'Esteira'\n";
            
            // 6. VERIFICAR resultado final
            echo "\n📊 Verificando resultado final...\n";
            $statusFinais = DB::select("
                SELECT status_troca, COUNT(*) as count 
                FROM controle_clube 
                WHERE deleted_at IS NULL 
                GROUP BY status_troca
                ORDER BY count DESC
            ");
            
            foreach ($statusFinais as $status) {
                echo "   - {$status->status_troca}: {$status->count} registros\n";
            }
            
            // 7. VERIFICAR se há status inválidos restantes
            $statusInvalidos = DB::select("
                SELECT DISTINCT status_troca, COUNT(*) as count 
                FROM controle_clube 
                WHERE status_troca NOT IN ('Esteira', 'Em andamento', 'Associado')
                AND deleted_at IS NULL
                GROUP BY status_troca
            ");
            
            if (!empty($statusInvalidos)) {
                echo "\n⚠️ Status inválidos encontrados:\n";
                foreach ($statusInvalidos as $status) {
                    echo "   - {$status->status_troca}: {$status->count} registros\n";
                }
            }
            
            echo "\n🎉 Migração concluída com sucesso!\n";
            echo "📋 Novos status permitidos: Esteira, Em andamento, Associado\n";
            echo "🔄 Valor padrão: Esteira\n";
            echo "✅ Total de registros atualizados: " . ($aguardandoCount + $finalizadoCount) . "\n";
            
        } catch (\Exception $e) {
            echo "\n❌ Erro durante a migração: " . $e->getMessage() . "\n";
            echo "🔄 Tentando aplicar constraint de segurança...\n";
            
            // Em caso de erro, tentar aplicar constraint que aceita valores antigos e novos
            try {
                DB::statement("ALTER TABLE controle_clube DROP CONSTRAINT IF EXISTS controle_clube_status_troca_check");
                DB::statement("
                    ALTER TABLE controle_clube 
                    ADD CONSTRAINT controle_clube_status_troca_check 
                    CHECK (status_troca IN ('Aguardando', 'Em andamento', 'Finalizado', 'Esteira', 'Associado'))
                ");
                echo "✅ Constraint de compatibilidade aplicada\n";
            } catch (\Exception $e2) {
                echo "❌ Erro ao aplicar constraint de segurança: " . $e2->getMessage() . "\n";
            }
            
            throw $e; // Re-throw o erro original
        }
    }

    /**
     * Reverter as alterações se necessário
     */
    public function down(): void
    {
        echo "🔄 Revertendo alterações de status...\n";
        
        try {
            // 1. REMOVER constraint atual
            echo "🔧 Removendo constraint atual...\n";
            DB::statement("ALTER TABLE controle_clube DROP CONSTRAINT IF EXISTS controle_clube_status_troca_check");
            
            // 2. REVERTER dados
            echo "🔄 Revertendo dados existentes...\n";
            
            // Esteira → Aguardando
            $esteiraCount = DB::update("
                UPDATE controle_clube 
                SET status_troca = 'Aguardando' 
                WHERE status_troca = 'Esteira' 
                AND deleted_at IS NULL
            ");
            echo "✅ Revertidos {$esteiraCount} registros: 'Esteira' → 'Aguardando'\n";
            
            // Associado → Finalizado
            $associadoCount = DB::update("
                UPDATE controle_clube 
                SET status_troca = 'Finalizado' 
                WHERE status_troca = 'Associado' 
                AND deleted_at IS NULL
            ");
            echo "✅ Revertidos {$associadoCount} registros: 'Associado' → 'Finalizado'\n";
            
            // 3. APLICAR constraint original
            echo "\n🔧 Aplicando constraint original...\n";
            DB::statement("
                ALTER TABLE controle_clube 
                ADD CONSTRAINT controle_clube_status_troca_check 
                CHECK (status_troca IN ('Aguardando', 'Em andamento', 'Finalizado'))
            ");
            
            // 4. REVERTER valor padrão
            DB::statement("ALTER TABLE controle_clube ALTER COLUMN status_troca SET DEFAULT 'Aguardando'");
            
            echo "\n🎉 Rollback concluído com sucesso!\n";
            echo "📋 Status revertidos para: Aguardando, Em andamento, Finalizado\n";
            echo "🔄 Valor padrão revertido para: Aguardando\n";
            
        } catch (\Exception $e) {
            echo "\n❌ Erro durante rollback: " . $e->getMessage() . "\n";
            throw $e;
        }
    }
};