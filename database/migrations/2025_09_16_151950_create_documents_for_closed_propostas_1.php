<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        echo "🏁 Iniciando migration para converter IDs e criar documentos por UC...\n";
        
        // ETAPA 1: Converter IDs existentes para ULID
        echo "📝 ETAPA 1: Convertendo IDs existentes para ULID...\n";
        
        $documentosExistentes = DB::table('documents')->get();
        echo "📊 Encontrados " . $documentosExistentes->count() . " documentos existentes\n";
        
        if ($documentosExistentes->count() > 0) {
            DB::beginTransaction();
            
            try {
                // Criar tabela temporária com nova estrutura
                DB::statement("CREATE TABLE documents_temp AS SELECT * FROM documents WHERE 1=0");
                DB::statement("ALTER TABLE documents_temp ALTER COLUMN id TYPE VARCHAR(26)");
                
                // Converter IDs para ULID
                foreach ($documentosExistentes as $doc) {
                    $data = (array) $doc;
                    $data['id'] = (string) Str::ulid();
                    
                    DB::table('documents_temp')->insert($data);
                }
                
                // Trocar tabelas
                DB::statement("ALTER TABLE documents RENAME TO documents_old");
                DB::statement("ALTER TABLE documents_temp RENAME TO documents");
                
                echo "✅ Convertidos " . $documentosExistentes->count() . " IDs para ULID\n";
                DB::commit();
                
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        }
        
        // ETAPA 2: Criar documentos para UCs fechadas que não têm documento
        echo "\n📝 ETAPA 2: Criando documentos para UCs fechadas...\n";
        
        $query = "
            SELECT 
                p.id as proposta_id,
                p.numero_proposta,
                p.nome_cliente,
                u.nome as consultor_nome,
                p.usuario_id,
                p.unidades_consumidoras,
                p.updated_at as data_fechamento,
                p.created_at
            FROM propostas p
            LEFT JOIN usuarios u ON u.id = p.usuario_id
            WHERE p.deleted_at IS NULL
            AND p.unidades_consumidoras IS NOT NULL
            AND p.unidades_consumidoras::text != 'null'
            AND p.unidades_consumidoras::text != '[]'
        ";
        
        $propostas = DB::select($query);
        echo "📊 Analisando " . count($propostas) . " propostas...\n";
        
        $documentosInseridos = 0;
        $ucsProcessadas = 0;
        $ucsComDocumento = 0;
        $erros = 0;
        
        foreach ($propostas as $proposta) {
            try {
                $unidadesConsumidoras = json_decode($proposta->unidades_consumidoras, true);
                
                if (!is_array($unidadesConsumidoras) || empty($unidadesConsumidoras)) {
                    continue;
                }
                
                foreach ($unidadesConsumidoras as $uc) {
                    $status = $uc['status'] ?? 'Aguardando';
                    
                    if ($status === 'Fechada') {
                        $ucsProcessadas++;
                        $numeroUC = $uc['numero_unidade'] ?? $uc['numeroUC'] ?? null;
                        
                        if (!$numeroUC) {
                            echo "⚠️ UC sem número na proposta {$proposta->numero_proposta}\n";
                            continue;
                        }
                        
                        // Verificar se já existe documento para esta UC específica
                        $documentoExistente = DB::table('documents')
                            ->where('proposta_id', $proposta->proposta_id)
                            ->whereRaw("document_data::jsonb @> ?", [json_encode(['numero_uc' => $numeroUC])])
                            ->first();
                            
                        if ($documentoExistente) {
                            $ucsComDocumento++;
                            echo "📄 UC {$numeroUC} da proposta {$proposta->numero_proposta} já tem documento\n";
                            continue;
                        }
                        
                        // Criar documento para esta UC específica
                        $documentId = (string) Str::ulid();
                        $emailRepresentante = $uc['emailRepresentante'] ?? $uc['email_representante'] ?? '';
                        $nomeRepresentante = $uc['nomeRepresentante'] ?? $uc['nome_representante'] ?? $proposta->nome_cliente;
                        $apelidoUC = $uc['apelido'] ?? "UC {$numeroUC}";
                        
                        $insertData = [
                            'id' => $documentId,
                            'autentique_id' => null,
                            'name' => "Termo de Adesão - " . $proposta->numero_proposta,
                            'status' => 'signed',
                            'is_sandbox' => false,
                            'proposta_id' => $proposta->proposta_id,
                            'document_data' => json_encode([
                                'proposta_id' => $proposta->proposta_id,
                                'numero_proposta' => $proposta->numero_proposta,
                                'nome_cliente' => $proposta->nome_cliente,
                                'consultor' => $proposta->consultor_nome ?: 'N/A',
                                'numero_uc' => $numeroUC,
                                'apelido_uc' => $apelidoUC,
                                'uc_data' => $uc,
                                'created_by_migration' => true,
                                'migration_date' => now()->toISOString()
                            ]),
                            'signers' => json_encode([[
                                'name' => $nomeRepresentante,
                                'email' => $emailRepresentante,
                                'action' => 'SIGN'
                            ]]),
                            'autentique_response' => json_encode([
                                'historical_document' => true,
                                'migration_created' => true,
                                'uc_especifica' => $numeroUC,
                                'uc_data' => $uc
                            ]),
                            'total_signers' => 1,
                            'signed_count' => 1,
                            'rejected_count' => 0,
                            'autentique_created_at' => null,
                            'last_checked_at' => now(),
                            'created_at' => $proposta->data_fechamento ?: $proposta->created_at,
                            'updated_at' => $proposta->data_fechamento ?: $proposta->created_at
                        ];
                        
                        // Campos opcionais
                        $optionalFields = [
                            'signer_email' => $emailRepresentante,
                            'signer_name' => $nomeRepresentante,
                            'signing_url' => null,
                            'envio_whatsapp' => false,
                            'envio_email' => false,
                            'uploaded_manually' => false,
                            'uploaded_at' => null,
                            'uploaded_by' => null,
                            'manual_upload_filename' => null
                        ];
                        
                        foreach ($optionalFields as $field => $value) {
                            if (Schema::hasColumn('documents', $field)) {
                                $insertData[$field] = $value;
                            }
                        }
                        
                        DB::table('documents')->insert($insertData);
                        
                        $documentosInseridos++;
                        echo "✅ Documento criado para UC {$numeroUC} da proposta {$proposta->numero_proposta}\n";
                    }
                }
                
            } catch (\Exception $e) {
                $erros++;
                echo "❌ Erro ao processar proposta {$proposta->numero_proposta}: " . $e->getMessage() . "\n";
            }
        }
        
        // Estatísticas finais
        $totalDocuments = DB::table('documents')->count();
        $documentosSigned = DB::table('documents')->where('status', 'signed')->count();
        
        echo "\n📋 RELATÓRIO FINAL:\n";
        echo "   - Documentos existentes convertidos: " . ($documentosExistentes->count() ?? 0) . "\n";
        echo "   - UCs fechadas processadas: {$ucsProcessadas}\n";
        echo "   - UCs que já tinham documento: {$ucsComDocumento}\n";
        echo "   - Novos documentos criados: {$documentosInseridos}\n";
        echo "   - Erros encontrados: {$erros}\n";
        echo "\n📊 TOTAIS FINAIS:\n";
        echo "   - Total de documentos no sistema: {$totalDocuments}\n";
        echo "   - Documentos com status 'signed': {$documentosSigned}\n";
        echo "   - Matemática: " . ($documentosExistentes->count() ?? 0) . " + {$documentosInseridos} = {$totalDocuments}\n";
        
        // Limpar
        DB::statement("DROP TABLE IF EXISTS documents_old");
        
        echo "\n🎉 Migration concluída! Cada UC fechada agora tem seu documento.\n";
    }

    public function down(): void
    {
        echo "🔄 Revertendo migration...\n";
        
        try {
            $deleted = DB::table('documents')
                ->whereRaw("autentique_response::jsonb @> '{\"migration_created\": true}'")
                ->delete();
            
            echo "🗑️ Removidos {$deleted} documentos históricos\n";
            
        } catch (\Exception $e) {
            echo "❌ Erro: " . $e->getMessage() . "\n";
        }
    }
};