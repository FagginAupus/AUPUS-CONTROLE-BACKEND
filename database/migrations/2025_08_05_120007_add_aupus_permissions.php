<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Definir novas permissions para o sistema Aupus
        $newPermissions = [
            // Dashboard
            'dashboard.view' => 'Visualizar dashboard',
            
            // Propostas/Prospec
            'prospec.view' => 'Visualizar propostas',
            'prospec.create' => 'Criar propostas',
            'prospec.edit' => 'Editar propostas',
            'prospec.delete' => 'Excluir propostas',
            'prospec.export' => 'Exportar propostas',
            'prospec.change_status' => 'Alterar status das propostas',
            
            // Controle
            'controle.view' => 'Visualizar controle',
            'controle.manage' => 'Gerenciar controle',
            'controle.calibrar' => 'Aplicar calibragem',
            'controle.assign_ug' => 'Atribuir UGs',
            'controle.export' => 'Exportar controle',
            
            // UGs (via unidades_consumidoras)
            'ugs.view' => 'Visualizar UGs',
            'ugs.create' => 'Criar UGs',
            'ugs.edit' => 'Editar UGs',
            'ugs.delete' => 'Excluir UGs',
            'ugs.export' => 'Exportar UGs',
            
            // Configurações
            'configuracoes.view' => 'Visualizar configurações',
            'configuracoes.edit' => 'Editar configurações',
            'configuracoes.calibragem' => 'Gerenciar calibragem global',
            
            // Relatórios
            'relatorios.view' => 'Visualizar relatórios',
            'relatorios.export' => 'Exportar relatórios',
            'relatorios.advanced' => 'Relatórios avançados',
            
            // Equipe
            'equipe.view' => 'Visualizar equipe',
            'equipe.create' => 'Criar usuários',
            'equipe.edit' => 'Editar usuários',
            'equipe.manage_hierarchy' => 'Gerenciar hierarquia',
        ];

        // Criar as permissions
        foreach ($newPermissions as $name => $description) {
            Permission::create([
                'name' => $name,
                'guard_name' => 'api',
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }

        // Definir permissions por role
        $rolePermissions = [
            'admin' => array_keys($newPermissions), // Admin tem todas
            'consultor' => [
                'dashboard.view',
                'prospec.view', 'prospec.create', 'prospec.edit', 'prospec.export', 'prospec.change_status',
                'controle.view', 'controle.manage', 'controle.calibrar', 'controle.assign_ug', 'controle.export',
                'ugs.view', 'ugs.create', 'ugs.edit', 'ugs.export',
                'configuracoes.view',
                'relatorios.view', 'relatorios.export', 'relatorios.advanced',
                'equipe.view', 'equipe.create', 'equipe.edit'
            ],
            'gerente' => [
                'dashboard.view',
                'prospec.view', 'prospec.create', 'prospec.edit', 'prospec.export',
                'controle.view',
                'ugs.view',
                'relatorios.view', 'relatorios.export',
                'equipe.view', 'equipe.create'
            ],
            'vendedor' => [
                'dashboard.view',
                'prospec.view', 'prospec.create', 'prospec.edit',
                'controle.view',
                'ugs.view',
                'relatorios.view'
            ]
        ];

        // Criar roles se não existirem e atribuir permissions
        foreach ($rolePermissions as $roleName => $permissions) {
            $role = Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'api'
            ]);
            
            // Sincronizar permissions
            $role->syncPermissions($permissions);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remover permissions criadas
        $permissionsToDelete = [
            'dashboard.view', 'prospec.view', 'prospec.create', 'prospec.edit', 'prospec.delete', 
            'prospec.export', 'prospec.change_status', 'controle.view', 'controle.manage', 
            'controle.calibrar', 'controle.assign_ug', 'controle.export', 'ugs.view', 
            'ugs.create', 'ugs.edit', 'ugs.delete', 'ugs.export', 'configuracoes.view', 
            'configuracoes.edit', 'configuracoes.calibragem', 'relatorios.view', 
            'relatorios.export', 'relatorios.advanced', 'equipe.view', 'equipe.create', 
            'equipe.edit', 'equipe.manage_hierarchy'
        ];

        foreach ($permissionsToDelete as $permission) {
            Permission::where('name', $permission)->delete();
        }
    }
};