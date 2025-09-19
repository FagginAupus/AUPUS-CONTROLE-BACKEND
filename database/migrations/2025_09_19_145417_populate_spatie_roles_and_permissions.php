<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\Usuario;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        try {
            // Limpar cache de permissões
            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

            // ==========================================
            // 1. CRIAR PERMISSIONS
            // ==========================================
            
            $permissions = [
                // Dashboard
                'dashboard.view',
                
                // Usuários
                'usuarios.view',
                'usuarios.create', 
                'usuarios.edit',
                'usuarios.delete',
                
                // Propostas
                'propostas.view',
                'propostas.create',
                'propostas.edit',
                'propostas.delete',
                'propostas.change_status',
                
                // Unidades Consumidoras
                'unidades.view',
                'unidades.create',
                'unidades.edit',
                'unidades.delete',
                'unidades.convert_ug',
                
                // Prospecção
                'prospec.view',
                'prospec.create',
                'prospec.edit',
                'prospec.delete',
                
                // Controle
                'controle.view',
                'controle.create',
                'controle.edit',
                'controle.calibragem',
                'controle.manage_ug',
                
                // Configurações
                'configuracoes.view',
                'configuracoes.edit',
                
                // Relatórios
                'relatorios.view',
                'relatorios.export',
                
                // Notificações
                'notificacoes.view',
                
                // UGs (Usinas Geradoras)
                'ugs.view',
                'ugs.create',
                'ugs.edit',
                'ugs.delete',
                
                // Equipe
                'equipe.view',
                'equipe.create'
            ];

            echo "📝 Criando " . count($permissions) . " permissões...\n";
            
            foreach ($permissions as $permission) {
                Permission::firstOrCreate([
                    'name' => $permission,
                    'guard_name' => 'api'
                ]);
            }

            // ==========================================
            // 2. CRIAR ROLES
            // ==========================================
            
            $roles = ['admin', 'consultor', 'gerente', 'vendedor'];
            
            echo "👥 Criando " . count($roles) . " roles...\n";
            
            $roleObjects = [];
            foreach ($roles as $roleName) {
                $roleObjects[$roleName] = Role::firstOrCreate([
                    'name' => $roleName,
                    'guard_name' => 'api'
                ]);
            }

            // ==========================================
            // 3. ASSOCIAR PERMISSIONS AOS ROLES
            // ==========================================
            
            echo "🔗 Associando permissões aos roles...\n";

            // ADMIN - Todas as permissões
            $roleObjects['admin']->syncPermissions($permissions);

            // CONSULTOR - Quase todas, exceto algumas administrativas
            $consultorPermissions = array_filter($permissions, function($perm) {
                return !in_array($perm, ['usuarios.delete', 'configuracoes.edit']);
            });
            $roleObjects['consultor']->syncPermissions($consultorPermissions);

            // GERENTE - Permissões intermediárias
            $gerentePermissions = [
                'dashboard.view',
                'usuarios.view', 'usuarios.create',
                'propostas.view', 'propostas.create', 'propostas.edit',
                'unidades.view', 'unidades.create', 'unidades.edit',
                'prospec.view', 'prospec.create', 'prospec.edit',
                'controle.view',
                'relatorios.view',
                'notificacoes.view'
            ];
            $roleObjects['gerente']->syncPermissions($gerentePermissions);

            // VENDEDOR - Permissões básicas
            $vendedorPermissions = [
                'dashboard.view',
                'usuarios.view',
                'propostas.view', 'propostas.create', 'propostas.edit',
                'unidades.view', 'unidades.create', 'unidades.edit',
                'prospec.view', 'prospec.create', 'prospec.edit',
                'controle.view',
                'relatorios.view',
                'notificacoes.view'
            ];
            $roleObjects['vendedor']->syncPermissions($vendedorPermissions);

            // ==========================================
            // 4. MIGRAR USUÁRIOS EXISTENTES
            // ==========================================
            
            echo "👤 Migrando usuários existentes...\n";
            
            $usuarios = Usuario::whereNotNull('role')->get();
            $migrated = 0;
            
            foreach ($usuarios as $usuario) {
                try {
                    // Verificar se role existe
                    if (isset($roleObjects[$usuario->role])) {
                        // Remover roles existentes (evita duplicatas)
                        $usuario->syncRoles([]);
                        
                        // Atribuir o role baseado no campo 'role'
                        $usuario->assignRole($usuario->role);
                        
                        $migrated++;
                        echo "  ✅ Usuário {$usuario->nome} ({$usuario->role}) migrado\n";
                    } else {
                        echo "  ⚠️ Role '{$usuario->role}' não encontrado para usuário {$usuario->nome}\n";
                    }
                } catch (\Exception $e) {
                    echo "  ❌ Erro ao migrar usuário {$usuario->nome}: {$e->getMessage()}\n";
                }
            }

            // ==========================================
            // 5. ESTATÍSTICAS
            // ==========================================
            
            echo "\n📊 MIGRAÇÃO CONCLUÍDA:\n";
            echo "  • Permissões criadas: " . count($permissions) . "\n";
            echo "  • Roles criados: " . count($roles) . "\n";
            echo "  • Usuários migrados: {$migrated}\n";
            echo "  • Total de usuários: " . $usuarios->count() . "\n";

            // Verificar dados
            echo "\n🔍 VERIFICAÇÃO:\n";
            foreach ($roles as $roleName) {
                $role = $roleObjects[$roleName];
                $permCount = $role->permissions()->count();
                $userCount = $role->users()->count();
                echo "  • {$roleName}: {$permCount} permissões, {$userCount} usuários\n";
            }

        } catch (\Exception $e) {
            echo "❌ ERRO NA MIGRAÇÃO: " . $e->getMessage() . "\n";
            echo "Stack trace: " . $e->getTraceAsString() . "\n";
            throw $e;
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        try {
            echo "⏪ Revertendo migração Spatie...\n";

            // Remover associações de usuários
            $usuarios = Usuario::whereHas('roles')->get();
            foreach ($usuarios as $usuario) {
                $usuario->syncRoles([]);
            }

            // Remover associações de roles com permissions
            $roles = Role::where('guard_name', 'api')->get();
            foreach ($roles as $role) {
                $role->syncPermissions([]);
            }

            // Deletar roles criados nesta migração
            Role::where('guard_name', 'api')
                ->whereIn('name', ['admin', 'consultor', 'gerente', 'vendedor'])
                ->delete();

            // Deletar permissions criadas nesta migração
            Permission::where('guard_name', 'api')->delete();

            // Limpar cache
            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

            echo "✅ Migração revertida com sucesso!\n";

        } catch (\Exception $e) {
            echo "❌ ERRO AO REVERTER: " . $e->getMessage() . "\n";
            throw $e;
        }
    }
};