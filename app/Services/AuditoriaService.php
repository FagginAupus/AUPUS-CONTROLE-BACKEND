<?php
// app/Services/AuditoriaService.php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuditoriaService
{
    /**
     * Registrar uma ação de auditoria
     *
     * @param string $entidade Nome da tabela/entidade (ex: 'controle_clube', 'propostas')
     * @param string $entidadeId ID do registro principal
     * @param string $acao Ação realizada ('CRIADO', 'REMOVIDO', 'REATIVADO', 'ALTERADO', 'EXCLUIDO')
     * @param array $options Opções adicionais
     * @return bool
     */
    public static function registrar(string $entidade, string $entidadeId, string $acao, array $options = []): bool
    {
        try {
            $currentUser = JWTAuth::user();
            $request = request();

            // Dados padrão
            $dados = [
                'id' => Str::ulid()->toString(),
                'entidade' => $entidade,
                'entidade_id' => $entidadeId,
                'acao' => strtoupper($acao),
                'usuario_id' => $currentUser->id ?? null,
                'ip_address' => $request->ip() ?? null,
                'user_agent' => $request->userAgent() ?? null,
                'data_acao' => now(),
                'created_at' => now()
            ];

            // Opções adicionais
            if (isset($options['entidade_relacionada'])) {
                $dados['entidade_relacionada'] = $options['entidade_relacionada'];
            }
            
            if (isset($options['entidade_relacionada_id'])) {
                $dados['entidade_relacionada_id'] = $options['entidade_relacionada_id'];
            }
            
            if (isset($options['sub_acao'])) {
                $dados['sub_acao'] = $options['sub_acao'];
            }
            
            if (isset($options['dados_anteriores'])) {
                $dados['dados_anteriores'] = json_encode($options['dados_anteriores'], JSON_UNESCAPED_UNICODE);
            }
            
            if (isset($options['dados_novos'])) {
                $dados['dados_novos'] = json_encode($options['dados_novos'], JSON_UNESCAPED_UNICODE);
            }
            
            if (isset($options['metadados'])) {
                $dados['metadados'] = json_encode($options['metadados'], JSON_UNESCAPED_UNICODE);
            }
            
            if (isset($options['observacoes'])) {
                $dados['observacoes'] = $options['observacoes'];
            }
            
            if (isset($options['sessao_id'])) {
                $dados['sessao_id'] = $options['sessao_id'];
            }

            // Inserir no banco
            DB::table('auditoria')->insert($dados);

            Log::info('Auditoria registrada', [
                'entidade' => $entidade,
                'entidade_id' => $entidadeId,
                'acao' => $acao,
                'usuario_id' => $currentUser->id ?? 'sistema'
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Erro ao registrar auditoria', [
                'entidade' => $entidade,
                'entidade_id' => $entidadeId,
                'acao' => $acao,
                'error' => $e->getMessage()
            ]);
            
            // Não falhar a operação principal por erro de auditoria
            return false;
        }
    }

    /**
     * Registrar mudança de status (caso específico comum)
     */
    public static function registrarMudancaStatus(
        string $entidade, 
        string $entidadeId, 
        string $statusAnterior, 
        string $statusNovo,
        array $options = []
    ): bool {
        return self::registrar($entidade, $entidadeId, 'ALTERADO', array_merge($options, [
            'sub_acao' => 'MUDANCA_STATUS',
            'dados_anteriores' => ['status' => $statusAnterior],
            'dados_novos' => ['status' => $statusNovo],
            'metadados' => [
                'status_anterior' => $statusAnterior,
                'status_novo' => $statusNovo,
                'timestamp' => now()->toISOString()
            ]
        ]));
    }

    /**
     * Registrar remoção de controle (caso específico)
     */
    public static function registrarRemocaoControle(
        string $propostaId, 
        string $ucId, 
        string $controleId,
        string $statusAnterior,
        string $statusNovo
    ): bool {
        return self::registrar('controle_clube', $controleId, 'REMOVIDO', [
            'entidade_relacionada' => 'propostas',
            'entidade_relacionada_id' => $propostaId,
            'sub_acao' => 'SOFT_DELETE_POR_MUDANCA_STATUS',
            'dados_anteriores' => ['status' => $statusAnterior],
            'dados_novos' => ['status' => $statusNovo],
            'metadados' => [
                'proposta_id' => $propostaId,
                'uc_id' => $ucId,
                'motivo' => 'Mudança de status de Fechada para ' . $statusNovo,
                'tipo_remocao' => 'soft_delete'
            ]
        ]);
    }

    /**
     * Registrar reativação de controle
     */
    public static function registrarReativacaoControle(
        string $propostaId, 
        string $ucId, 
        string $controleId,
        string $statusAnterior,
        string $statusNovo
    ): bool {
        return self::registrar('controle_clube', $controleId, 'REATIVADO', [
            'entidade_relacionada' => 'propostas',
            'entidade_relacionada_id' => $propostaId,
            'sub_acao' => 'REMOCAO_SOFT_DELETE',
            'dados_anteriores' => ['status' => $statusAnterior],
            'dados_novos' => ['status' => $statusNovo],
            'metadados' => [
                'proposta_id' => $propostaId,
                'uc_id' => $ucId,
                'motivo' => 'Mudança de status de ' . $statusAnterior . ' para Fechada',
                'tipo_reativacao' => 'remove_soft_delete'
            ]
        ]);
    }

    /**
     * Obter estatísticas de remoções por período
     */
    public static function obterEstatisticasRemocoes(string $dataInicio = null, string $dataFim = null): array
    {
        try {
            $query = DB::table('auditoria')
                ->select(
                    'entidade',
                    'acao',
                    'sub_acao',
                    DB::raw('COUNT(*) as total'),
                    DB::raw('DATE(data_acao) as data')
                )
                ->where('entidade', 'controle_clube')
                ->whereIn('acao', ['REMOVIDO', 'REATIVADO']);

            if ($dataInicio) {
                $query->where('data_acao', '>=', $dataInicio);
            }

            if ($dataFim) {
                $query->where('data_acao', '<=', $dataFim);
            }

            return $query->groupBy('entidade', 'acao', 'sub_acao', 'data')
                        ->orderBy('data', 'desc')
                        ->get()
                        ->toArray();

        } catch (\Exception $e) {
            Log::error('Erro ao obter estatísticas de auditoria', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Obter histórico de uma entidade específica
     */
    public static function obterHistorico(string $entidade, string $entidadeId, int $limite = 50): array
    {
        try {
            return DB::table('auditoria')
                ->select([
                    'id', 'acao', 'sub_acao', 'dados_anteriores', 'dados_novos', 
                    'metadados', 'usuario_id', 'data_acao', 'observacoes'
                ])
                ->where('entidade', $entidade)
                ->where('entidade_id', $entidadeId)
                ->orderBy('data_acao', 'desc')
                ->limit($limite)
                ->get()
                ->toArray();

        } catch (\Exception $e) {
            Log::error('Erro ao obter histórico de auditoria', [
                'entidade' => $entidade,
                'entidade_id' => $entidadeId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
}