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
     * Registrar uma ação de auditoria com usuário específico
     *
     * @param string $entidade Nome da tabela/entidade
     * @param string $entidadeId ID do registro principal
     * @param string $acao Ação realizada
     * @param string $usuarioId ID do usuário que executou a ação
     * @param array $options Opções adicionais
     * @return bool
     */
    public static function registrarComUsuario(string $entidade, string $entidadeId, string $acao, string $usuarioId, array $options = []): bool
    {
        try {
            $request = request();

            // Dados padrão - usando o usuário fornecido
            $dados = [
                'id' => Str::ulid()->toString(),
                'entidade' => $entidade,
                'entidade_id' => $entidadeId,
                'acao' => strtoupper($acao),
                'usuario_id' => $usuarioId,
                'ip_address' => $request->ip() ?? null,
                'user_agent' => $request->userAgent() ?? null,
                'data_acao' => now(),
                'created_at' => now()
            ];

            // Processar opções da mesma forma que o método original
            $optionsFields = [
                'entidade_relacionada', 'entidade_relacionada_id', 'sub_acao',
                'dados_anteriores', 'dados_novos', 'metadados', 'observacoes',
                'sessao_id', 'evento_tipo', 'descricao_evento', 'modulo',
                'dados_contexto', 'evento_critico'
            ];

            foreach ($optionsFields as $field) {
                if (isset($options[$field])) {
                    if (in_array($field, ['dados_anteriores', 'dados_novos', 'metadados', 'dados_contexto'])) {
                        $dados[$field] = json_encode($options[$field], JSON_UNESCAPED_UNICODE);
                    } else {
                        $dados[$field] = $options[$field];
                    }
                }
            }

            // Inserir no banco
            DB::table('auditoria')->insert($dados);

            Log::info('Auditoria registrada com usuário específico', [
                'entidade' => $entidade,
                'entidade_id' => $entidadeId,
                'acao' => $acao,
                'usuario_id' => $usuarioId
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Erro ao registrar auditoria com usuário específico', [
                'entidade' => $entidade,
                'entidade_id' => $entidadeId,
                'acao' => $acao,
                'usuario_id' => $usuarioId,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

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

            // Novos campos de evento
            if (isset($options['evento_tipo'])) {
                $dados['evento_tipo'] = $options['evento_tipo'];
            }

            if (isset($options['descricao_evento'])) {
                $dados['descricao_evento'] = $options['descricao_evento'];
            }

            if (isset($options['modulo'])) {
                $dados['modulo'] = $options['modulo'];
            }

            if (isset($options['dados_contexto'])) {
                $dados['dados_contexto'] = json_encode($options['dados_contexto'], JSON_UNESCAPED_UNICODE);
            }

            if (isset($options['evento_critico'])) {
                $dados['evento_critico'] = $options['evento_critico'];
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
            'evento_critico' => true, // UC saindo do controle é crítico
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

    /**
     * Registrar evento de criação de proposta
     */
    public static function registrarCriacaoProposta(string $propostaId, array $dadosProposta): bool
    {
        return self::registrar('propostas', $propostaId, 'CRIADO', [
            'evento_tipo' => 'PROPOSTA_CRIADA',
            'descricao_evento' => 'Nova proposta criada',
            'modulo' => 'propostas',
            'dados_novos' => $dadosProposta,
            'dados_contexto' => [
                'numero_proposta' => $dadosProposta['numero'] ?? null,
                'consultor_id' => $dadosProposta['consultor'] ?? null,
                'timestamp' => now()->toISOString()
            ]
        ]);
    }

    /**
     * Registrar evento de edição de proposta
     */
    public static function registrarEdicaoProposta(string $propostaId, array $dadosAnteriores, array $dadosNovos): bool
    {
        return self::registrar('propostas', $propostaId, 'ALTERADO', [
            'evento_tipo' => 'PROPOSTA_EDITADA',
            'descricao_evento' => 'Proposta editada',
            'modulo' => 'propostas',
            'dados_anteriores' => $dadosAnteriores,
            'dados_novos' => $dadosNovos,
            'dados_contexto' => [
                'campos_alterados' => array_keys(array_diff_assoc($dadosNovos, $dadosAnteriores)),
                'timestamp' => now()->toISOString()
            ]
        ]);
    }

    /**
     * Registrar evento de criação de UG
     */
    public static function registrarCriacaoUG(string $ugId, array $dadosUG): bool
    {
        return self::registrar('ugs', $ugId, 'CRIADO', [
            'evento_tipo' => 'UG_CRIADA',
            'descricao_evento' => 'Nova UG cadastrada',
            'modulo' => 'ugs',
            'dados_novos' => $dadosUG,
            'dados_contexto' => [
                'numero_uc' => $dadosUG['numero_uc'] ?? null,
                'cnpj' => $dadosUG['cnpj'] ?? null,
                'timestamp' => now()->toISOString()
            ]
        ]);
    }

    /**
     * Registrar evento de envio de termo de adesão
     */
    public static function registrarEnvioTermo(string $propostaId, string $tipoTermo, array $detalhes): bool
    {
        return self::registrar('propostas', $propostaId, 'TERMO_ENVIADO', [
            'evento_tipo' => 'TERMO_ADESAO_ENVIADO',
            'descricao_evento' => "Termo de adesão {$tipoTermo} enviado ao cliente",
            'modulo' => 'propostas',
            'evento_critico' => true,
            'dados_contexto' => [
                'tipo_termo' => $tipoTermo,
                'email_destinatario' => $detalhes['email'] ?? null,
                'arquivo_termo' => $detalhes['arquivo'] ?? null,
                'timestamp' => now()->toISOString()
            ],
            'metadados' => $detalhes
        ]);
    }

    /**
     * Registrar evento de leitura de notificação
     */
    public static function registrarLeituraNotificacao(string $notificacaoId, array $dadosNotificacao): bool
    {
        return self::registrar('notificacoes', $notificacaoId, 'LIDA', [
            'evento_tipo' => 'NOTIFICACAO_LIDA',
            'descricao_evento' => 'Notificação visualizada pelo usuário',
            'modulo' => 'dashboard',
            'dados_contexto' => [
                'titulo_notificacao' => $dadosNotificacao['titulo'] ?? null,
                'tipo_notificacao' => $dadosNotificacao['tipo'] ?? null,
                'timestamp' => now()->toISOString()
            ]
        ]);
    }

    /**
     * Registrar evento de login
     */
    public static function registrarLogin(string $usuarioId, array $dadosLogin = []): bool
    {
        return self::registrarComUsuario('usuarios', $usuarioId, 'LOGIN', $usuarioId, [
            'evento_tipo' => 'USUARIO_LOGIN',
            'descricao_evento' => 'Usuário fez login no sistema',
            'modulo' => 'auth',
            'dados_contexto' => array_merge([
                'timestamp' => now()->toISOString(),
                'navegador' => request()->userAgent() ?? null
            ], $dadosLogin)
        ]);
    }

    /**
     * Registrar evento de logout
     */
    public static function registrarLogout(string $usuarioId): bool
    {
        return self::registrar('usuarios', $usuarioId, 'LOGOUT', [
            'evento_tipo' => 'USUARIO_LOGOUT',
            'descricao_evento' => 'Usuário fez logout do sistema',
            'modulo' => 'auth',
            'dados_contexto' => [
                'timestamp' => now()->toISOString()
            ]
        ]);
    }

    /**
     * Registrar evento de exclusão de proposta
     */
    public static function registrarExclusaoProposta(string $propostaId, array $dadosProposta): bool
    {
        return self::registrar('propostas', $propostaId, 'EXCLUIDO', [
            'evento_tipo' => 'PROPOSTA_EXCLUIDA',
            'descricao_evento' => 'Proposta removida do sistema',
            'modulo' => 'propostas',
            'evento_critico' => true,
            'dados_anteriores' => $dadosProposta,
            'dados_contexto' => [
                'numero_proposta' => $dadosProposta['numero'] ?? null,
                'motivo_exclusao' => 'Exclusão manual pelo usuário',
                'timestamp' => now()->toISOString()
            ]
        ]);
    }

    /**
     * Registrar evento de alteração de status
     */
    public static function registrarMudancaStatusDetalhada(
        string $entidade,
        string $entidadeId,
        string $statusAnterior,
        string $statusNovo,
        string $modulo,
        array $options = []
    ): bool {
        return self::registrar($entidade, $entidadeId, 'ALTERADO', array_merge($options, [
            'sub_acao' => 'MUDANCA_STATUS',
            'evento_tipo' => 'STATUS_ALTERADO',
            'descricao_evento' => "Status alterado de {$statusAnterior} para {$statusNovo}",
            'modulo' => $modulo,
            'dados_anteriores' => ['status' => $statusAnterior],
            'dados_novos' => ['status' => $statusNovo],
            'dados_contexto' => [
                'status_anterior' => $statusAnterior,
                'status_novo' => $statusNovo,
                'timestamp' => now()->toISOString()
            ]
        ]));
    }

    /**
     * Obter eventos por módulo e período
     */
    public static function obterEventosPorModulo(string $modulo, string $dataInicio = null, string $dataFim = null, int $limite = 100): array
    {
        try {
            $query = DB::table('auditoria')
                ->select([
                    'id', 'entidade', 'entidade_id', 'acao', 'sub_acao', 'evento_tipo',
                    'descricao_evento', 'modulo', 'dados_contexto', 'evento_critico',
                    'usuario_id', 'data_acao'
                ])
                ->where('modulo', $modulo);

            if ($dataInicio) {
                $query->where('data_acao', '>=', $dataInicio);
            }

            if ($dataFim) {
                $query->where('data_acao', '<=', $dataFim);
            }

            return $query->orderBy('data_acao', 'desc')
                        ->limit($limite)
                        ->get()
                        ->toArray();

        } catch (\Exception $e) {
            Log::error('Erro ao obter eventos por módulo', [
                'modulo' => $modulo,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Obter eventos críticos recentes
     */
    public static function obterEventosCriticos(int $limite = 50): array
    {
        try {
            return DB::table('auditoria')
                ->select([
                    'id', 'entidade', 'entidade_id', 'acao', 'evento_tipo',
                    'descricao_evento', 'modulo', 'dados_contexto',
                    'usuario_id', 'data_acao'
                ])
                ->where('evento_critico', true)
                ->orderBy('data_acao', 'desc')
                ->limit($limite)
                ->get()
                ->toArray();

        } catch (\Exception $e) {
            Log::error('Erro ao obter eventos críticos', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
}