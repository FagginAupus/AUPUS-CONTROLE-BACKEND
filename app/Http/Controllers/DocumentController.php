<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\AutentiqueService;
use App\Services\PDFGeneratorService;
use App\Models\Document;
use App\Models\Proposta;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class DocumentController extends Controller
{
    private $autentiqueService;
    private $pdfGeneratorService;

    public function __construct(
        AutentiqueService $autentiqueService,
        PDFGeneratorService $pdfGeneratorService
    ) {
        $this->autentiqueService = $autentiqueService;
        $this->pdfGeneratorService = $pdfGeneratorService;
    }

    /**
     * Gera e envia termo de adesão para assinatura
     */
    public function gerarTermoAdesao(Request $request, string $propostaId): JsonResponse
    {
        Log::info('📤 Iniciando geração de termo de adesão', [
            'proposta_id' => $propostaId,
            'numero_uc' => $request->numeroUC,
            'apelido' => $request->apelido
        ]);

        try {
            $proposta = Proposta::findOrFail($propostaId);
            
            // ✅ CORREÇÃO PRINCIPAL: Obter dados da UC específica
            $numeroUC = $request->numeroUC;
            $nomeCliente = $proposta->nome_cliente ?: $request->nomeCliente;
            
            if (!$numeroUC) {
                return response()->json([
                    'success' => false,
                    'message' => 'Número da UC é obrigatório para gerar termo'
                ], 400);
            }

            // ✅ CORREÇÃO: Verificar documento existente POR UC
            $documentoExistente = Document::where('proposta_id', $propostaId)
                ->where('numero_uc', $numeroUC) // ✅ ADICIONAR FILTRO POR UC
                ->where('status', '!=', Document::STATUS_CANCELLED)
                ->first();

            if ($documentoExistente) {
                return response()->json([
                    'success' => false,
                    'message' => "Já existe um termo pendente para a UC {$numeroUC}. Para atualizar, cancele o atual primeiro.",
                    'documento' => [
                        'id' => $documentoExistente->id,
                        'autentique_id' => $documentoExistente->autentique_id,
                        'nome' => $documentoExistente->name,
                        'status' => $documentoExistente->status_label,
                        'progresso' => $documentoExistente->signing_progress . '%',
                        'link_assinatura' => null,
                        'criado_em' => $documentoExistente->created_at->format('d/m/Y H:i'),
                        'opcoes' => [
                            'pode_cancelar' => true,
                            'pode_atualizar' => false
                        ]
                    ]
                ], 409);
            }

            // Obter conteúdo do PDF
            $pdfContent = null;
            
            if ($request->has('pdf_base64')) {
                $pdfContent = base64_decode($request->pdf_base64);
                Log::info('📄 Usando PDF enviado pelo frontend');
            } elseif ($request->has('nome_arquivo_temp')) {
                $caminhoTemp = storage_path("app/public/temp/{$request->nome_arquivo_temp}");
                if (file_exists($caminhoTemp)) {
                    $pdfContent = file_get_contents($caminhoTemp);
                    Log::info('📄 Usando PDF temporário salvo', ['arquivo' => $request->nome_arquivo_temp]);
                }
            }

            if (!$pdfContent) {
                Log::info('📄 Gerando PDF novamente como fallback');
                $dadosCompletos = array_merge($request->all(), [
                    'numeroProposta' => $proposta->numero_proposta,
                    'nomeCliente' => $proposta->nome_cliente,
                    'consultor' => $proposta->consultor,
                    'numeroUC' => $numeroUC,
                    'nomeCliente' => $nomeCliente
                ]);
                if (method_exists($this, 'tentarPreenchimentoPDFtk')) {
                    $pdfContent = $this->tentarPreenchimentoPDFtk($dadosCompletos);
                }
            }

            if (!$pdfContent) {
                return response()->json([
                    'success' => false,
                    'message' => 'Não foi possível obter o conteúdo do PDF para envio'
                ], 400);
            }

            $dadosDocumento = array_merge($request->all(), [
                'numeroProposta' => $proposta->numero_proposta,
                'nomeCliente' => $proposta->nome_cliente,
                'consultor' => $proposta->consultor,
                'nome_cliente' => $proposta->nome_cliente,
                'numeroUC' => $numeroUC,
                'nomeCliente' => $nomeCliente,
                // ✅ CORREÇÃO: Nome do arquivo usando Nome Cliente + Número UC
                'nome_documento' => "Procuracao e Termo de Adesao - {$nomeCliente} - UC {$numeroUC}",
                'opcoes_envio' => [
                    'enviar_whatsapp' => $request->boolean('enviar_whatsapp', false),
                    'enviar_email' => $request->boolean('enviar_email', true)
                ]
            ]);

            Log::info('📤 Enviando para Autentique com dados específicos da UC', [
                'proposta_id' => $propostaId,
                'numero_uc' => $numeroUC,
                'apelido' => $apelido,
                'nome_documento' => $dadosDocumento['nome_documento']
            ]);

            $documentoAutentique = $this->autentiqueService->enviarDocumento($pdfContent, $dadosDocumento);

            // ✅ CORREÇÃO: Salvar documento local COM número da UC
            $documentoLocal = Document::create([
                'id' => (string) Str::ulid(),
                'proposta_id' => $propostaId,
                'numero_uc' => $numeroUC,
                'autentique_id' => $documentoAutentique['id'],
                'name' => $dadosDocumento['nome_documento'],
                'status' => Document::STATUS_PENDING,
                'signer_email' => $dadosDocumento['emailRepresentante'] ?? $dadosDocumento['email'] ?? '',
                'signing_url' => $documentoAutentique['signing_url'] ?? null,
                'signing_progress' => 0,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            
            
            $documentoLocal->save();

            Log::info('✅ Termo de adesão gerado e enviado com sucesso', [
                'proposta_id' => $propostaId,
                'numero_uc' => $numeroUC,
                'documento_id' => $documentoLocal->id,
                'autentique_id' => $documentoAutentique['id'],
                'nome_arquivo' => $dadosDocumento['nome_documento']
            ]);

            return response()->json([
                'success' => true,
                'message' => "Termo de adesão gerado e enviado com sucesso para UC {$numeroUC}!",
                'documento' => [
                    'id' => $documentoLocal->id,
                    'autentique_id' => $documentoAutentique['id'],
                    'nome' => $dadosDocumento['nome_documento'],
                    'status' => 'Pendente de Assinatura',
                    'link_assinatura' => $documentoAutentique['signing_url'] ?? null,
                    'email_signatario' => $documentoLocal->signer_email,
                    'criado_em' => $documentoLocal->created_at->format('d/m/Y H:i')
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Erro ao gerar termo de adesão', [
                'proposta_id' => $propostaId,
                'numero_uc' => $request->numeroUC,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno ao gerar termo: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Preparar dados para o PDF baseado no mapeamento fornecido
     */
    private function prepararDadosParaPDF($dados): array
    {
        $agora = \Carbon\Carbon::now('America/Sao_Paulo');
        
        // Determinar CPF/CNPJ baseado no tipo
        $cpfCnpj = '';
        if (($dados['tipoDocumento'] ?? '') === 'CPF') {
            $cpfCnpj = $dados['cpf'] ?? '';
        } else {
            $cpfCnpj = $dados['cnpj'] ?? '';
        }

        return [
            'nomeAssociado' => $dados['nomeCliente'] ?? '',
            'endereco' => $dados['enderecoUC'] ?? '',
            'formaPagamento' => 'Boleto', // ← Sempre fixo
            'cpf' => $cpfCnpj,
            'representanteLegal' => $dados['nomeRepresentante'] ?? '',
            'numeroUnidade' => (string)($dados['numeroUC'] ?? ''),
            'logradouro' => $dados['logradouroUC'] ?? '',
            'dia' => $agora->format('d'),
            'mes' => $agora->format('m'),
            'economia' => $dados['descontoTarifa'] ?? '0'
        ];
    }

    /**
     * Finalizar criação do documento após preenchimento no frontend
     */
    public function finalizarDocumento(Request $request): JsonResponse
    {
        Log::info('=== FINALIZANDO DOCUMENTO PREENCHIDO ===', [
            'proposta_id' => $request->proposta_id
        ]);

        try {
            $validator = Validator::make($request->all(), [
                'proposta_id' => 'required|string',
                'pdf_base64' => 'required|string',
                'dados' => 'required|array',
                'signatarios' => 'required|array'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Decodificar PDF
            $pdfContent = base64_decode($request->pdf_base64);
            if (!$pdfContent) {
                throw new \Exception('Erro ao decodificar PDF base64');
            }

            $resultado = $this->autentiqueService->createDocumentFromProposta(
                $request->dados,
                $request->signatarios,
                $pdfContent,
                $request->sandbox ?? env('AUTENTIQUE_SANDBOX', false)
            );

            // ✅ CORREÇÃO AQUI TAMBÉM
            if (isset($resultado['data']['createDocument'])) {
                $documento = $resultado['data']['createDocument'];
            } elseif (isset($resultado['createDocument'])) {
                $documento = $resultado['createDocument'];
            } else {
                $documento = $resultado;
            }

            if (!isset($documento['id'])) {
                throw new \Exception('ID do documento não encontrado na resposta da Autentique');
            }

            // Salvar documento no banco local
            $document = Document::create([
                'autentique_id' => $documento['id'], // ✅ Garantido que existe
                'numero_uc' => $request->numeroUC,
                'name' => $request->dados['nomeAssociado'] ? "Termo de Adesão - {$request->dados['nomeAssociado']}" : "Termo de Adesão",
                'status' => Document::STATUS_PENDING,
                'is_sandbox' => $request->sandbox ?? env('AUTENTIQUE_SANDBOX', false),
                'proposta_id' => $request->proposta_id,
                'document_data' => $request->dados,
                'signers' => $request->signatarios,
                'autentique_response' => $resultado,
                'total_signers' => count($request->signatarios),
                'signed_count' => 0,
                'rejected_count' => 0,
                'autentique_created_at' => now(),
                'created_by' => auth()->id(),
                'envio_whatsapp' => $request->boolean('enviar_whatsapp', false),
                'envio_email' => $request->boolean('enviar_email', true),
                'signer_email' => $request->signatarios[0]['email'] ?? null,
                'signer_name' => $request->signatarios[0]['name'] ?? null
            ]);

            // Extrair links de assinatura
            $linkAssinatura = null;
            if (isset($resultado['signatures'][0]['link']['short_link'])) {
                $linkAssinatura = $resultado['signatures'][0]['link']['short_link'];
            }

            Log::info('✅ Documento finalizado com sucesso', [
                'document_id' => $document->id,
                'autentique_id' => $documento['id']
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Termo de adesão gerado e enviado para assinatura com sucesso!',
                'documento' => [
                    'id' => $document->id,
                    'autentique_id' => $documento['id'],
                    'nome' => $document->name,
                    'status' => $document->status_label,
                    'progresso' => $document->signing_progress . '%',
                    'link_assinatura' => $linkAssinatura,
                    'signatario' => $request->signatarios[0]['name'] ?? '',
                    'email_signatario' => $request->signatarios[0]['email'] ?? '',
                    'criado_em' => $document->created_at->format('d/m/Y H:i')
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Erro ao finalizar documento', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao finalizar documento: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Webhook da Autentique para receber notificações
     */
    public function webhook(Request $request): JsonResponse
    {
        Log::info('🎣 WEBHOOK RECEBIDO', [
            'headers' => $request->headers->all(),
            'body' => $request->all()
        ]);

        try {
            // A estrutura do webhook da Autentique é diferente do esperado
            $webhookBody = $request->all();
            $event = $webhookBody['event'] ?? [];
            $eventType = $event['type'] ?? null;
            $eventData = $event['data'] ?? [];

            Log::info('📥 Processando evento webhook', [
                'event_type' => $eventType,
                'document_id' => $eventData['id'] ?? 'N/A'
            ]);

            switch ($eventType) {
                case 'document.finished':
                    $this->handleDocumentFinished($eventData, $webhookBody);
                    break;
                    
                case 'document.created':  // NOVO CASO ADICIONADO
                    $this->handleDocumentCreated($eventData, $webhookBody);
                    break;
                    
                case 'document.updated':
                    $this->handleDocumentUpdated($eventData, $webhookBody);
                    break;
                    
                case 'signature.accepted':
                    $this->handleSignatureAccepted($eventData, $webhookBody);
                    break;
                    
                case 'signature.rejected':
                    $this->handleSignatureRejected($eventData, $webhookBody);
                    break;
                    
                default:
                    Log::info('ℹ️ Evento webhook não mapeado', ['event_type' => $eventType]);
            }

            return response()->json(['success' => true, 'message' => 'Webhook processado']);

        } catch (\Exception $e) {
            Log::error('❌ Erro ao processar webhook', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    private function handleDocumentCreated(array $eventData, array $fullEvent)
    {
        $documentId = $eventData['id'] ?? null;
        if (!$documentId) return;

        $localDocument = Document::where('autentique_id', $documentId)->first();
        if (!$localDocument) {
            Log::warning('Documento não encontrado localmente', ['document_id' => $documentId]);
            return;
        }

        // Atualizar status para "enviado" ou manter pendente
        $localDocument->update([
            'status' => Document::STATUS_PENDING, // ou STATUS_SENT se existir
            'last_checked_at' => now(),
            'autentique_response' => $fullEvent
        ]);

        // Verificar se email foi enviado
        $emailEnviado = false;
        if (isset($eventData['signatures'])) {
            foreach ($eventData['signatures'] as $signature) {
                if (!empty($signature['mail']['sent'])) {
                    $emailEnviado = true;
                    break;
                }
            }
        }

        Log::info('📧 DOCUMENTO CRIADO E ENVIADO!', [
            'document_id' => $documentId,
            'document_name' => $localDocument->name,
            'email_enviado' => $emailEnviado
        ]);
    }

    /**
     * Handle document.updated - documento foi atualizado
     */
    private function handleDocumentUpdated(array $eventData, array $fullEvent)
    {
        $documentId = $eventData['id'] ?? null;
        if (!$documentId) return;

        $localDocument = Document::where('autentique_id', $documentId)->first();
        if (!$localDocument) {
            Log::warning('Documento não encontrado localmente', ['document_id' => $documentId]);
            return;
        }

        // ✅ DETECTAR REJEIÇÃO - Verificar se rejected_count aumentou
        $rejectedCount = $eventData['rejected_count'] ?? 0;
        $previousRejectedCount = $fullEvent['event']['previous_attributes']['rejected_count'] ?? 0;
        
        // ✅ SE HOUVE REJEIÇÃO, PROCESSAR COMO REJEITADO
        if ($rejectedCount > $previousRejectedCount) {
            Log::info('🔍 REJEIÇÃO DETECTADA via document.updated', [
                'document_id' => $documentId,
                'rejected_count' => $rejectedCount,
                'previous_rejected_count' => $previousRejectedCount
            ]);
            
            // Buscar informações do signatário que rejeitou
            $signerInfo = $this->extrairInfoRejeitante($eventData);
            
            // Processar como rejeição usando a mesma lógica
            $this->processarRejeicao($localDocument, $signerInfo, $fullEvent);
            return; // Não processar como update normal
        }

        // Verificar se documento foi finalizado (todos assinaram)
        $signedCount = $eventData['signed_count'] ?? 0;
        $totalSigners = $localDocument->total_signers;
        
        if ($signedCount >= $totalSigners && $totalSigners > 0) {
            Log::info('📋 Documento finalizado detectado via update', [
                'document_id' => $documentId,
                'signed_count' => $signedCount,
                'total_signers' => $totalSigners
            ]);
            
            // Processar como documento finalizado
            $this->handleDocumentFinished($eventData, $fullEvent);
            return;
        }

        // Verificar se documento foi visualizado ou assinado parcialmente
        $previousSignedCount = $fullEvent['event']['previous_attributes']['signed_count'] ?? 0;

        // Se houve mudança no contador de assinaturas, algo importante aconteceu
        if ($signedCount != $previousSignedCount) {
            $localDocument->update([
                'signed_count' => $signedCount,
                'last_checked_at' => now(),
                'autentique_response' => $fullEvent
            ]);

            // Verificar eventos recentes nas assinaturas
            if (isset($eventData['signatures'])) {
                foreach ($eventData['signatures'] as $signature) {
                    if (isset($signature['events']) && is_array($signature['events'])) {
                        $ultimosEventos = $signature['events'];
                        
                        // Ordenar eventos por data (mais recente primeiro)
                        usort($ultimosEventos, function($a, $b) {
                            return strtotime($b['created_at'] ?? 0) - strtotime($a['created_at'] ?? 0);
                        });
                        
                        // Verificar último evento
                        foreach ($ultimosEventos as $evento) {
                            if ($evento['type'] === 'viewed') {
                                Log::info('👀 DOCUMENTO VISUALIZADO!', [
                                    'document_id' => $documentId,
                                    'user' => $evento['user']['name'] ?? 'N/A',
                                    'viewed_at' => $evento['created_at'] ?? now()
                                ]);
                                break;
                            } elseif ($evento['type'] === 'signed') {
                                Log::info('✍️ ASSINATURA REALIZADA!', [
                                    'document_id' => $documentId,
                                    'user' => $evento['user']['name'] ?? 'N/A',
                                    'signed_at' => $evento['created_at'] ?? now(),
                                    'signed_count' => $signedCount
                                ]);
                                break;
                            }
                        }
                    }
                }
            }
        }

        Log::info('📝 Documento atualizado via webhook', [
            'document_id' => $documentId,
            'status' => $localDocument->status,
            'signed_count' => $signedCount,
            'rejected_count' => $rejectedCount
        ]);
    }

    /**
     * ✅ NOVA FUNÇÃO: Extrair informações de quem rejeitou
     */
    private function extrairInfoRejeitante(array $eventData): array
    {
        $signerInfo = [
            'name' => 'Signatário',
            'email' => null,
            'reason' => null
        ];

        if (isset($eventData['signatures'])) {
            foreach ($eventData['signatures'] as $signature) {
                // Verificar se esta assinatura foi rejeitada
                if (!empty($signature['rejected'])) {
                    $signerInfo['name'] = $signature['user']['name'] ?? 'Signatário';
                    $signerInfo['email'] = $signature['user']['email'] ?? null;
                    $signerInfo['reason'] = $signature['reason'] ?? null;
                    
                    // Buscar no evento de rejeição mais detalhes
                    if (isset($signature['events'])) {
                        foreach ($signature['events'] as $evento) {
                            if ($evento['type'] === 'rejected') {
                                $signerInfo['reason'] = $evento['reason'] ?? $signerInfo['reason'];
                                break;
                            }
                        }
                    }
                    
                    break; // Parar na primeira rejeição encontrada
                }
            }
        }

        return $signerInfo;
    }

    /**
     * ✅ NOVA FUNÇÃO: Processar rejeição (centralizar lógica)
     */
    private function processarRejeicao(Document $localDocument, array $signerInfo, array $fullEvent)
    {
        $localDocument->update([
            'status' => Document::STATUS_REJECTED,
            'rejected_count' => $localDocument->rejected_count + 1,
            'last_checked_at' => now(),
            'autentique_response' => $fullEvent
        ]);

        Log::info('❌ ASSINATURA REJEITADA!', [
            'document_id' => $localDocument->autentique_id,
            'signer_name' => $signerInfo['name'],
            'signer_email' => $signerInfo['email'],
            'rejection_reason' => $signerInfo['reason'],
            'rejected_count' => $localDocument->rejected_count
        ]);

        // ✅ LÓGICA PARA ATUALIZAR UC - MESMA DO CÓDIGO ANTERIOR
        if ($localDocument->proposta_id && $localDocument->document_data) {
            $dadosDocumento = $localDocument->document_data;
            $numeroUC = $dadosDocumento['numeroUC'] ?? null;
            
            if ($numeroUC) {
                // Buscar proposta e alterar status da UC para "Recusada"
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
                            $uc['status'] = 'Recusada';
                            
                            Log::info('🔄 Status da UC alterado para Recusada via webhook', [
                                'proposta_id' => $localDocument->proposta_id,
                                'numero_uc' => $numeroUC,
                                'status_anterior' => $statusAnterior,
                                'status_novo' => 'Recusada',
                                'documento_id' => $localDocument->autentique_id,
                                'signatario' => $signerInfo['name']
                            ]);
                            
                            break;
                        }
                    }
                    
                    // Salvar as alterações
                    $proposta->unidades_consumidoras = json_encode($unidadesConsumidoras);
                    $proposta->save();
                    
                    // ✅ REMOVER DO CONTROLE se estava em "Fechada"
                    try {
                        $propostaController = new \App\Http\Controllers\PropostaController();
                        $reflection = new \ReflectionClass($propostaController);
                        $method = $reflection->getMethod('removerDoControle');
                        $method->setAccessible(true);
                        $method->invoke($propostaController, $localDocument->proposta_id, $numeroUC, 'Fechada', 'Recusada');
                        
                        Log::info('✅ UC removida do controle automaticamente', [
                            'proposta_id' => $localDocument->proposta_id,
                            'numero_uc' => $numeroUC
                        ]);
                    } catch (\Exception $e) {
                        Log::error('❌ Erro ao remover UC do controle', [
                            'error' => $e->getMessage(),
                            'proposta_id' => $localDocument->proposta_id,
                            'numero_uc' => $numeroUC
                        ]);
                    }
                    
                    Log::info('💾 Proposta atualizada com UC em status Recusada', [
                        'proposta_id' => $localDocument->proposta_id,
                        'numero_uc' => $numeroUC
                    ]);
                    try {
                        $this->cancelarDocumentoNaAutentique($localDocument->autentique_id);
                        Log::info('✅ Documento cancelado na Autentique', [
                            'autentique_id' => $localDocument->autentique_id
                        ]);
                    } catch (\Exception $e) {
                        Log::warning('⚠️ Não foi possível cancelar documento rejeitado na Autentique', [
                            'document_id' => $localDocument->autentique_id,
                            'error' => $e->getMessage()
                        ]);
                    }
                    $localDocument->delete();
                    Log::info('🗑️ DOCUMENTO REJEITADO REMOVIDO AUTOMATICAMENTE!', [
                        'proposta_id' => $localDocument->proposta_id,
                        'autentique_id' => $localDocument->autentique_id,
                        'motivo' => 'Limpeza automática após rejeição'
                    ]);
                }
            }
        }
    }

    /**
     * Handle document.finished - documento totalmente assinado
     */
    private function handleDocumentFinished(array $eventData, array $fullEvent)
    {
        $documentId = $eventData['id'] ?? null;
        if (!$documentId) return;

        $localDocument = Document::where('autentique_id', $documentId)->first();
        if (!$localDocument) {
            Log::warning('Documento não encontrado localmente', ['document_id' => $documentId]);
            return;
        }

        $oldStatus = $localDocument->status;
        $localDocument->update([
            'status' => Document::STATUS_SIGNED,
            'signed_count' => $localDocument->total_signers,
            'last_checked_at' => now(),
            'autentique_response' => $fullEvent
        ]);

        Log::info('🎉 DOCUMENTO TOTALMENTE ASSINADO!', [
            'document_id' => $localDocument->autentique_id,
            'document_name' => $localDocument->name,
            'proposta_id' => $localDocument->proposta_id,
            'status_anterior' => $oldStatus,
            'status_novo' => Document::STATUS_SIGNED
        ]);
        
        // Lógica automática quando documento for assinado
        if ($localDocument->proposta_id && $localDocument->document_data) {
            $dadosDocumento = $localDocument->document_data;
            $numeroUC = $dadosDocumento['numeroUC'] ?? null;
            
            if ($numeroUC) {
                // Buscar proposta e alterar status da UC
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
                            
                            Log::info('✅ STATUS UC ALTERADO AUTOMATICAMENTE APÓS ASSINATURA', [
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
                        app(PropostaController::class)->popularControleAutomaticoParaUC($localDocument->proposta_id, $numeroUC);
                        Log::info('🔄 UC ADICIONADA AO CONTROLE AUTOMATICAMENTE', [
                            'proposta_id' => $localDocument->proposta_id,
                            'numero_uc' => $numeroUC
                        ]);
                    } catch (\Exception $e) {
                        Log::error('❌ Erro ao adicionar UC ao controle automaticamente', [
                            'error' => $e->getMessage(),
                            'proposta_id' => $localDocument->proposta_id,
                            'numero_uc' => $numeroUC
                        ]);
                    }
                }
            }
        }
    }

    /**
     * Handle signature.accepted - signatário assinou
     */
    private function handleSignatureAccepted(array $eventData, array $fullEvent)
    {
        $documentId = $eventData['document'] ?? $eventData['id'];
        $signerName = $eventData['user']['name'] ?? 'Signatário';
        $signerEmail = $eventData['user']['email'] ?? null;

        if (!$documentId) return;

        $localDocument = Document::where('autentique_id', $documentId)->first();
        if (!$localDocument) return;

        // Incrementar contador de assinaturas
        $localDocument->increment('signed_count');
        $localDocument->update(['last_checked_at' => now()]);

        Log::info('✍️ ASSINATURA ACEITA!', [
            'document_id' => $documentId,
            'signer_name' => $signerName,
            'signer_email' => $signerEmail,
            'progress' => $localDocument->signing_progress . '%'
        ]);
    }

    /**
     * Handle signature.rejected - signatário recusou
     */
    private function handleSignatureRejected(array $eventData, array $fullEvent)
    {
        $documentId = $eventData['document'] ?? $eventData['id'];
        $signerName = $eventData['user']['name'] ?? 'Signatário';

        if (!$documentId) return;

        $localDocument = Document::where('autentique_id', $documentId)->first();
        if (!$localDocument) return;

        $localDocument->update([
            'status' => Document::STATUS_REJECTED,
            'rejected_count' => $localDocument->rejected_count + 1,
            'last_checked_at' => now(),
            'autentique_response' => $fullEvent
        ]);

        Log::info('❌ ASSINATURA REJEITADA!', [
            'document_id' => $documentId,
            'signer_name' => $signerName,
            'rejected_count' => $localDocument->rejected_count
        ]);

        // ✅ NOVA LÓGICA: Atualizar status da UC para "Recusada"
        if ($localDocument->proposta_id && $localDocument->document_data) {
            $dadosDocumento = $localDocument->document_data;
            $numeroUC = $dadosDocumento['numeroUC'] ?? null;
            
            if ($numeroUC) {
                // Buscar proposta e alterar status da UC para "Recusada"
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
                            $uc['status'] = 'Recusada';
                            
                            Log::info('🔄 Status da UC alterado para Recusada via webhook', [
                                'proposta_id' => $localDocument->proposta_id,
                                'numero_uc' => $numeroUC,
                                'status_anterior' => $statusAnterior,
                                'status_novo' => 'Recusada',
                                'documento_id' => $documentId,
                                'signatario' => $signerName
                            ]);
                            
                            break;
                        }
                    }
                    
                    // Salvar as alterações
                    $proposta->unidades_consumidoras = json_encode($unidadesConsumidoras);
                    $proposta->save();
                    
                    // ✅ REMOVER DO CONTROLE se estava em "Fechada"
                    // Usar a mesma lógica do PropostaController
                    try {
                        $propostaController = new \App\Http\Controllers\PropostaController();
                        $reflection = new \ReflectionClass($propostaController);
                        $method = $reflection->getMethod('removerDoControle');
                        $method->setAccessible(true);
                        $method->invoke($propostaController, $localDocument->proposta_id, $numeroUC, 'Fechada', 'Recusada');
                        
                        Log::info('✅ UC removida do controle automaticamente', [
                            'proposta_id' => $localDocument->proposta_id,
                            'numero_uc' => $numeroUC
                        ]);
                    } catch (\Exception $e) {
                        Log::error('❌ Erro ao remover UC do controle', [
                            'error' => $e->getMessage(),
                            'proposta_id' => $localDocument->proposta_id,
                            'numero_uc' => $numeroUC
                        ]);
                    }
                    
                    Log::info('💾 Proposta atualizada com UC em status Recusada', [
                        'proposta_id' => $localDocument->proposta_id,
                        'numero_uc' => $numeroUC
                    ]);
                }
            }
        }
    }

    public function resetarDocumentoRejeitado(Request $request, $propostaId): JsonResponse
    {
        try {
            Log::info('🔄 Iniciando reset de documento rejeitado', [
                'proposta_id' => $propostaId,
                'user_id' => auth()->id()
            ]);

            // Buscar documento rejeitado da proposta
            $documento = Document::where('proposta_id', $propostaId)
                ->where('status', Document::STATUS_REJECTED)
                ->first();

            if (!$documento) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nenhum documento rejeitado encontrado para esta proposta'
                ], 404);
            }

            Log::info('📄 Documento rejeitado encontrado', [
                'document_id' => $documento->id,
                'autentique_id' => $documento->autentique_id,
                'status' => $documento->status,
                'rejected_count' => $documento->rejected_count
            ]);

            // Opcional: Cancelar o documento na Autentique
            try {
                $this->cancelarDocumentoNaAutentique($documento->autentique_id);
                Log::info('✅ Documento cancelado na Autentique', [
                    'autentique_id' => $documento->autentique_id
                ]);
            } catch (\Exception $e) {
                Log::warning('⚠️ Não foi possível cancelar documento na Autentique', [
                    'document_id' => $documento->autentique_id,
                    'error' => $e->getMessage()
                ]);
                // Não falhar o processo todo por isso
            }

            // Excluir o documento rejeitado localmente
            $documento->delete();

            Log::info('🗑️ Documento rejeitado removido do sistema', [
                'proposta_id' => $propostaId,
                'document_id' => $documento->autentique_id,
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Documento rejeitado resetado com sucesso. Você pode gerar um novo termo agora.'
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Erro ao resetar documento rejeitado', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'proposta_id' => $propostaId,
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao resetar documento: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ MÉTODO AUXILIAR: Cancelar documento na Autentique
     */
    private function cancelarDocumentoNaAutentique($autentiqueId)
    {
        $mutation = '
            mutation {
                deleteDocument(id: "' . $autentiqueId . '") {
                    id
                    deleted
                }
            }
        ';

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('AUTENTIQUE_API_TOKEN'),
            'Content-Type' => 'application/json'
        ])->post(env('AUTENTIQUE_API_URL'), [
            'query' => $mutation
        ]);

        if (!$response->successful()) {
            throw new \Exception('Erro ao cancelar documento na Autentique: ' . $response->body());
        }

        $result = $response->json();
        
        if (isset($result['errors'])) {
            $errorMessages = collect($result['errors'])->pluck('message')->implode(', ');
            throw new \Exception('Erro GraphQL: ' . $errorMessages);
        }

        return $result['data']['deleteDocument'] ?? null;
    }

    public function verificarPdfTemporario($propostaId): JsonResponse
    {
        try {
            $dirTemp = storage_path('app/public/temp');
            $pattern = "temp_termo_{$propostaId}_*.pdf";
            $arquivos = glob($dirTemp . '/' . $pattern);
            
            if (empty($arquivos)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nenhum PDF temporário encontrado'
                ], 404);
            }
            
            // Pegar o arquivo mais recente
            $arquivoMaisRecente = array_reduce($arquivos, function($a, $b) {
                return filemtime($a) > filemtime($b) ? $a : $b;
            });
            
            $nomeArquivo = basename($arquivoMaisRecente);
            $timestamp = filemtime($arquivoMaisRecente);
            
            return response()->json([
                'success' => true,
                'pdf' => [
                    'nome' => $nomeArquivo,
                    'url' => asset("storage/temp/{$nomeArquivo}"),
                    'tamanho' => filesize($arquivoMaisRecente),
                    'gerado_em' => date('d/m/Y H:i', $timestamp)
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao verificar PDF temporário'
            ], 500);
        }
    }

    public function buscarStatusDocumento(string $propostaId): JsonResponse
    {
        try {
            // ✅ CORREÇÃO: Buscar documento por proposta E número da UC
            $numeroUC = request()->query('numero_uc'); // Pegar da query string
            
            // ✅ ADICIONAR LOG PARA DEBUG
            Log::info('📋 Buscando status documento', [
                'proposta_id' => $propostaId,
                'numero_uc' => $numeroUC,
                'query_params' => request()->query()
            ]);
            
            if ($numeroUC) {
                // Buscar documento específico da UC
                $documento = Document::where('proposta_id', $propostaId)
                    ->where('numero_uc', $numeroUC)  // ✅ FILTRAR POR UC
                    ->where('status', '!=', Document::STATUS_CANCELLED)
                    ->first();
                    
                Log::info('📋 Resultado da busca', [
                    'documento_encontrado' => $documento ? $documento->id : null,
                    'numero_uc_documento' => $documento ? $documento->numero_uc : null
                ]);
            } else {
                Log::warning('📋 numeroUC não fornecido na consulta');
                // Se não informou UC, buscar qualquer documento da proposta (compatibilidade)
                $documento = Document::where('proposta_id', $propostaId)
                    ->where('status', '!=', Document::STATUS_CANCELLED)
                    ->first();
            }

            if (!$documento) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nenhum documento encontrado'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'documento' => [
                    'id' => $documento->id,
                    'autentique_id' => $documento->autentique_id,
                    'numero_uc' => $documento->numero_uc, // ✅ INCLUIR NO RETORNO
                    'nome' => $documento->name,
                    'status' => $documento->status,
                    'status_label' => $documento->status_label ?? $documento->status,
                    'signing_progress' => $documento->signing_progress ?? 0,
                    'link_assinatura' => $documento->signing_url,
                    'signer_email' => $documento->signer_email,
                    'criado_em' => $documento->created_at->format('d/m/Y H:i')
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao buscar status do documento', [
                'proposta_id' => $propostaId,
                'numero_uc' => $numeroUC ?? 'N/A',
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno'
            ], 500);
        }
    }

    /**
     * ✅ GERAÇÃO TERMO - USANDO PREENCHIMENTO REAL DE FORM FIELDS
     */
    public function gerarTermoCompleto(Request $request, $propostaId): JsonResponse
    {
        Log::info('=== INÍCIO GERAÇÃO TERMO COM FORM FIELDS ===', [
            'proposta_id' => $propostaId,
            'user_id' => auth()->id()
        ]);

        try {
            // Validação
            $validator = Validator::make($request->all(), [
                'nomeCliente' => 'required|string|max:255',
                'numeroUC' => 'required',
                'enderecoUC' => 'required|string',
                'tipoDocumento' => 'required|in:CPF,CNPJ',
                'nomeRepresentante' => 'required|string|max:255',
                'enderecoRepresentante' => 'required|string',
                'emailRepresentante' => 'required|email',
                'whatsappRepresentante' => 'nullable|string',
                'descontoTarifa' => 'required|numeric|min:0|max:100',
                'logradouroUC' => 'required|string'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos para gerar o termo',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Buscar proposta
            $proposta = Proposta::findOrFail($propostaId);
            $numeroUC = $request->numeroUC;
            // Verificar documento existente
            $documentoExistente = Document::where('proposta_id', $propostaId)
                ->where('numero_uc', $numeroUC)
                ->where('status', Document::STATUS_PENDING)
                ->first();

            if ($documentoExistente) {
                return response()->json([
                    'success' => false,
                    'message' => 'Já existe um termo pendente de assinatura para esta proposta. Para atualizar o termo, cancele o atual primeiro.',
                    'documento' => [
                        'id' => $documentoExistente->id,
                        'autentique_id' => $documentoExistente->autentique_id,
                        'nome' => $documentoExistente->name,
                        'status' => $documentoExistente->status_label,
                        'progresso' => $documentoExistente->signing_progress . '%',
                        'link_assinatura' => null, // Não fornecer link para forçar cancelamento
                        'criado_em' => $documentoExistente->created_at->format('d/m/Y H:i'),
                        'opcoes' => [
                            'pode_cancelar' => true,
                            'pode_atualizar' => false
                        ]
                    ]
                ], 409);
            }

            // ✅ NOVA ESTRATÉGIA: Tentar diferentes métodos de preenchimento
            $dadosCompletos = array_merge($request->all(), [
                'numeroProposta' => $proposta->numero_proposta,
                'nomeCliente' => $proposta->nome_cliente,
                'consultor' => $proposta->consultor
            ]);

            Log::info('🎯 Tentando preenchimento de PDF com form fields');

            // 1️⃣ PRIMEIRA OPÇÃO: PDFtk (melhor para form fields)
            $pdfContent = $this->tentarPreenchimentoPDFtk($dadosCompletos);

            // 2️⃣ SEGUNDA OPÇÃO: Frontend com pdf-lib.js  
            if (!$pdfContent) {
                Log::info('📤 PDFtk não disponível, preparando para frontend');
                return $this->prepararParaPreenchimentoFrontend($dadosCompletos, $proposta, $request);
            }

            // ✅ 3️⃣ ENVIAR PARA AUTENTIQUE
            $resultado = $this->autentiqueService->criarDocumento([
                'nome' => "Termo de Adesão - {$proposta->numero_proposta}",
                'conteudo_pdf' => $pdfContent,
                'signatarios' => [[
                    'email' => $request->emailRepresentante,
                    'nome' => $request->nomeRepresentante
                ]]
            ]);

            $documento = $resultado['createDocument'] ?? $resultado;

            // Salvar no banco
            $documentoSalvo = Document::create([
                'proposta_id' => $proposta->id,
                'autentique_id' => $documento['id'],
                'numero_uc' => $request->dados['numeroUC'] ?? null,
                'name' => "Termo de Adesão - {$proposta->numero_proposta}",
                'status' => Document::STATUS_PENDING,
                'signer_email' => $request->emailRepresentante,
                'signer_name' => $request->nomeRepresentante,
                'signing_url' => $documento['signatures'][0]['link']['short_link'] ?? null,
                'created_by' => auth()->id(),
                'document_data' => $request->all(),
                'signers' => [[
                    'email' => $request->emailRepresentante,
                    'name' => $request->nomeRepresentante,
                    'action' => 'SIGN'
                ]],
                'autentique_response' => $documento,
                'is_sandbox' => env('AUTENTIQUE_SANDBOX', false),
                'total_signers' => 1,
                'signed_count' => 0,
                'rejected_count' => 0,
                'autentique_created_at' => $documento['created_at'] ?? now()
            ]);

            Log::info('✅ Termo gerado e enviado com sucesso');

            return response()->json([
                'success' => true,
                'message' => 'Termo gerado e enviado para assinatura com sucesso!',
                'documento' => [
                    'id' => $documentoSalvo->id,
                    'status' => $documentoSalvo->status,
                    'nome' => $documentoSalvo->name,
                    'link_assinatura' => $documentoSalvo->signing_url,
                    'email_signatario' => $documentoSalvo->signer_email
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Erro ao gerar termo', [
                'proposta_id' => $propostaId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno ao gerar termo: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ MÉTODO 1: Tentar preenchimento com PDFtk
     */
    private function tentarPreenchimentoPDFtk(array $dados): ?string
    {
        try {
            // Verificar se PDFtk está instalado
            exec('which pdftk', $output, $returnCode);
            
            if ($returnCode !== 0) {
                Log::info('⚠️ PDFtk não encontrado no sistema');
                return null;
            }

            Log::info('🔧 PDFtk encontrado, tentando preenchimento');
            
            return $this->pdfGeneratorService->preencherComPDFtk($dados);

        } catch (\Exception $e) {
            Log::warning('⚠️ Erro com PDFtk', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * ✅ MÉTODO 2: Preparar para preenchimento no frontend
     */
    private function prepararParaPreenchimentoFrontend(array $dados, $proposta, $request): JsonResponse
    {
        try {
            $dadosParaFrontend = $this->pdfGeneratorService->prepararParaFrontend($dados);

            if (!$dadosParaFrontend['sucesso']) {
                throw new \Exception($dadosParaFrontend['erro']);
            }

            Log::info('📋 Dados preparados para frontend', [
                'campos_preenchidos' => count(array_filter($dadosParaFrontend['mapeamento_campos']))
            ]);

            return response()->json([
                'success' => true,
                'tipo' => 'preenchimento_frontend',
                'message' => 'Template e dados preparados para preenchimento no frontend',
                'dados' => [
                    'template_base64' => $dadosParaFrontend['template_base64'],
                    'mapeamento_campos' => $dadosParaFrontend['mapeamento_campos'],
                    'proposta' => [
                        'id' => $proposta->id,
                        'numero' => $proposta->numero_proposta,
                        'nome_cliente' => $proposta->nome_cliente
                    ],
                    'signatario' => [
                        'nome' => $request->nomeRepresentante,
                        'email' => $request->emailRepresentante
                    ]
                ],
                'instrucoes' => [
                    'usar_pdf_lib' => true,
                    'preencher_form_fields' => true,
                    'enviar_preenchido_para' => '/api/documentos/finalizar-preenchido'
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Erro ao preparar para frontend', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'message' => 'Erro ao preparar dados para preenchimento: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ MÉTODO 3: Receber PDF preenchido do frontend
     */
    public function finalizarPreenchido(Request $request): JsonResponse
    {
        Log::info('📥 Recebendo PDF preenchido do frontend');

        try {
            $validator = Validator::make($request->all(), [
                'proposta_id' => 'required|string',
                'pdf_base64' => 'required|string',
                'dados' => 'required|array',
                'signatario' => 'required|array'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Decodificar PDF preenchido
            $pdfContent = base64_decode($request->pdf_base64);
            if (!$pdfContent) {
                throw new \Exception('Erro ao decodificar PDF');
            }

            $proposta = Proposta::findOrFail($request->proposta_id);

            // Enviar para Autentique
            $resultado = $this->autentiqueService->criarDocumento([
                'nome' => "Termo de Adesão - {$proposta->numero_proposta}",
                'conteudo_pdf' => $pdfContent,
                'signatarios' => [[
                    'email' => $request->signatario['email'],
                    'nome' => $request->signatario['nome']
                ]]
            ]);

            // ✅ CORREÇÃO AQUI TAMBÉM
            if (isset($resultado['data']['createDocument'])) {
                $documento = $resultado['data']['createDocument'];
            } elseif (isset($resultado['createDocument'])) {
                $documento = $resultado['createDocument'];
            } else {
                $documento = $resultado;
            }

            if (!isset($documento['id'])) {
                throw new \Exception('ID do documento não encontrado na resposta da Autentique');
            }

            // Salvar no banco
            $documentoSalvo = Document::create([
                'proposta_id' => $request->proposta_id,
                'autentique_id' => $documento['id'], // ✅ Garantido que existe
                'numero_uc' => $numeroUC,
                'name' => "Termo de Adesão - {$proposta->numero_proposta}",
                'status' => Document::STATUS_PENDING,
                'signer_email' => $request->signatario['email'],
                'signer_name' => $request->signatario['nome'],
                'signing_url' => $documento['signatures'][0]['link']['short_link'] ?? null,
                'created_by' => auth()->id(),
                'document_data' => $request->dados,
                'signers' => [$request->signatario],
                'autentique_response' => $documento,
                'is_sandbox' => env('AUTENTIQUE_SANDBOX', false),
                'total_signers' => 1,
                'signed_count' => 0,
                'rejected_count' => 0,
                'autentique_created_at' => $documento['created_at'] ?? now()
            ]);

            Log::info('✅ PDF preenchido processado com sucesso');

            return response()->json([
                'success' => true,
                'message' => 'Termo preenchido e enviado para assinatura!',
                'documento' => [
                    'id' => $documentoSalvo->id,
                    'status' => $documentoSalvo->status,
                    'nome' => $documentoSalvo->name,
                    'link_assinatura' => $documentoSalvo->signing_url
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Erro ao finalizar preenchimento', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao processar PDF preenchido: ' . $e->getMessage()
            ], 500);
        }
    }

    public function cancelarDocumentoPendente($propostaId): JsonResponse
    {
        try {
            $documento = Document::where('proposta_id', $propostaId)
                ->where('status', Document::STATUS_PENDING)
                ->first();

            if (!$documento) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nenhum documento pendente encontrado'
                ], 404);
            }

            // Cancelar na Autentique se necessário
            try {
                $this->autentiqueService->cancelDocument($documento->autentique_id);
            } catch (\Exception $e) {
                Log::warning('Erro ao cancelar documento na Autentique', ['error' => $e->getMessage()]);
            }

            // Marcar como cancelado localmente
            $documento->update([
                'status' => Document::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => auth()->id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Documento cancelado com sucesso'
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao cancelar documento', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Erro ao cancelar documento'
            ], 500);
        }
    }

    public function baixarPDFAssinado($propostaId): JsonResponse
    {   
        Log::info("📋 ERRO na busca PDF", ['proposta' => $propostaId]);
        Log::info("📋 ERRO na verificação do documento");
        Log::info("📋 ERRO no processamento da request");
        Log::info("📋 ERRO na busca por arquivos finalizados");
        try {
            $documento = Document::where('proposta_id', $propostaId)
                ->where('status', Document::STATUS_SIGNED)
                ->first();

            if (!$documento) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nenhum documento assinado encontrado'
                ], 404);
            }

            $nomeArquivo = "termo_assinado_{$documento->id}.pdf";
            $caminhoLocal = storage_path("app/public/termos_assinados/{$nomeArquivo}");

            // TENTATIVA 1: Baixar da Autentique (se tiver autentique_id)
            if (!empty($documento->autentique_id)) {
                try {
                    Log::info('📥 Tentando baixar da Autentique', ['autentique_id' => $documento->autentique_id]);
                    
                    $pdfAssinado = $this->autentiqueService->downloadSignedDocument($documento->autentique_id);
                    
                    if ($pdfAssinado) {
                        // Salvar localmente
                        if (!is_dir(dirname($caminhoLocal))) {
                            mkdir(dirname($caminhoLocal), 0755, true);
                        }
                        
                        file_put_contents($caminhoLocal, $pdfAssinado);

                        return response()->json([
                            'success' => true,
                            'source' => 'autentique',
                            'documento' => [
                                'nome' => $nomeArquivo,
                                'url' => asset("storage/termos_assinados/{$nomeArquivo}"),
                                'tamanho' => strlen($pdfAssinado),
                                'data_assinatura' => $documento->updated_at->format('d/m/Y H:i')
                            ]
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::warning('⚠️ Falha ao baixar da Autentique', ['error' => $e->getMessage()]);
                }
            }

            // TENTATIVA 2: Verificar se já existe arquivo local
            if (file_exists($caminhoLocal)) {
                Log::info('📁 Usando arquivo local existente');
                
                return response()->json([
                    'success' => true,
                    'source' => 'local_cache',
                    'documento' => [
                        'nome' => $nomeArquivo,
                        'url' => asset("storage/termos_assinados/{$nomeArquivo}"),
                        'tamanho' => filesize($caminhoLocal),
                        'data_assinatura' => $documento->updated_at->format('d/m/Y H:i')
                    ]
                ]);
            }

            // TENTATIVA 3: Documento histórico - precisa de upload manual
            Log::info('📋 Documento histórico - upload manual necessário');
            
            return response()->json([
                'success' => false,
                'needs_manual_upload' => true,
                'message' => 'Documento assinado não encontrado - upload manual necessário',
                'documento_info' => [
                    'id' => $documento->id,
                    'nome' => $documento->name,
                    'data_assinatura' => $documento->updated_at->format('d/m/Y H:i')
                ]
            ], 404);

        } catch (\Exception $e) {
            Log::error('❌ Erro ao buscar PDF assinado', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Erro interno ao buscar documento assinado'
            ], 500);
        }
    }

    public function uploadTermoAssinadoManual(Request $request, $propostaId): JsonResponse
    {
        try {
            $documento = Document::where('proposta_id', $propostaId)
                ->where('status', Document::STATUS_SIGNED)
                ->first();

            if (!$documento) {
                return response()->json([
                    'success' => false,
                    'message' => 'Documento não encontrado'
                ], 404);
            }

            $request->validate([
                'arquivo' => 'required|mimes:pdf|max:10240' // 10MB máximo
            ]);

            $arquivo = $request->file('arquivo');
            $nomeArquivo = "termo_assinado_{$documento->id}.pdf";
            $caminhoDestino = "termos_assinados/{$nomeArquivo}";

            // Salvar arquivo no storage público
            $arquivo->storeAs('public/' . dirname($caminhoDestino), basename($caminhoDestino));

            // Atualizar documento com informação de upload manual
            $documento->update([
                'uploaded_manually' => true,
                'uploaded_at' => now(),
                'uploaded_by' => auth()->id(),
                'manual_upload_filename' => $arquivo->getClientOriginalName()
            ]);

            Log::info('📄 Upload manual realizado', [
                'documento_id' => $documento->id,
                'proposta_id' => $propostaId,
                'original_filename' => $arquivo->getClientOriginalName(),
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Termo assinado enviado com sucesso',
                'documento' => [
                    'nome' => $nomeArquivo,
                    'url' => asset("storage/{$caminhoDestino}"),
                    'tamanho' => $arquivo->getSize(),
                    'uploaded_manually' => true
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Erro no upload manual', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Erro ao enviar arquivo: ' . $e->getMessage()
            ], 500);
        }
    }


    public function gerarPdfApenas(Request $request, $propostaId): JsonResponse
    {
        Log::info('📄 Gerando PDF do termo sem enviar para Autentique', [
            'proposta_id' => $propostaId
        ]);

        try {
            $proposta = Proposta::findOrFail($propostaId);

            // Validar campos obrigatórios
            $validator = Validator::make($request->all(), [
                'nomeRepresentante' => 'required|string|max:255',
                'emailRepresentante' => 'nullable|email|max:255',
                'whatsappRepresentante' => 'nullable|string|max:20',
                'nomeCliente' => 'required|string|max:255',
                'numeroUC' => 'required',
                'enderecoUC' => 'required|string',
                'tipoDocumento' => 'required|in:CPF,CNPJ',
                'enderecoRepresentante' => 'required|string',
                'descontoTarifa' => 'required|numeric|min:0|max:100',
                'logradouroUC' => 'required|string'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Campos obrigatórios não preenchidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Preparar dados completos
            $dadosCompletos = array_merge($request->all(), [
                'numeroProposta' => $proposta->numero_proposta,
                'nomeCliente' => $proposta->nome_cliente,
                'consultor' => $proposta->consultor,
                'propostaId' => $propostaId
            ]);

            Log::info('🎯 Gerando PDF para visualização');

            // Tentar preenchimento com PDFtk (usar método existente se houver)
            $pdfContent = null;
            if (method_exists($this, 'tentarPreenchimentoPDFtk')) {
                $pdfContent = $this->tentarPreenchimentoPDFtk($dadosCompletos);
            }

            // Se não conseguir com PDFtk, retornar dados para frontend
            if (!$pdfContent) {
                Log::info('📤 PDFtk não disponível, enviando dados para frontend');
                return response()->json([
                    'success' => true,
                    'requires_frontend_processing' => true,
                    'dados' => $dadosCompletos,
                    'template_url' => asset('pdfs/termo_adesao_template.pdf')
                ]);
            }

            // Salvar PDF temporariamente para visualização
            $nomeArquivoTemp = "temp_termo_{$propostaId}_" . time() . ".pdf";
            $caminhoTemp = storage_path("app/public/temp/{$nomeArquivoTemp}");
            
            if (!is_dir(dirname($caminhoTemp))) {
                mkdir(dirname($caminhoTemp), 0755, true);
            }
            
            file_put_contents($caminhoTemp, $pdfContent);

            // Limpar arquivos temporários antigos
            $this->limparArquivosTemporarios();

            return response()->json([
                'success' => true,
                'message' => 'PDF gerado com sucesso!',
                'pdf' => [
                    'nome' => $nomeArquivoTemp,
                    'url' => asset("storage/temp/{$nomeArquivoTemp}"),
                    'tamanho' => strlen($pdfContent),
                    'gerado_em' => now()->format('d/m/Y H:i')
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Erro ao gerar PDF apenas', [
                'proposta_id' => $propostaId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno ao gerar PDF: ' . $e->getMessage()
            ], 500);
        }
    }

    private function limparArquivosTemporarios()
    {
        try {
            $dirTemp = storage_path('app/public/temp');
            if (!is_dir($dirTemp)) return;

            $arquivos = glob($dirTemp . '/temp_termo_*.pdf');
            $agora = time();
            
            foreach ($arquivos as $arquivo) {
                // Remove arquivos com mais de 1 hora
                if (filemtime($arquivo) < $agora - 3600) {
                    unlink($arquivo);
                    Log::info('🗑️ Arquivo temporário removido', ['arquivo' => basename($arquivo)]);
                }
            }
        } catch (\Exception $e) {
            Log::warning('Erro ao limpar arquivos temporários', ['error' => $e->getMessage()]);
        }
    }

    public function enviarParaAutentique(Request $request, string $propostaId): JsonResponse
    {
        Log::info('📤 Enviando PDF para Autentique', ['proposta_id' => $propostaId]);

        try {
            $proposta = Proposta::findOrFail($propostaId);
            $numeroUC = $request->numeroUC;

            // Verificar se já existe documento pendente
            $documentoExistente = Document::where('proposta_id', $propostaId)
                ->where('numero_uc', $numeroUC)
                ->where('status', '!=', Document::STATUS_CANCELLED)
                ->first();

            if ($documentoExistente) {
                return response()->json([
                    'success' => false,
                    'message' => 'Já existe um documento pendente para esta proposta. Para atualizar o termo, cancele o atual primeiro.',
                    'documento' => [
                        'id' => $documentoExistente->id,
                        'autentique_id' => $documentoExistente->autentique_id,
                        'nome' => $documentoExistente->name,
                        'status' => $documentoExistente->status_label,
                        'progresso' => $documentoExistente->signing_progress . '%',
                        'link_assinatura' => null,
                        'criado_em' => $documentoExistente->created_at->format('d/m/Y H:i'),
                        'opcoes' => [
                            'pode_cancelar' => true,
                            'pode_atualizar' => false
                        ]
                    ]
                ], 409);
            }

            // Obter conteúdo do PDF
            $pdfContent = null;
            
            if ($request->has('pdf_base64')) {
                $pdfContent = base64_decode($request->pdf_base64);
                Log::info('📄 Usando PDF enviado pelo frontend');
            } elseif ($request->has('nome_arquivo_temp')) {
                $caminhoTemp = storage_path("app/public/temp/{$request->nome_arquivo_temp}");
                if (file_exists($caminhoTemp)) {
                    $pdfContent = file_get_contents($caminhoTemp);
                    Log::info('📄 Usando PDF temporário salvo', ['arquivo' => $request->nome_arquivo_temp]);
                }
            }

            if (!$pdfContent) {
                Log::info('📄 Gerando PDF novamente como fallback');
                $dadosCompletos = array_merge($request->all(), [
                    'numeroProposta' => $proposta->numero_proposta,
                    'nomeCliente' => $proposta->nome_cliente,
                    'consultor' => $proposta->consultor
                ]);
                if (method_exists($this, 'tentarPreenchimentoPDFtk')) {
                    $pdfContent = $this->tentarPreenchimentoPDFtk($dadosCompletos);
                }
            }

            if (!$pdfContent) {
                return response()->json([
                    'success' => false,
                    'message' => 'Não foi possível obter o conteúdo do PDF para envio'
                ], 400);
            }

            $dadosDocumento = array_merge($request->all(), [
                'numeroProposta' => $proposta->numero_proposta,
                'nomeCliente' => $proposta->nome_cliente,
                'consultor' => $proposta->consultor,
                'nome_cliente' => $proposta->nome_cliente,
                'opcoes_envio' => [
                    'enviar_email' => $request->boolean('enviar_email', true),
                    'enviar_whatsapp' => $request->boolean('enviar_whatsapp', false)
                ]
            ]);

            // ✅ LOG PARA VERIFICAR AS OPÇÕES
            Log::info('📋 Opções de envio definidas pelo usuário', [
                'enviar_email' => $dadosDocumento['opcoes_envio']['enviar_email'],
                'enviar_whatsapp' => $dadosDocumento['opcoes_envio']['enviar_whatsapp'],
                'proposta_id' => $propostaId
            ]);

            // Continuar com a preparação do signatário (manter como está)
            $signatario = [
                'email' => $request->emailRepresentante,
                'action' => 'SIGN',
                'name' => $request->nomeRepresentante
            ];

            if ($request->whatsappRepresentante && $request->boolean('enviar_whatsapp', false)) {
                // Formatação do telefone (manter como está)
                $telefone = preg_replace('/\D/', '', $request->whatsappRepresentante);
                
                if (strlen($telefone) === 11) {
                    $telefone = '+55' . $telefone;
                } elseif (strlen($telefone) === 10) {
                    $telefone = '+559' . $telefone;
                } elseif (strlen($telefone) === 13 && substr($telefone, 0, 2) === '55') {
                    $telefone = '+' . $telefone;
                } else {
                    if (strlen($telefone) >= 10) {
                        $telefone = '+55' . $telefone;
                    }
                }
                
                $signatario['phone_number'] = $telefone;
                
                Log::info('✅ WhatsApp adicionado ao signatário', [
                    'phone_number' => $telefone
                ]);
            }

            $signatarios = [$signatario];

            Log::info('📋 Signatários preparados com opções de envio', [
                'signatarios' => $signatarios,
                'opcoes_envio' => $dadosDocumento['opcoes_envio']
            ]);

            // ✅ CHAMAR O AUTENTIQUE SERVICE COM AS OPÇÕES
            $resultado = $this->autentiqueService->createDocumentFromProposta(
                $dadosDocumento,  // ✅ Agora inclui opcoes_envio
                $signatarios,
                $pdfContent,
                env('AUTENTIQUE_SANDBOX', false)
            );

            // ✅ CORREÇÃO CRÍTICA: Processar resposta corretamente
            Log::info('🔍 Analisando resposta da Autentique', [
                'resultado_tipo' => gettype($resultado),
                'tem_data' => isset($resultado['data']),
                'tem_createDocument' => isset($resultado['data']['createDocument']) ?? false,
                'keys_resultado' => is_array($resultado) ? array_keys($resultado) : 'não é array'
            ]);

            // ✅ CORRIGIR EXTRAÇÃO DOS DADOS
            if (isset($resultado['data']['createDocument'])) {
                // Resposta GraphQL padrão: {"data": {"createDocument": {...}}}
                $documentoData = $resultado['data']['createDocument'];
            } elseif (isset($resultado['createDocument'])) {
                // Resposta já processada: {"createDocument": {...}}
                $documentoData = $resultado['createDocument'];
            } else {
                // Resposta direta ou formato diferente
                $documentoData = $resultado;
            }

            // ✅ VALIDAR SE TEM ID ANTES DE USAR
            if (!isset($documentoData['id'])) {
                Log::error('❌ ID não encontrado na resposta', [
                    'documentoData' => $documentoData,
                    'resultado_completo' => $resultado
                ]);
                throw new \Exception('Resposta da Autentique não contém o ID do documento');
            }

            $documentId = $documentoData['id'];
            $linkAssinatura = null;

            // Extrair link de assinatura
            if (isset($documentoData['signatures'][0]['link']['short_link'])) {
                $linkAssinatura = $documentoData['signatures'][0]['link']['short_link'];
            }

            // ✅ PREPARAR DADOS PARA SALVAR NO BANCO (usando dados do request)
            $documentData = array_merge($request->all(), [
                'numeroProposta' => $proposta->numero_proposta,
                'nomeCliente' => $proposta->nome_cliente,
                'consultor' => $proposta->consultor
            ]);

            $document = Document::create([
                'id' => (string) Str::ulid(),
                'autentique_id' => $documentId,
                'name' => "Termo de Adesão - {$proposta->numero_proposta}",
                'numero_uc' => $numeroUC,
                'status' => Document::STATUS_PENDING,
                'is_sandbox' => env('AUTENTIQUE_SANDBOX', false),
                'proposta_id' => $propostaId,
                'document_data' => $documentData,
                'signers' => $signatarios,
                'autentique_response' => $resultado,
                'total_signers' => 1,
                'signed_count' => 0,
                'rejected_count' => 0,
                'autentique_created_at' => now(),
                'created_by' => auth()->id(),
                'signer_email' => $request->emailRepresentante,
                'signer_name' => $request->nomeRepresentante,
                'signing_url' => $linkAssinatura,
                'envio_whatsapp' => $request->boolean('enviar_whatsapp', false),
                'envio_email' => $request->boolean('enviar_email', true),
                'uploaded_manually' => false,
                'uploaded_at' => null,
                'uploaded_by' => null,
                'manual_upload_filename' => null
            ]);

            // Limpar arquivo temporário se foi usado
            if ($request->has('nome_arquivo_temp')) {
                $caminhoTemp = storage_path("app/public/temp/{$request->nome_arquivo_temp}");
                if (file_exists($caminhoTemp)) {
                    unlink($caminhoTemp);
                    Log::info('🗑️ Arquivo temporário removido após envio');
                }
            }

            // ✅ PREPARAR INFORMAÇÕES DE ENVIO PARA RESPOSTA
            $envioEmail = $request->boolean('enviar_email', true);
            $envioWhatsApp = $request->boolean('enviar_whatsapp', false);

            // ✅ DETERMINAR CANAIS DE ENVIO E DESTINATÁRIO PARA EXIBIÇÃO
            $canaisEnvio = [];
            $destinatarioExibicao = '';

            if ($envioEmail && $envioWhatsApp) {
                $canaisEnvio[] = 'E-mail';
                $canaisEnvio[] = 'WhatsApp';
                $destinatarioExibicao = $request->emailRepresentante; // Principal por email
                
            } elseif ($envioEmail && !$envioWhatsApp) {
                $canaisEnvio[] = 'E-mail';
                $destinatarioExibicao = $request->emailRepresentante;
                
            } elseif (!$envioEmail && $envioWhatsApp) {
                $canaisEnvio[] = 'WhatsApp';
                $destinatarioExibicao = $request->whatsappRepresentante; // ✅ Mostrar telefone quando só WhatsApp
                
            } else {
                $canaisEnvio[] = 'Nenhum canal selecionado';
                $destinatarioExibicao = 'N/A';
            }

            Log::info('✅ Termo enviado para Autentique com sucesso', [
                'proposta_id' => $propostaId,
                'document_id' => $document->id,
                'autentique_id' => $documentId,
                'canais_envio' => $canaisEnvio,
                'destinatario_exibicao' => $destinatarioExibicao
            ]);

            // ✅ RESPOSTA CORRIGIDA PARA O FRONTEND
            return response()->json([
                'success' => true,
                'message' => 'Termo enviado para assinatura com sucesso!',
                'documento' => [
                    'id' => $document->id,
                    'autentique_id' => $documentId,
                    'nome' => $document->name,
                    'status' => $document->status_label,
                    'link_assinatura' => $linkAssinatura,
                    // ✅ CAMPOS CORRIGIDOS PARA EXIBIÇÃO ADEQUADA
                    'email_signatario' => $destinatarioExibicao, 
                    'destinatario_exibicao' => $destinatarioExibicao,
                    'canais_envio' => $canaisEnvio,
                    'canais_envio_texto' => implode(' e ', $canaisEnvio), 
                    'criado_em' => $document->created_at->format('d/m/Y H:i'),
                    // ✅ Campos específicos para controle
                    'envio_email' => $envioEmail,
                    'envio_whatsapp' => $envioWhatsApp,
                    'whatsapp_formatado' => $request->whatsappRepresentante ? 
                        preg_replace('/\D/', '', $request->whatsappRepresentante) : null
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Erro ao enviar para Autentique', [
                'proposta_id' => $propostaId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno ao enviar documento: ' . $e->getMessage()
            ], 500);
        }
    }

    public function buscarPDFOriginal(Request $request, string $propostaId): Response
    {
        Log::info('📄 Buscando PDF original para visualização', ['proposta_id' => $propostaId]);

        try {
            // Buscar documento da proposta
            $documento = Document::where('proposta_id', $propostaId)
                ->whereIn('status', [Document::STATUS_PENDING, Document::STATUS_SIGNED])
                ->orderBy('created_at', 'desc')
                ->first();

            if (!$documento) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nenhum documento encontrado para esta proposta'
                ], 404);
            }

            // OPÇÃO 1: Se temos o PDF salvo em storage local
            $pdfPath = storage_path("app/documentos/{$documento->autentique_id}.pdf");
            if (file_exists($pdfPath)) {
                Log::info('✅ PDF encontrado localmente', ['path' => $pdfPath]);
                
                return response()->file($pdfPath, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'inline; filename="termo_' . $documento->name . '.pdf"'
                ]);
            }

            // OPÇÃO 2: Regenerar PDF com os dados salvos
            if (!empty($documento->document_data)) {
                Log::info('🔄 Regenerando PDF com dados salvos');
                
                $dadosDocumento = $documento->document_data;
                
                // Usar o mesmo serviço que gera PDF
                if (method_exists($this, 'tentarPreenchimentoPDFtk')) {
                    $pdfContent = $this->tentarPreenchimentoPDFtk($dadosDocumento);
                    
                    if ($pdfContent) {
                        return response($pdfContent, 200, [
                            'Content-Type' => 'application/pdf',
                            'Content-Disposition' => 'inline; filename="termo_regenerado.pdf"'
                        ]);
                    }
                }
                
                // Fallback: usar PDFGeneratorService
                try {
                    $pdfContent = $this->pdfGeneratorService->gerarTermoPreenchido($dadosDocumento);
                    
                    return response($pdfContent, 200, [
                        'Content-Type' => 'application/pdf',
                        'Content-Disposition' => 'inline; filename="termo_regenerado.pdf"'
                    ]);
                    
                } catch (\Exception $e) {
                    Log::warning('⚠️ Falha ao regenerar PDF', ['error' => $e->getMessage()]);
                }
            }

            // OPÇÃO 3: Baixar da Autentique (se disponível)
            try {
                if (method_exists($this->autentiqueService, 'downloadDocument')) {
                    $pdfContent = $this->autentiqueService->downloadDocument($documento->autentique_id);
                    
                    if ($pdfContent) {
                        Log::info('✅ PDF baixado da Autentique');
                        
                        return response($pdfContent, 200, [
                            'Content-Type' => 'application/pdf',
                            'Content-Disposition' => 'inline; filename="termo_autentique.pdf"'
                        ]);
                    }
                }
            } catch (\Exception $e) {
                Log::warning('⚠️ Falha ao baixar PDF da Autentique', ['error' => $e->getMessage()]);
            }

            // Se chegou até aqui, não conseguiu encontrar/gerar o PDF
            return response()->json([
                'success' => false,
                'message' => 'PDF não disponível para visualização'
            ], 404);

        } catch (\Exception $e) {
            Log::error('❌ Erro ao buscar PDF original', [
                'proposta_id' => $propostaId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno ao buscar PDF'
            ], 500);
        }
    }
}