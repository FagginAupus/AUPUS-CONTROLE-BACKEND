<?php
/**
 * Script para listar todas as UCs onde não foi possível preencher todos os campos de endereço
 */

require __DIR__.'/vendor/autoload.php';

use Illuminate\Support\Facades\DB;

// Carrega o ambiente Laravel
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== UCs COM CAMPOS DE ENDEREÇO INCOMPLETOS ===\n\n";

$propostas = DB::table('propostas')
    ->whereNotNull('documentacao')
    ->whereRaw("documentacao::text LIKE '%CEP_UC%'")
    ->get();

$totalUcsAnalisadas = 0;
$ucsIncompletas = [];

foreach ($propostas as $proposta) {
    $documentacao = json_decode($proposta->documentacao, true);

    if (!$documentacao) {
        continue;
    }

    foreach ($documentacao as $numeroUC => $dados) {
        // Pula campos que não são UCs
        if (in_array($numeroUC, ['faturas_ucs', 'data_upload_faturas', 'documentoPessoal', 'termoAdesao'])) {
            continue;
        }

        $totalUcsAnalisadas++;

        // Verifica se tem os campos novos
        if (!isset($dados['CEP_UC'])) {
            continue; // Não foi processada
        }

        // Verifica se algum campo está vazio ou null
        $camposVazios = [];

        if (empty($dados['CEP_UC'])) {
            $camposVazios[] = 'CEP_UC';
        }
        if (empty($dados['Bairro_UC']) || $dados['Bairro_UC'] === null) {
            $camposVazios[] = 'Bairro_UC';
        }
        if (empty($dados['Cidade_UC']) || $dados['Cidade_UC'] === null) {
            $camposVazios[] = 'Cidade_UC';
        }
        if (empty($dados['Estado_UC']) || $dados['Estado_UC'] === null) {
            $camposVazios[] = 'Estado_UC';
        }

        // Se tem pelo menos um campo vazio, adiciona à lista
        if (count($camposVazios) > 0) {
            $ucsIncompletas[] = [
                'proposta_id' => $proposta->id,
                'numero_proposta' => $proposta->numero_proposta,
                'numero_uc' => $numeroUC,
                'endereco_original' => $dados['enderecoUC'] ?? 'Sem endereço',
                'cep_uc' => $dados['CEP_UC'] ?? null,
                'bairro_uc' => $dados['Bairro_UC'] ?? null,
                'cidade_uc' => $dados['Cidade_UC'] ?? null,
                'estado_uc' => $dados['Estado_UC'] ?? null,
                'campos_vazios' => $camposVazios
            ];
        }
    }
}

// Exibe os resultados
echo "Total de UCs analisadas: $totalUcsAnalisadas\n";
echo "Total de UCs com campos incompletos: " . count($ucsIncompletas) . "\n\n";

if (count($ucsIncompletas) > 0) {
    echo "DETALHAMENTO:\n";
    echo str_repeat("=", 120) . "\n\n";

    foreach ($ucsIncompletas as $i => $uc) {
        $num = $i + 1;
        echo "[$num] Proposta: {$uc['numero_proposta']} | UC: {$uc['numero_uc']}\n";
        echo "    Campos vazios: " . implode(', ', $uc['campos_vazios']) . "\n";
        echo "    CEP_UC: " . ($uc['cep_uc'] ?: 'VAZIO') . "\n";
        echo "    Bairro_UC: " . ($uc['bairro_uc'] ?: 'VAZIO') . "\n";
        echo "    Cidade_UC: " . ($uc['cidade_uc'] ?: 'VAZIO') . "\n";
        echo "    Estado_UC: " . ($uc['estado_uc'] ?: 'VAZIO') . "\n";
        echo "    Endereço original: " . substr($uc['endereco_original'], 0, 100) . "...\n";
        echo "\n";
    }

    // Estatísticas por campo
    echo "\n" . str_repeat("=", 120) . "\n";
    echo "ESTATÍSTICAS POR CAMPO:\n\n";

    $estatisticas = [
        'CEP_UC' => 0,
        'Bairro_UC' => 0,
        'Cidade_UC' => 0,
        'Estado_UC' => 0
    ];

    foreach ($ucsIncompletas as $uc) {
        foreach ($uc['campos_vazios'] as $campo) {
            $estatisticas[$campo]++;
        }
    }

    foreach ($estatisticas as $campo => $count) {
        echo "$campo vazio: $count UCs\n";
    }

    // Agrupa por proposta
    echo "\n" . str_repeat("=", 120) . "\n";
    echo "PROPOSTAS COM UCs INCOMPLETAS:\n\n";

    $propostasCom = [];
    foreach ($ucsIncompletas as $uc) {
        $propostasCom[$uc['numero_proposta']][] = $uc['numero_uc'];
    }

    foreach ($propostasCom as $proposta => $ucs) {
        echo "Proposta $proposta: " . count($ucs) . " UC(s) incompleta(s) - UCs: " . implode(', ', $ucs) . "\n";
    }
}

echo "\n" . str_repeat("=", 120) . "\n";
echo "Finalizado!\n";
