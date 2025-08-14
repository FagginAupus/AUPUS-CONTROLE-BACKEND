<?php
// database/migrations/2025_08_14_151500_update_propostas_status_constraint.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ✅ ATUALIZAR STATUS PERMITIDOS E REMOVER CONSTRAINTS DESNECESSÁRIAS
     */
    public function up(): void
    {
        echo "🔄 Atualizando constraints da tabela propostas...\n";
        
        // ✅ 1. REMOVER TODAS AS CONSTRAINTS ANTIGAS
        try {
            DB::statement('ALTER TABLE propostas DROP CONSTRAINT IF EXISTS propostas_status_check');
            DB::statement('ALTER TABLE propostas DROP CONSTRAINT IF EXISTS chk_desconto_tarifa_format');
            DB::statement('ALTER TABLE propostas DROP CONSTRAINT IF EXISTS chk_desconto_bandeira_format');
            echo "✅ Constraints antigas removidas\n";
        } catch (\Exception $e) {
            echo "⚠️ Aviso ao remover constraints antigas: " . $e->getMessage() . "\n";
        }
        
        // ✅ 2. ADICIONAR NOVA CONSTRAINT DE STATUS (APENAS OS 4 STATUS SOLICITADOS)
        try {
            DB::statement("
                ALTER TABLE propostas 
                ADD CONSTRAINT propostas_status_check 
                CHECK (status IN ('Fechada', 'Aguardando', 'Recusada', 'Cancelada'))
            ");
            echo "✅ Nova constraint de status adicionada: Fechada, Aguardando, Recusada, Cancelada\n";
        } catch (\Exception $e) {
            echo "❌ Erro ao adicionar constraint de status: " . $e->getMessage() . "\n";
            throw $e;
        }
        
        // ✅ 3. ATUALIZAR STATUS EXISTENTES PARA OS NOVOS VALORES
        $statusMappings = [
            'Fechado' => 'Fechada',
            'Perdido' => 'Recusada',
            'Em Análise' => 'Aguardando', // Mapear "Em Análise" para "Aguardando"
            'Recusado' => 'Recusada'
        ];
        
        foreach ($statusMappings as $antigo => $novo) {
            try {
                $updated = DB::update("UPDATE propostas SET status = ? WHERE status = ?", [$novo, $antigo]);
                if ($updated > 0) {
                    echo "✅ Convertidos {$updated} registros de '{$antigo}' para '{$novo}'\n";
                }
            } catch (\Exception $e) {
                echo "⚠️ Erro ao converter status '{$antigo}': " . $e->getMessage() . "\n";
            }
        }
        
        // ✅ 4. VERIFICAR SE EXISTEM STATUS INVÁLIDOS RESTANTES
        $statusInvalidos = DB::select("
            SELECT DISTINCT status, COUNT(*) as count 
            FROM propostas 
            WHERE status NOT IN ('Fechada', 'Aguardando', 'Recusada', 'Cancelada')
            GROUP BY status
        ");
        
        if (!empty($statusInvalidos)) {
            echo "⚠️ Status inválidos encontrados:\n";
            foreach ($statusInvalidos as $status) {
                echo "   - {$status->status}: {$status->count} registros\n";
            }
            echo "⚠️ Estes registros precisam ser corrigidos manualmente\n";
        }
        
        echo "🎉 Migration de status concluída com sucesso!\n";
        echo "📋 Status permitidos agora: Fechada, Aguardando, Recusada, Cancelada\n";
        echo "🔓 Campos SEM restrições: recorrencia, desconto_tarifa, desconto_bandeira\n";
    }

    /**
     * ✅ REVERTER as alterações se necessário
     */
    public function down(): void
    {
        echo "🔄 Revertendo alterações de status...\n";
        
        // Remover constraint nova
        try {
            DB::statement('ALTER TABLE propostas DROP CONSTRAINT IF EXISTS propostas_status_check');
            echo "✅ Nova constraint de status removida\n";
        } catch (\Exception $e) {
            echo "⚠️ Erro ao remover constraint: " . $e->getMessage() . "\n";
        }
        
        // Reverter mapeamentos de status
        $statusReverseMappings = [
            'Fechada' => 'Fechado',
            'Recusada' => 'Perdido'
            // 'Aguardando' permanece igual
            // 'Cancelada' será perdida no rollback
        ];
        
        foreach ($statusReverseMappings as $novo => $antigo) {
            try {
                $updated = DB::update("UPDATE propostas SET status = ? WHERE status = ?", [$antigo, $novo]);
                if ($updated > 0) {
                    echo "✅ Revertidos {$updated} registros de '{$novo}' para '{$antigo}'\n";
                }
            } catch (\Exception $e) {
                echo "⚠️ Erro ao reverter status '{$novo}': " . $e->getMessage() . "\n";
            }
        }
        
        // Recriar constraint antiga (valores aproximados do sistema original)
        try {
            DB::statement("
                ALTER TABLE propostas 
                ADD CONSTRAINT propostas_status_check 
                CHECK (status IN ('Aguardando', 'Em Análise', 'Fechado', 'Perdido', 'Recusado'))
            ");
            echo "✅ Constraint antiga de status restaurada\n";
        } catch (\Exception $e) {
            echo "⚠️ Erro ao restaurar constraint antiga: " . $e->getMessage() . "\n";
        }
        
        echo "🎉 Rollback concluído!\n";
    }
};