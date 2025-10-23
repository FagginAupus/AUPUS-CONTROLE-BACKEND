<?php
/**
 * Script para enriquecer o campo documentacao com dados de endereço extraídos do CEP
 * Extrai CEP do campo enderecoUC e adiciona CEP_UC, Bairro_UC, Cidade_UC, Estado_UC
 */

require __DIR__.'/vendor/autoload.php';

use Illuminate\Support\Facades\DB;

// Carrega o ambiente Laravel
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

/**
 * Extrai o CEP do texto do endereço
 */
function extrairCEP($endereco) {
    // Padrão: CEP: 12345678 ou CEP: 12345-678 ou somente 12345678
    if (preg_match('/CEP:\s*(\d{5})-?(\d{3})/', $endereco, $matches)) {
        return $matches[1] . $matches[2];
    }

    // Tenta encontrar apenas números de 8 dígitos
    if (preg_match('/\b(\d{8})\b/', $endereco, $matches)) {
        return $matches[1];
    }

    return null;
}

/**
 * Extrai dados do endereço diretamente do texto (fallback)
 */
function extrairDadosDoTexto($endereco) {
    $dados = [
        'bairro' => null,
        'cidade' => null,
        'estado' => null
    ];

    // Lista de estados brasileiros
    $estados = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];

    // Tenta extrair o estado (duas letras maiúsculas)
    foreach ($estados as $estado) {
        if (preg_match('/\b' . $estado . '\b/', $endereco)) {
            $dados['estado'] = $estado;
            break;
        }
    }

    // Se encontrou estado, tenta pegar a cidade (palavra antes do estado)
    if ($dados['estado']) {
        if (preg_match('/([A-Z][A-Z\s]+)\s+' . $dados['estado'] . '\b/', $endereco, $matches)) {
            $cidade = trim($matches[1]);
            // Remove "CEP:" se aparecer
            $cidade = preg_replace('/CEP:\s*\d+/', '', $cidade);
            $cidade = trim($cidade);
            if (!empty($cidade) && strlen($cidade) > 2) {
                $dados['cidade'] = $cidade;
            }
        }
    }

    // Tenta extrair bairro (palavra depois de BAIRRO ou antes de CEP)
    if (preg_match('/(?:BAIRRO|SETOR)\s+([A-Z][A-Z\s]+?)(?:\s+CEP|\s+\d{5})/', $endereco, $matches)) {
        $bairro = trim($matches[1]);
        if (!empty($bairro) && strlen($bairro) > 2) {
            $dados['bairro'] = $bairro;
        }
    }

    return $dados;
}

/**
 * Consulta a API ViaCEP
 */
function consultarViaCEP($cep) {
    $url = "https://viacep.com.br/ws/{$cep}/json/";

    try {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);

            // ViaCEP retorna erro como {"erro": true}
            if (isset($data['erro']) && $data['erro'] === true) {
                return null;
            }

            return [
                'bairro' => $data['bairro'] ?? null,
                'cidade' => $data['localidade'] ?? null,
                'estado' => $data['uf'] ?? null
            ];
        }

        return null;
    } catch (Exception $e) {
        echo "Erro ao consultar ViaCEP: " . $e->getMessage() . "\n";
        return null;
    }
}

/**
 * Processa e enriquece o JSON de documentação
 */
function enriquecerDocumentacao($documentacao) {
    if (!$documentacao) {
        return null;
    }

    $modificado = false;

    foreach ($documentacao as $numeroUC => $dados) {
        // Verifica se já tem os campos novos
        if (isset($dados['CEP_UC']) || isset($dados['Bairro_UC']) || isset($dados['Cidade_UC']) || isset($dados['Estado_UC'])) {
            echo "  UC $numeroUC já possui campos de endereço\n";
            continue;
        }

        // Verifica se tem enderecoUC
        if (!isset($dados['enderecoUC']) || empty($dados['enderecoUC'])) {
            echo "  UC $numeroUC sem enderecoUC\n";
            continue;
        }

        $enderecoUC = $dados['enderecoUC'];
        echo "  UC $numeroUC: processando endereço...\n";

        // Extrai o CEP
        $cep = extrairCEP($enderecoUC);

        $dadosEndereco = [
            'CEP_UC' => $cep,
            'Bairro_UC' => null,
            'Cidade_UC' => null,
            'Estado_UC' => null
        ];

        // Se encontrou CEP, consulta ViaCEP
        if ($cep) {
            echo "    CEP encontrado: $cep\n";
            $dadosViaCEP = consultarViaCEP($cep);

            if ($dadosViaCEP) {
                $dadosEndereco['Bairro_UC'] = $dadosViaCEP['bairro'];
                $dadosEndereco['Cidade_UC'] = $dadosViaCEP['cidade'];
                $dadosEndereco['Estado_UC'] = $dadosViaCEP['estado'];
                echo "    ViaCEP: {$dadosViaCEP['cidade']}/{$dadosViaCEP['estado']} - {$dadosViaCEP['bairro']}\n";
            } else {
                echo "    ViaCEP não retornou dados\n";
            }
        } else {
            echo "    CEP não encontrado no texto\n";
        }

        // Se ainda não tem dados completos, tenta extrair do texto
        if (!$dadosEndereco['Estado_UC'] || !$dadosEndereco['Cidade_UC']) {
            echo "    Tentando extrair dados do texto...\n";
            $dadosTexto = extrairDadosDoTexto($enderecoUC);

            if (!$dadosEndereco['Bairro_UC'] && $dadosTexto['bairro']) {
                $dadosEndereco['Bairro_UC'] = $dadosTexto['bairro'];
            }
            if (!$dadosEndereco['Cidade_UC'] && $dadosTexto['cidade']) {
                $dadosEndereco['Cidade_UC'] = $dadosTexto['cidade'];
            }
            if (!$dadosEndereco['Estado_UC'] && $dadosTexto['estado']) {
                $dadosEndereco['Estado_UC'] = $dadosTexto['estado'];
            }

            if ($dadosTexto['cidade'] || $dadosTexto['estado']) {
                echo "    Texto: {$dadosTexto['cidade']}/{$dadosTexto['estado']} - {$dadosTexto['bairro']}\n";
            }
        }

        // Adiciona os novos campos ao JSON
        $documentacao[$numeroUC] = array_merge($dados, $dadosEndereco);
        $modificado = true;
    }

    return $modificado ? $documentacao : null;
}

// ==== EXECUÇÃO PRINCIPAL ====

echo "=== ENRIQUECIMENTO DE ENDEREÇOS UC ===\n\n";

// Modo de teste ou execução completa
$modoTeste = $argv[1] ?? 'teste';

if ($modoTeste === 'teste') {
    echo "MODO TESTE: Processando apenas 5 propostas\n\n";
    $propostas = DB::table('propostas')
        ->whereNotNull('documentacao')
        ->whereRaw("documentacao::text LIKE '%enderecoUC%'")
        ->limit(5)
        ->get();
} else {
    echo "MODO COMPLETO: Processando todas as propostas\n\n";
    $propostas = DB::table('propostas')
        ->whereNotNull('documentacao')
        ->whereRaw("documentacao::text LIKE '%enderecoUC%'")
        ->get();
}

$total = count($propostas);
$processadas = 0;
$modificadas = 0;
$erros = 0;

echo "Total de propostas a processar: $total\n\n";

foreach ($propostas as $proposta) {
    $processadas++;
    echo "[$processadas/$total] Proposta {$proposta->numero_proposta} (ID: {$proposta->id})\n";

    try {
        $documentacao = json_decode($proposta->documentacao, true);

        if (!$documentacao) {
            echo "  Erro ao decodificar JSON\n";
            $erros++;
            continue;
        }

        $documentacaoEnriquecida = enriquecerDocumentacao($documentacao);

        if ($documentacaoEnriquecida) {
            // Atualiza no banco
            DB::table('propostas')
                ->where('id', $proposta->id)
                ->update(['documentacao' => json_encode($documentacaoEnriquecida)]);

            echo "  ✓ Atualizado com sucesso\n";
            $modificadas++;
        } else {
            echo "  - Sem alterações necessárias\n";
        }

        // Pequeno delay para não sobrecarregar a API ViaCEP
        usleep(300000); // 300ms

    } catch (Exception $e) {
        echo "  Erro: " . $e->getMessage() . "\n";
        $erros++;
    }

    echo "\n";
}

echo "\n=== RESUMO ===\n";
echo "Total processadas: $processadas\n";
echo "Total modificadas: $modificadas\n";
echo "Total com erros: $erros\n";

if ($modoTeste === 'teste') {
    echo "\n>>> Para processar todas as propostas, execute: php enriquecer_endereco_uc.php completo\n";
}
