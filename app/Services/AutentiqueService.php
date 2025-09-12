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
     * Cria um documento na Autentique a partir dos dados da proposta
     */
    
    public function createDocumentFromProposta($propostaData, $signers, $pdfContent, $sandbox = true)
    {
        $this->ensureTokenConfigured();
        
        // Preparar dados do documento
        $documentData = [
            'name' => "Termo de Adesão - " . $propostaData['nome_cliente'],
            'refusable' => true,
            'sortable' => false,
            'message' => 'Documento para assinatura digital - Termo de Adesão AUPUS Energia'
        ];

        // ✅ CORREÇÃO: Salvar PDF como arquivo temporário REAL (como no api_authentic)
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
        
        // Salvar conteúdo como arquivo físico
        file_put_contents($tempPdfPath, $pdfContent);

        try {
            // ✅ Usar o método que funcionava no api_authentic
            $result = $this->createSimpleDocument($documentData, $signers, $tempPdfPath, $sandbox);
            
            // Limpar arquivo temporário
            if (file_exists($tempPdfPath)) {
                unlink($tempPdfPath);
            }
            
            return $result;
            
        } catch (\Exception $e) {
            // Limpar arquivo temporário em caso de erro
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
        $this->ensureTokenConfigured();
        
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
        ]);

        $map = json_encode(['file' => ['variables.file']]);

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
                'Authorization: Bearer ' . $this->token
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60
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
        
        $query = '
            query GetDocument($id: ID!) {
                document(id: $id) {
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

        return $this->sendGraphQLRequest($query, ['id' => $documentId]);
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
                Log::error('Detalhes da resposta de erro', [
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
            // Preparar signatários no formato correto
            $signatarios = [];
            foreach ($dados['signatarios'] as $signatario) {
                $signatarios[] = [
                    'email' => $signatario['email'],
                    'action' => 'SIGN',
                    'name' => $signatario['nome']
                ];
            }
            
            // DEBUG: Verificar o conteúdo PDF antes de decodificar
            $pdfContent = base64_decode($dados['conteudo_pdf']);
            
            Log::info('=== DEBUG PDF CONTENT ===', [
                'base64_length' => strlen($dados['conteudo_pdf']),
                'decoded_length' => strlen($pdfContent),
                'pdf_header' => substr($pdfContent, 0, 20),
                'is_pdf' => str_starts_with($pdfContent, '%PDF'),
                'pdf_version' => substr($pdfContent, 0, 8)
            ]);
            
            // Usar o método já existente
            return $this->createDocumentFromProposta(
                ['nome_cliente' => $dados['nome']],
                $signatarios,
                $pdfContent,
                env('AUTENTIQUE_SANDBOX', true)
            );
            
        } catch (\Exception $e) {
            Log::error('❌ Erro ao criar documento na Autentique', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}