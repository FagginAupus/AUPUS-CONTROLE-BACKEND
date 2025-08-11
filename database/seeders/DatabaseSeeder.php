<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->command->info('🚀 Iniciando população do banco de dados...');
        
        // Chamar seeders na ordem correta
        $this->call([
            UsuarioSeeder::class,
            ConfiguracoesSeeder::class,
        ]);

        $this->command->info('✅ Banco de dados populado com dados de teste!');
        $this->command->info('');
        $this->command->info('🔑 Credenciais para teste:');
        $this->command->info('   Admin: admin@aupus.com / 123456');
        $this->command->info('   Consultor: consultor@aupus.com / 123456');
        $this->command->info('   Gerente: gerente@aupus.com / 123456');
        $this->command->info('   Vendedor: vendedor@aupus.com / 123456');
        $this->command->info('');
        $this->command->info('🌐 Agora você pode testar o login no frontend!');
    }
}