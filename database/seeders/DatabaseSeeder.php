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
        // Chamar seeders na ordem correta
        $this->call([
            UsuarioSeeder::class,
            ConfiguracoesSeeder::class, // Usando o nome do seu seeder existente
        ]);

        $this->command->info('✅ Banco de dados populado com dados de teste!');
    }
}