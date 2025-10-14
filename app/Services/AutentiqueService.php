<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use App\Models\Document;

class AutentiqueService
{
    private $client;
    private $apiUrl;
    private $token;

    public function __construct()
    {
        $this->client = new Client([
            'timeout' => 60,
            'verify' => false
        ]);
        $this->apiUrl = config('autentique.api_url');
        $this->token = config('autentique.api_token');
        
        Log::info('AutentiqueService inicializado', [
            'api_url' => $this->apiUrl,
            'token_configured' => !empty($this->token),
            'token_length' => $this->token ? strlen($this->token) : 0,
            'config_check' => [
                'AUTENTIQUE_API_TOKEN' => config('autentique.api_token') ? 'FOUND' : 'NOT_FOUND',
                'AUTENTIQUE_API_URL' => config('autentique.api_url')
            ]
        ]);
    }

    /**
     * Verifica se o token está configurado antes de usar
     */
    private function ensureTokenConfigured()
    {
        if (!$this->token) {
            // ✅ CORREÇÃO: Tentar recarregar o token do env
            $this->token = env('AUTENTIQUE_API_TOKEN');

            if (!$this->token) {
                Log::error('Token da Autentique não configurado', [
                    'env_dump' => [
                        'AUTENTIQUE_API_TOKEN' => env('AUTENTIQUE_API_TOKEN'),
                        'all_autentique_vars' => array_filter($_ENV, function($key) {
                            return strpos($key, 'AUTENTIQUE') !== false;
                        }, ARRAY_FILTER_USE_KEY)
                    ]
                ]);
                throw new \Exception('Token da Autentique não configurado no .env (AUTENTIQUE_API_TOKEN)');
            }
        }
    }

    /**
     * Formata nome próprio com primeira letra de cada palavra em maiúscula
     * Mantém preposições e artigos em minúsculo (de, da, dos, das, do, e)
     */
    private function formatarNomeProprio(?string $nome): string
    {
        if (empty($nome)) {
            return '';
        }

        // Garantir UTF-8
        if (!mb_check_encoding($nome, 'UTF-8')) {
            $nome = mb_convert_encoding($nome, 'UTF-8', 'auto');
        }

        // Remover espaços extras
        $nome = trim(preg_replace('/\s+/', ' ', $nome));

        // Converter para minúsculo primeiro
        $nome = mb_strtolower($nome, 'UTF-8');

        // Lista de preposições e artigos que devem ficar em minúsculo
        $minusculas = ['de', 'da', 'do', 'dos', 'das', 'e', 'a', 'o', 'as', 'os'];

        // Separar palavras
        $palavras = explode(' ', $nome);

        // Processar cada palavra
        $palavrasFormatadas = [];
        foreach ($palavras as $index => $palavra) {
            // Primeira palavra sempre em maiúscula, ou se não for preposição/artigo
            if ($index === 0 || !in_array($palavra, $minusculas)) {
                $palavrasFormatadas[] = mb_convert_case($palavra, MB_CASE_TITLE, 'UTF-8');
            } else {
                $palavrasFormatadas[] = $palavra;
            }
        }

        return implode(' ', $palavrasFormatadas);
    }

    /**
     * Cria um documento na Autentique a partir dos dados da proposta
     */

    public function createDocumentFromProposta($propostaData, $signers, $pdfContent, $sandbox = true)
    {
        $this->ensureTokenConfigured();

        // Formatar nome do cliente
        $nomeCliente = $this->formatarNomeProprio($propostaData['nome_cliente'] ?? $propostaData['nomeCliente'] ?? '');

        $nomeDocumento = $propostaData['nome_documento'] ??
                     "Procuracao e Termo de Adesao - " . $nomeCliente .
                     " - UC " . ($propostaData['numeroUC'] ?? 'UC');
        // Preparar dados do documento
        $documentData = [
            'name' => $nomeDocumento,
            'refusable' => true,
            'sortable' => false,
            'message' => 'Documento para assinatura digital - Termo de Adesão AUPUS Energia'
        ];

        // ✅ OBTER OPÇÕES DE ENVIO DO USUÁRIO
        $enviarEmail = $propostaData['opcoes_envio']['enviar_email'] ?? true;
        $enviarWhatsApp = $propostaData['opcoes_envio']['enviar_whatsapp'] ?? false;

        Log::info('🎯 Processando opções de envio escolhidas pelo usuário', [
            'enviar_email' => $enviarEmail,
            'enviar_whatsapp' => $enviarWhatsApp,
            'signers_originais' => $signers
        ]);

        // ✅ PROCESSAR SIGNATÁRIOS RESPEITANDO AS ESCOLHAS DO USUÁRIO
        $processedSigners = [];
        
        foreach ($signers as $signer) {
            Log::info('📝 Processando signatário', [
                'signer_original' => $signer,
                'opcoes_usuario' => ['email' => $enviarEmail, 'whatsapp' => $enviarWhatsApp]
            ]);
            
            $hasEmail = isset($signer['email']) && !empty($signer['email']);
            $hasPhone = isset($signer['phone_number']) && !empty($signer['phone_number']);
            
            // ✅ LÓGICA CORRIGIDA: Respeitar exatamente o que o usuário escolheu
            if ($enviarEmail && $enviarWhatsApp) {
                // USUÁRIO QUER AMBOS - Criar signatário por email (principal)
                if ($hasEmail) {
                    $processedSigners[] = [
                        'email' => $signer['email'],
                        'action' => $signer['action'] ?? 'SIGN',
                        'name' => $this->formatarNomeProprio($signer['name'] ?? '')
                    ];
                    
                    Log::info('✅ Email + WhatsApp: Criado signatário por email', [
                        'email' => $signer['email'],
                        'note' => 'WhatsApp será enviado como notificação adicional'
                    ]);
                } else {
                    throw new \Exception('Email é obrigatório quando envio por email está ativado');
                }
                
            } elseif ($enviarEmail && !$enviarWhatsApp) {
                // USUÁRIO QUER APENAS EMAIL
                if ($hasEmail) {
                    $processedSigners[] = [
                        'email' => $signer['email'],
                        'action' => $signer['action'] ?? 'SIGN',
                        'name' => $this->formatarNomeProprio($signer['name'] ?? '')
                    ];
                    
                    Log::info('✅ Apenas Email: Criado signatário por email', [
                        'email' => $signer['email']
                    ]);
                } else {
                    throw new \Exception('Email é obrigatório quando apenas envio por email está ativado');
                }
                
            } elseif (!$enviarEmail && $enviarWhatsApp) {
                // ✅ USUÁRIO QUER APENAS WHATSAPP
                if ($hasPhone) {
                    $processedSigners[] = [
                        'phone' => $signer['phone_number'],
                        'delivery_method' => 'DELIVERY_METHOD_WHATSAPP',
                        'action' => $signer['action'] ?? 'SIGN'
                    ];
                    
                    Log::info('✅ Apenas WhatsApp: Criado signatário por telefone', [
                        'phone' => $signer['phone_number']
                    ]);
                } else {
                    throw new \Exception('Telefone é obrigatório quando apenas envio por WhatsApp está ativado');
                }
                
            } else {
                // NEM EMAIL NEM WHATSAPP - Erro
                throw new \Exception('Pelo menos uma opção de envio deve estar ativada (email ou WhatsApp)');
            }
        }
        
        Log::info('🔄 Signatários processados conforme escolha do usuário', [
            'opcoes_usuario' => ['email' => $enviarEmail, 'whatsapp' => $enviarWhatsApp],
            'original_count' => count($signers),
            'processed_count' => count($processedSigners),
            'processed_signers' => $processedSigners
        ]);

        // Resto do método continua igual (salvar PDF temporário e enviar)
        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        
        $tempPdfPath = $tempDir . '/termo_' . time() . '_' . uniqid() . '.pdf';
        
        Log::info('📄 Salvando PDF temporário para Autentique', [
            'temp_path' => $tempPdfPath,
            'content_length' => strlen($pdfContent),
            'is_pdf' => str_starts_with($pdfContent, '%PDF')
        ]);
        
        file_put_contents($tempPdfPath, $pdfContent);

        try {
            $result = $this->createSimpleDocument($documentData, $processedSigners, $tempPdfPath, $sandbox);
            
            if (file_exists($tempPdfPath)) {
                unlink($tempPdfPath);
            }
            
            return $result;
            
        } catch (\Exception $e) {
            if (file_exists($tempPdfPath)) {
                unlink($tempPdfPath);
            }
            throw $e;
        }
    }

    /**
     * Cria um documento simples baseado no exemplo oficial da Autentique
     */
    public function createSimpleDocument($documentData, $signers, $filePath, $sandbox = false)
    {
        if (!$this->token) {
            throw new \Exception('Token da Autentique não configurado no .env');
        }

        if (!file_exists($filePath)) {
            throw new \Exception('Arquivo PDF não encontrado: ' . $filePath);
        }

        // Query exata do exemplo oficial da documentação
        $query = 'mutation CreateDocumentMutation($document: DocumentInput!, $signers: [SignerInput!]!, $file: Upload!) {
            createDocument(document: $document, signers: $signers, file: $file' . ($sandbox ? ', sandbox: true' : '') . ') {
                id 
                name 
                refusable 
                sortable 
                created_at 
                signatures { 
                    public_id 
                    name 
                    email 
                    created_at 
                    action { name } 
                    link { short_link } 
                    user { id name email }
                }
            }
        }';

        $operations = json_encode([
            'query' => $query,
            'variables' => [
                'document' => $documentData,
                'signers' => $signers,
                'file' => null
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $map = json_encode(['file' => ['variables.file']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Configuração cURL com Content-Type correto para PDF
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $this->apiUrl,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => [
                'operations' => $operations,
                'map' => $map,
                'file' => new \CURLFile($filePath, 'application/pdf', 'document.pdf') // Nome fixo .pdf
            ],
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token,
                'Accept-Charset: utf-8'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_ENCODING => 'UTF-8'
        ]);

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($error) {
            Log::error('Erro cURL', ['error' => $error]);
            throw new \Exception('Erro de conexão: ' . $error);
        }

        Log::info('Resposta Autentique via cURL', [
            'http_code' => $httpCode,
            'response_size' => strlen($response),
            'response_preview' => substr($response, 0, 200)
        ]);

        $body = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('Erro ao decodificar JSON', [
                'error' => json_last_error_msg(),
                'response' => substr($response, 0, 500)
            ]);
            throw new \Exception('Resposta inválida da API: ' . json_last_error_msg());
        }

        if (isset($body['errors']) && !empty($body['errors'])) {
            Log::error('Erros da API Autentique:', $body['errors']);
            $firstError = $body['errors'][0];
            $errorMessage = $firstError['message'] ?? 'Erro desconhecido';
            
            // Log detalhado do erro de validação
            if (isset($firstError['extensions']['validation'])) {
                Log::error('Detalhes da validação:', $firstError['extensions']['validation']);
            }
            
            throw new \Exception('Erro da API: ' . $errorMessage);
        }

        return $body['data'] ?? $body;
    }

    public function createDocument($documentData, $signers, $filePath, $sandbox = false)
    {
        $this->ensureTokenConfigured();
        
        if (!file_exists($filePath)) {
            throw new \Exception('Arquivo PDF não encontrado: ' . $filePath);
        }

        $mutation = '
            mutation CreateDocumentMutation(
                $document: DocumentInput!,
                $signers: [SignerInput!]!,
                $file: Upload!
            ) {
                createDocument(
                    document: $document,
                    signers: $signers,
                    file: $file,
                    sandbox: ' . ($sandbox ? 'true' : 'false') . '
                ) {
                    id
                    name
                    refusable
                    sortable
                    created_at
                    signatures {
                        public_id
                        name
                        email
                        created_at
                        action { name }
                        link { short_link }
                        user { id name email }
                    }
                }
            }
        ';

        return $this->sendGraphQLRequest($mutation, [
            'document' => $documentData,
            'signers' => $signers,
            'file' => $filePath
        ]);
    }

    public function getDocument($documentId)
    {
        $this->ensureTokenConfigured();

        Log::info('🔍 Buscando documento na Autentique', [
            'document_id' => $documentId,
            'document_id_length' => strlen($documentId)
        ]);

        $query = '
            query GetDocument($id: UUID!) {
                document(id: $id) {
                    id
                    name
                    created_at
                    signed_count
                    rejected_count
                    signatures {
                        public_id
                        name
                        email
                        created_at
                        action { name }
                        user { id name email }
                    }
                }
            }
        ';

        $response = $this->sendGraphQLRequest($query, ['id' => $documentId]);

        Log::info('📤 Resposta da query GetDocument', [
            'document_id' => $documentId,
            'response_structure' => [
                'has_data' => isset($response['data']),
                'has_errors' => isset($response['errors']),
                'data_keys' => isset($response['data']) ? array_keys($response['data']) : null
            ]
        ]);

        return $response;
    }

    /**
     * Sincroniza o status de um documento local com a Autentique
     * Útil quando webhooks não são recebidos
     */
    public function syncDocumentStatus($localDocument)
    {
        try {
            Log::info('🔄 Iniciando sincronização de documento', [
                'document_id' => $localDocument->id,
                'autentique_id' => $localDocument->autentique_id,
                'current_status' => $localDocument->status
            ]);

            // Buscar dados atualizados na Autentique
            $response = $this->getDocument($localDocument->autentique_id);

            Log::info('📥 Resposta completa da Autentique', [
                'autentique_id' => $localDocument->autentique_id,
                'response' => $response,
                'has_document' => isset($response['document']),
                'has_errors' => isset($response['errors'])
            ]);

            if (!isset($response['document'])) {
                Log::warning('Documento não encontrado na Autentique', [
                    'autentique_id' => $localDocument->autentique_id,
                    'response_keys' => array_keys($response),
                    'errors' => $response['errors'] ?? null
                ]);
                return [
                    'success' => false,
                    'message' => 'Documento não encontrado na Autentique'
                ];
            }

            $autentiqueDoc = $response['document'];
            $signedCount = $autentiqueDoc['signed_count'] ?? 0;
            $rejectedCount = $autentiqueDoc['rejected_count'] ?? 0;
            $totalSigners = $localDocument->total_signers;

            Log::info('📊 Status na Autentique', [
                'signed_count' => $signedCount,
                'rejected_count' => $rejectedCount,
                'total_signers' => $totalSigners
            ]);

            $updates = [];
            $statusChanged = false;
            $statusAnterior = $localDocument->status;

            // Verificar se foi rejeitado
            if ($rejectedCount > 0 && $localDocument->status !== Document::STATUS_REJECTED) {
                $updates['status'] = Document::STATUS_REJECTED;
                $updates['rejected_count'] = $rejectedCount;
                $statusChanged = true;
                Log::info('⚠️ Documento foi rejeitado');
            }
            // Verificar se foi completamente assinado
            elseif ($signedCount >= $totalSigners && $totalSigners > 0 && $localDocument->status !== Document::STATUS_SIGNED) {
                $updates['status'] = Document::STATUS_SIGNED;
                $updates['signed_count'] = $signedCount;
                $statusChanged = true;
                Log::info('✅ Documento foi completamente assinado');
            }
            // Verificar se houve progresso nas assinaturas
            elseif ($signedCount > $localDocument->signed_count) {
                $updates['signed_count'] = $signedCount;
                Log::info('📝 Progresso de assinaturas atualizado');
            }

            // Sempre atualizar o timestamp de verificação
            $updates['last_checked_at'] = now();
            $updates['autentique_response'] = $response;

            if (!empty($updates)) {
                $localDocument->update($updates);

                // Se o status mudou para SIGNED, executar ações pós-assinatura
                if ($statusChanged && $updates['status'] === Document::STATUS_SIGNED) {
                    $this->executePostSignatureActions($localDocument);
                }

                Log::info('✅ Documento sincronizado com sucesso', [
                    'updates' => array_keys($updates),
                    'new_status' => $localDocument->status
                ]);

                return [
                    'success' => true,
                    'message' => $statusChanged ? 'Status atualizado com sucesso!' : 'Documento já estava atualizado',
                    'status_changed' => $statusChanged,
                    'status_anterior' => $statusAnterior,
                    'status_novo' => $updates['status'] ?? $localDocument->status,
                    'signed_count' => $signedCount,
                    'rejected_count' => $rejectedCount,
                    'total_signers' => $totalSigners
                ];
            }

            return [
                'success' => true,
                'message' => 'Documento já está atualizado',
                'status_changed' => false,
                'status_anterior' => $statusAnterior,
                'status_novo' => $statusAnterior,
                'signed_count' => $signedCount,
                'rejected_count' => $rejectedCount,
                'total_signers' => $totalSigners
            ];

        } catch (\Exception $e) {
            Log::error('❌ Erro ao sincronizar documento', [
                'error' => $e->getMessage(),
                'document_id' => $localDocument->id
            ]);

            return [
                'success' => false,
                'message' => 'Erro ao sincronizar: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Executa ações necessárias após documento ser assinado
     */
    private function executePostSignatureActions($localDocument)
    {
        try {
            // Registrar auditoria
            \App\Services\AuditoriaService::registrar('propostas', $localDocument->proposta_id, 'TERMO_ASSINADO', [
                'evento_tipo' => 'TERMO_ASSINADO_SYNC',
                'descricao_evento' => "Termo de adesão assinado (sincronizado manualmente)",
                'modulo' => 'propostas',
                'dados_contexto' => [
                    'documento_id' => $localDocument->id,
                    'autentique_id' => $localDocument->autentique_id,
                    'document_name' => $localDocument->name,
                    'numero_uc' => $localDocument->document_data['numeroUC'] ?? null,
                    'nome_cliente' => $localDocument->document_data['nomeCliente'] ?? null,
                    'timestamp' => now()->toISOString(),
                    'sync_manual' => true
                ]
            ]);

            // Atualizar status da UC e adicionar ao controle
            if ($localDocument->proposta_id && $localDocument->document_data) {
                $dadosDocumento = $localDocument->document_data;
                $numeroUC = $dadosDocumento['numeroUC'] ?? null;

                if ($numeroUC) {
                    $nomeCliente = $dadosDocumento['nomeCliente'] ?? $dadosDocumento['nome_cliente'] ?? 'Cliente';
                    $nomeArquivoAssinado = "Assinado - Procuracao e Termo de Adesao - {$nomeCliente} - UC {$numeroUC}.pdf";

                    // Atualizar documentação
                    app(\App\Http\Controllers\PropostaController::class)->atualizarArquivoDocumentacao(
                        $localDocument->proposta_id,
                        $numeroUC,
                        'termo_assinado',
                        $nomeArquivoAssinado,
                        'salvar'
                    );

                    app(\App\Http\Controllers\PropostaController::class)->atualizarArquivoDocumentacao(
                        $localDocument->proposta_id,
                        $numeroUC,
                        'termo_pendente',
                        '',
                        'remover'
                    );

                    // Atualizar status da UC para "Fechada"
                    $proposta = \App\Models\Proposta::find($localDocument->proposta_id);
                    if ($proposta) {
                        $unidadesConsumidoras = $proposta->unidades_consumidoras;
                        if (is_string($unidadesConsumidoras)) {
                            $unidadesConsumidoras = json_decode($unidadesConsumidoras, true);
                        } elseif (!is_array($unidadesConsumidoras)) {
                            $unidadesConsumidoras = [];
                        }

                        foreach ($unidadesConsumidoras as &$uc) {
                            if (($uc['numero_unidade'] ?? $uc['numeroUC']) == $numeroUC) {
                                $statusAnterior = $uc['status'] ?? null;
                                $uc['status'] = 'Fechada';

                                Log::info('✅ STATUS UC ALTERADO APÓS SINCRONIZAÇÃO', [
                                    'proposta_id' => $localDocument->proposta_id,
                                    'numero_uc' => $numeroUC,
                                    'status_anterior' => $statusAnterior,
                                    'status_novo' => 'Fechada'
                                ]);
                                break;
                            }
                        }

                        $proposta->update([
                            'unidades_consumidoras' => json_encode($unidadesConsumidoras)
                        ]);

                        // Adicionar ao controle automaticamente
                        try {
                            app(\App\Http\Controllers\PropostaController::class)->popularControleAutomaticoParaUC($localDocument->proposta_id, $numeroUC);
                            Log::info('✅ UC adicionada ao controle após sincronização');
                        } catch (\Exception $e) {
                            Log::warning('UC pode já estar no controle', ['error' => $e->getMessage()]);
                        }
                    }
                }
            }

            Log::info('✅ Ações pós-assinatura executadas com sucesso');

        } catch (\Exception $e) {
            Log::error('❌ Erro ao executar ações pós-assinatura', [
                'error' => $e->getMessage(),
                'document_id' => $localDocument->id
            ]);
        }
    }

    public function listDocuments($limit = 10, $page = 1)
    {
        $this->ensureTokenConfigured();
        
        $query = '
            query ListDocuments($limit: Int!, $page: Int!) {
                documents(limit: $limit, page: $page) {
                    total
                    pages
                    currentPage
                    hasNextPage
                    hasPreviousPage
                    data {
                        id
                        name
                        refusable
                        sortable
                        created_at
                    }
                }
            }
        ';

        return $this->sendGraphQLRequest($query, [
            'limit' => $limit,
            'page' => $page
        ]);
    }

    public function resendSignatures($publicIds)
    {
        $this->ensureTokenConfigured();
        
        $mutation = '
            mutation ResendSignatures($public_ids: [String!]!) {
                resendSignatures(public_ids: $public_ids)
            }
        ';

        return $this->sendGraphQLRequest($mutation, ['public_ids' => $publicIds]);
    }

    public function testConnection()
    {
        try {
            $this->ensureTokenConfigured();
            
            $query = '
                query {
                    documents(limit: 1, page: 1) {
                        total
                    }
                }
            ';

            $result = $this->sendGraphQLRequest($query);
            
            return [
                'success' => true,
                'message' => 'Token válido e funcionando!',
                'data' => $result
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null
            ];
        }
    }

    public function sendGraphQLRequest($query, $variables = [])
    {
        $this->ensureTokenConfigured();
        
        try {
            $response = $this->client->post($this->apiUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'User-Agent' => 'Laravel-AutentiqueIntegration/2.0'
                ],
                'json' => [
                    'query' => $query,
                    'variables' => $variables
                ]
            ]);

            $statusCode = $response->getStatusCode();
            $bodyContents = $response->getBody()->getContents();

            Log::info('Autentique API Response', [
                'status_code' => $statusCode,
                'query' => substr($query, 0, 200),
                'variables' => $variables,
                'response_size' => strlen($bodyContents)
            ]);

            if (str_starts_with(trim($bodyContents), '<')) {
                Log::error('Autentique retornou HTML', [
                    'response' => substr($bodyContents, 0, 500)
                ]);
                throw new \Exception('API retornou HTML. Verifique se o token está correto e se a URL está correta.');
            }

            $body = json_decode($bodyContents, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('Erro ao decodificar JSON', [
                    'error' => json_last_error_msg(),
                    'response' => substr($bodyContents, 0, 500)
                ]);
                throw new \Exception('Resposta inválida da API: ' . json_last_error_msg());
            }
            
            if (isset($body['errors']) && !empty($body['errors'])) {
                Log::error('Erros na API Autentique:', $body['errors']);
                
                $firstError = $body['errors'][0];
                $errorMessage = $firstError['message'] ?? 'Erro desconhecido';
                
                // Log detalhado do erro de validação
                if (isset($firstError['extensions']['validation'])) {
                    Log::error('Detalhes da validação:', $firstError['extensions']['validation']);
                }
                
                throw new \Exception('Erro da API: ' . $errorMessage);
            }

            return $body['data'] ?? $body;
            
        } catch (RequestException $e) {
            Log::error('Erro ao conectar com a Autentique', [
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ]);
            
            if ($e->hasResponse()) {
                $response = $e->getResponse();
                Log::error('text_1semi da resposta de erro', [
                    'status_code' => $response->getStatusCode(),
                    'body' => $response->getBody()->getContents()
                ]);
            }
            
            throw new \Exception('Erro de conexão com a API da Autentique: ' . $e->getMessage());
        }
    }

    public function criarDocumento(array $dados): array
    {
        Log::info('📤 Criando documento na Autentique', [
            'nome' => $dados['nome']
        ]);
        
        try {
            // ✅ CORREÇÃO: Processar signatários no formato correto da API Autentique
            $signatarios = [];
            foreach ($dados['signatarios'] as $signatario) {
                
                $hasEmail = isset($signatario['email']) && !empty($signatario['email']);
                $hasPhone = isset($signatario['phone_number']) && !empty($signatario['phone_number']);
                
                if ($hasEmail && !$hasPhone) {
                    // APENAS EMAIL
                    $signatarios[] = [
                        'email' => $signatario['email'],
                        'action' => 'SIGN',
                        'name' => $this->formatarNomeProprio($signatario['nome'] ?? '')
                    ];
                    
                } elseif (!$hasEmail && $hasPhone) {
                    // APENAS WHATSAPP - Formato correto da API
                    $signatarios[] = [
                        'phone' => $signatario['phone_number'],  // ✅ "phone", não "phone_number"
                        'delivery_method' => 'DELIVERY_METHOD_WHATSAPP',  // ✅ Obrigatório
                        'action' => 'SIGN'
                    ];
                    
                } elseif ($hasEmail && $hasPhone) {
                    // EMAIL + WHATSAPP - Apenas email (WhatsApp como notificação)
                    $signatarios[] = [
                        'email' => $signatario['email'],
                        'action' => 'SIGN',
                        'name' => $this->formatarNomeProprio($signatario['nome'] ?? '')
                    ];
                    
                } else {
                    throw new \Exception('Signatário deve ter email ou telefone');
                }
            }

            Log::info('📋 Signatários preparados para Autentique', [
                'count' => count($signatarios),
                'signatarios' => $signatarios
            ]);
            
            // ✅ CORREÇÃO: NÃO decodificar - o conteúdo já é binário
            $pdfContent = $dados['conteudo_pdf']; // Remover base64_decode
            
            Log::info('=== DEBUG PDF CONTENT ===', [
                'content_type' => gettype($dados['conteudo_pdf']),
                'content_length' => strlen($pdfContent),
                'is_pdf' => str_starts_with($pdfContent, '%PDF'),
                'pdf_header' => substr($pdfContent, 0, 8)
            ]);
            
            // Usar o método já existente
            return $this->createDocumentFromProposta(
                ['nome_cliente' => $dados['nome']],
                $signatarios,
                $pdfContent,
                env('AUTENTIQUE_SANDBOX', false)
            );
            
        } catch (\Exception $e) {
            Log::error('❌ Erro ao criar documento na Autentique', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    public function downloadSignedDocument($documentId)
    {
        try {
            Log::info('Tentando baixar PDF assinado', ['document_id' => $documentId]);
            
            // URL direta do PDF assinado baseada no padrão da Autentique
            $pdfUrl = "https://api.autentique.com.br/documentos/{$documentId}/assinado.pdf";
            
            Log::info('Baixando PDF da URL', ['url' => $pdfUrl]);
            
            // Baixar o PDF diretamente
            $context = stream_context_create([
                'http' => [
                    'header' => "Authorization: Bearer " . $this->token
                ]
            ]);
            
            $pdfContent = file_get_contents($pdfUrl, false, $context);
            
            if (!$pdfContent) {
                Log::error('Erro ao baixar PDF da URL', ['url' => $pdfUrl]);
                return null;
            }
            
            Log::info('PDF baixado com sucesso', ['size' => strlen($pdfContent)]);
            return $pdfContent;
            
        } catch (\Exception $e) {
            Log::error('Erro ao baixar documento assinado', [
                'document_id' => $documentId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    /**
     * Cancelar documento na Autentique
     */
    public function cancelDocument($documentId): bool
    {
        Log::info('🚫 Tentando cancelar documento na Autentique', ['document_id' => $documentId]);
        
        try {
            $this->ensureTokenConfigured();
            
            // ✅ QUERY CORRIGIDA - deleteDocument retorna Boolean, não objeto
            $mutation = '
                mutation DeleteDocument($id: UUID!) {
                    deleteDocument(id: $id)
                }
            ';

            $resultado = $this->sendGraphQLRequest($mutation, ['id' => $documentId]);

            // ✅ VERIFICAÇÃO CORRIGIDA - deleteDocument retorna boolean diretamente
            if (isset($resultado['deleteDocument']) && $resultado['deleteDocument'] === true) {
                Log::info('✅ Documento cancelado na Autentique com sucesso', [
                    'document_id' => $documentId
                ]);
                return true;
            } else {
                Log::warning('⚠️ Falha ao cancelar documento na Autentique', [
                    'document_id' => $documentId,
                    'response' => $resultado
                ]);
                return false;
            }

        } catch (\Exception $e) {
            Log::error('❌ Erro ao cancelar documento na Autentique', [
                'document_id' => $documentId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}