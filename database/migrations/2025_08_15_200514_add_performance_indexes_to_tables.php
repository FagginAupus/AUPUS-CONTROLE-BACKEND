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
        // ✅ REMOVER CONCURRENTLY para funcionar em transações Laravel
        
        // Índices para otimizar queries de propostas
        DB::statement('CREATE INDEX IF NOT EXISTS idx_propostas_usuario_created ON propostas (usuario_id, created_at DESC)');
        
        DB::statement('CREATE INDEX IF NOT EXISTS idx_propostas_consultor ON propostas (consultor)');
        
        DB::statement('CREATE INDEX IF NOT EXISTS idx_propostas_nome_cliente ON propostas (nome_cliente)');
        
        DB::statement('CREATE INDEX IF NOT EXISTS idx_propostas_numero ON propostas (numero_proposta)');
        
        // Índice GIN para busca em JSON (unidades_consumidoras)
        DB::statement('CREATE INDEX IF NOT EXISTS idx_propostas_unidades_gin ON propostas USING GIN (unidades_consumidoras)');
        
        // Índices para controle_clube
        DB::statement('CREATE INDEX IF NOT EXISTS idx_controle_proposta_created ON controle_clube (proposta_id, created_at DESC)');
        
        DB::statement('CREATE INDEX IF NOT EXISTS idx_controle_uc_ug ON controle_clube (uc_id, ug_id)');
        
        DB::statement('CREATE INDEX IF NOT EXISTS idx_controle_calibragem ON controle_clube (calibragem) WHERE calibragem IS NOT NULL');
        
        // Índices para unidades_consumidoras
        DB::statement('CREATE INDEX IF NOT EXISTS idx_uc_proposta_tipo ON unidades_consumidoras (proposta_id, is_ug)');
        
        DB::statement('CREATE INDEX IF NOT EXISTS idx_uc_numero_unidade ON unidades_consumidoras (numero_unidade)');
        
        DB::statement('CREATE INDEX IF NOT EXISTS idx_uc_apelido ON unidades_consumidoras (apelido) WHERE apelido IS NOT NULL');
        
        // Índices para usuarios
        DB::statement('CREATE INDEX IF NOT EXISTS idx_usuarios_role_active ON usuarios (role, is_active)');
        
        DB::statement('CREATE INDEX IF NOT EXISTS idx_usuarios_manager ON usuarios (manager_id) WHERE manager_id IS NOT NULL');
        
        // Atualizar estatísticas do banco
        DB::statement('ANALYZE propostas');
        DB::statement('ANALYZE controle_clube');
        DB::statement('ANALYZE unidades_consumidoras');
        DB::statement('ANALYZE usuarios');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remover índices criados
        DB::statement('DROP INDEX IF EXISTS idx_propostas_usuario_created');
        DB::statement('DROP INDEX IF EXISTS idx_propostas_consultor');
        DB::statement('DROP INDEX IF EXISTS idx_propostas_nome_cliente');
        DB::statement('DROP INDEX IF EXISTS idx_propostas_numero');
        DB::statement('DROP INDEX IF EXISTS idx_propostas_unidades_gin');
        DB::statement('DROP INDEX IF EXISTS idx_controle_proposta_created');
        DB::statement('DROP INDEX IF EXISTS idx_controle_uc_ug');
        DB::statement('DROP INDEX IF EXISTS idx_controle_calibragem');
        DB::statement('DROP INDEX IF EXISTS idx_uc_proposta_tipo');
        DB::statement('DROP INDEX IF EXISTS idx_uc_numero_unidade');
        DB::statement('DROP INDEX IF EXISTS idx_uc_apelido');
        DB::statement('DROP INDEX IF EXISTS idx_usuarios_role_active');
        DB::statement('DROP INDEX IF EXISTS idx_usuarios_manager');
    }
};