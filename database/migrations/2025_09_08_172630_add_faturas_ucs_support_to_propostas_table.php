<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Expandir estrutura JSON de documentação para suportar faturas das UCs
     */
    public function up(): void
    {
        // A coluna documentacao já existe como JSON na tabela propostas
        // Esta migration apenas documenta a nova estrutura suportada
        
        echo "✅ Campo 'documentacao' já existe como JSON na tabela propostas\n";
        echo "✅ Nova estrutura suportada para faturas das UCs:\n";
        echo "{\n";
        echo "  \"faturas_ucs\": {\n";
        echo "    \"12345678\": \"2025_08_PROP-2025-001_12345678_faturaUC_1723456789.pdf\",\n";
        echo "    \"87654321\": \"2025_08_PROP-2025-001_87654321_faturaUC_1723456790.pdf\"\n";
        echo "  },\n";
        echo "  \"data_upload_faturas\": \"2025-08-15T10:30:00Z\",\n";
        echo "  \"documentoPessoal\": \"arquivo_cpf.pdf\",\n";
        echo "  \"contratoSocial\": \"arquivo_cnpj.pdf\"\n";
        echo "}\n";
        
        // Verificar se existem propostas para demonstrar a estrutura
        $countPropostas = DB::table('propostas')
            ->whereNull('deleted_at')
            ->count();
            
        echo "📊 Total de propostas no sistema: {$countPropostas}\n";
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Não há necessidade de reverter pois estamos apenas expandindo a estrutura JSON
        echo "⚠️ Não há reversão necessária - apenas expandimos a estrutura JSON existente\n";
    }
};