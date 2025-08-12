<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class Proposta extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'propostas';

    protected $fillable = [
        'numero_proposta',
        'data_proposta',
        'nome_cliente',
        'consultor',
        'usuario_id',
        'recorrencia',
        'economia',
        'bandeira', 
        'status',
        'observacoes',
        'beneficios',
        // ✅ ADICIONAR ESTAS LINHAS
        'telefone',
        'email',
        'endereco',
        'unidades_consumidoras',
        'numero_uc',
        'apelido',
        'media_consumo',
        'ligacao',
        'distribuidora',
    ];

    protected $casts = [
        'data_proposta' => 'date',
        'economia' => 'decimal:2',
        'bandeira' => 'decimal:2',
        'beneficios' => 'array',
        'unidades_consumidoras' => 'array', // ✅ ADICIONAR
        'media_consumo' => 'decimal:2',     // ✅ ADICIONAR
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $attributes = [
        'recorrencia' => '3%',
        'economia' => 20.00,
        'bandeira' => 20.00,
        'status' => 'Aguardando'
    ];

    // Boot method para eventos do modelo
    protected static function boot()
    {
        parent::boot();

        // Garantir que beneficios seja sempre um array
        static::creating(function ($proposta) {
            if (empty($proposta->beneficios)) {
                $proposta->beneficios = [];
            }
        });

        static::updating(function ($proposta) {
            if (empty($proposta->beneficios)) {
                $proposta->beneficios = [];
            }
        });
    }

    // ========================================
    // RELACIONAMENTOS
    // ========================================

    /**
     * Usuário que criou a proposta
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    /**
     * Registros no controle clube associados
     */
    public function controleClube(): HasMany
    {
        return $this->hasMany(ControleClube::class, 'proposta_id');
    }

    /**
     * Unidades consumidoras associadas
     */
    public function unidadesConsumidoras(): HasMany
    {
        return $this->hasMany(UnidadeConsumidora::class, 'proposta_id');
    }

    // ========================================
    // SCOPES
    // ========================================

    /**
     * Propostas aguardando
     */
    public function scopeAguardando($query)
    {
        return $query->where('status', 'Aguardando');
    }

    /**
     * Propostas fechadas
     */
    public function scopeFechado($query)
    {
        return $query->where('status', 'Fechado');
    }

    /**
     * Propostas perdidas
     */
    public function scopePerdido($query)
    {
        return $query->where('status', 'Perdido');
    }

    /**
     * Propostas não fechadas
     */
    public function scopeNaoFechado($query)
    {
        return $query->where('status', 'Não Fechado');
    }

    /**
     * Propostas canceladas
     */
    public function scopeCancelado($query)
    {
        return $query->where('status', 'Cancelado');
    }

    /**
     * Filtrar por consultor
     */
    public function scopePorConsultor($query, $consultor)
    {
        return $query->where('consultor', 'ilike', "%{$consultor}%");
    }

    /**
     * Filtrar por período de data
     */
    public function scopePorPeriodo($query, $dataInicio, $dataFim)
    {
        return $query->whereBetween('data_proposta', [
            Carbon::parse($dataInicio)->startOfDay(),
            Carbon::parse($dataFim)->endOfDay()
        ]);
    }

    /**
     * Filtrar por usuário
     */
    public function scopePorUsuario($query, $usuarioId)
    {
        return $query->where('usuario_id', $usuarioId);
    }

    /**
     * Filtro hierárquico baseado no usuário logado
     */
    public function scopeComFiltroHierarquico($query, Usuario $usuario)
    {
        if ($usuario->role === 'admin') {
            // Admin vê todas as propostas
            return $query;
        }

        if ($usuario->role === 'manager') {
            // Manager vê suas propostas + propostas da equipe
            $subordinados = Usuario::where('manager_id', $usuario->id)->pluck('id')->toArray();
            $usuariosPermitidos = array_merge([$usuario->id], $subordinados);
            return $query->whereIn('usuario_id', $usuariosPermitidos);
        }

        // Usuário comum vê apenas suas propostas
        return $query->where('usuario_id', $usuario->id);
    }

    /**
     * Busca textual
     */
    public function scopeBusca($query, $termo)
    {
        return $query->where(function ($q) use ($termo) {
            $q->where('nome_cliente', 'ilike', "%{$termo}%")
              ->orWhere('numero_proposta', 'ilike', "%{$termo}%")
              ->orWhere('consultor', 'ilike', "%{$termo}%");
        });
    }

    // ========================================
    // ACCESSORS & MUTATORS
    // ========================================

    /**
     * Formatar economia com percentual
     */
    public function getEconomiaFormatadaAttribute(): string
    {
        return number_format($this->economia, 1) . '%';
    }

    /**
     * Formatar bandeira com percentual
     */
    public function getBandeiraFormatadaAttribute(): string
    {
        return number_format($this->bandeira, 1) . '%';
    }

    /**
     * Data formatada em pt-BR
     */
    public function getDataPropostaFormatadaAttribute(): string
    {
        return $this->data_proposta ? $this->data_proposta->format('d/m/Y') : '';
    }

    /**
     * Status com cor para frontend
     */
    public function getStatusComCorAttribute(): array
    {
        $cores = [
            'Aguardando' => '#f59e0b',
            'Fechado' => '#10b981', 
            'Perdido' => '#ef4444',
            'Não Fechado' => '#6b7280',
            'Cancelado' => '#64748b'
        ];

        return [
            'status' => $this->status,
            'cor' => $cores[$this->status] ?? '#6b7280'
        ];
    }

    /**
     * Garantir que beneficios seja sempre um array
     */
    public function setBeneficiosAttribute($value)
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $this->attributes['beneficios'] = json_encode($decoded ?: []);
        } elseif (is_array($value)) {
            $this->attributes['beneficios'] = json_encode($value);
        } elseif (is_null($value)) {  // ✅ ADICIONE ESTA LINHA
            $this->attributes['beneficios'] = json_encode([]);
        } else {
            $this->attributes['beneficios'] = json_encode([]);
        }
    }

    /**
     * Sempre retornar beneficios como array
     */
    public function getBeneficiosAttribute($value)
    {
        if (empty($value) || is_null($value)) {
            return [];
        }

        // Se já for um array, retornar diretamente
        if (is_array($value)) {  // ✅ ADICIONE ESTA VERIFICAÇÃO
            return $value;
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    // ========================================
    // MÉTODOS AUXILIARES
    // ========================================

    /**
     * Verificar se proposta pode ser editada
     */
    public function podeSerEditada(): bool
    {
        return !in_array($this->status, ['Fechado', 'Cancelado']);
    }

    /**
     * Verificar se proposta está ativa
     */
    public function estaAtiva(): bool
    {
        return in_array($this->status, ['Aguardando', 'Fechado']);
    }

    /**
     * Obter próximo status sugerido
     */
    public function getProximoStatusSugerido(): array
    {
        $proximosStatus = [
            'Aguardando' => ['Fechado', 'Perdido', 'Não Fechado'],
            'Fechado' => ['Cancelado'],
            'Perdido' => ['Aguardando'],
            'Não Fechado' => ['Aguardando', 'Perdido'],
            'Cancelado' => ['Aguardando']
        ];

        return $proximosStatus[$this->status] ?? [];
    }

    /**
     * Calcular idade da proposta em dias
     */
    public function getIdadeDias(): int
    {
        return $this->created_at->diffInDays(now());
    }

    /**
     * Verificar se proposta está atrasada (mais de 30 dias sem fechamento)
     */
    public function estaAtrasada(): bool
    {
        return $this->status === 'Aguardando' && $this->getIdadeDias() > 30;
    }

    /**
     * Obter resumo da proposta para listagem
     */
    public function getResumo(): array
    {
        return [
            'id' => $this->id,
            'numero_proposta' => $this->numero_proposta,
            'nome_cliente' => $this->nome_cliente,
            'consultor' => $this->consultor,
            'data_proposta' => $this->data_proposta_formatada,
            'status' => $this->status_com_cor,
            'economia' => $this->economia_formatada,
            'idade_dias' => $this->getIdadeDias(),
            'atrasada' => $this->estaAtrasada(),
            'pode_editar' => $this->podeSerEditada()
        ];
    }
    /**
     * Converter dados para formato esperado pelo frontend
     */
    public function toFrontendFormat(): array
    {
        return [
            'id' => $this->id,
            'numeroProposta' => $this->numero_proposta,
            'nomeCliente' => $this->nome_cliente,
            'consultor' => $this->consultor,
            'data' => $this->data_proposta?->format('Y-m-d'),
            'status' => $this->status,
            'economia' => $this->economia,
            'bandeira' => $this->bandeira,
            'recorrencia' => $this->recorrencia,
            'observacoes' => $this->observacoes,
            'beneficios' => $this->beneficios ?: [],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            // Campos adicionais para compatibilidade
            'numeroUC' => null,
            'apelido' => null,
            'media' => null,
        ];
    }
    
    /**
     * Scope para ordenação padrão
     */
    public function scopeOrdenacaoPadrao($query)
    {
        return $query->orderBy('created_at', 'desc');
    }
}