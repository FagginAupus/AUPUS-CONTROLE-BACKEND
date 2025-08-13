<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Proposta extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * Indicar que utilizamos UUID ao invés de incremento automático
     */
    public $incrementing = false;
    protected $keyType = 'string';

    /**
     * Nome da tabela
     */
    protected $table = 'propostas';

    /**
     * Campos que podem ser preenchidos via mass assignment
     */
    protected $fillable = [
        'id',
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
        'unidades_consumidoras',
        'created_at',
        'updated_at',
        'deleted_at'
    ];

    /**
     * Campos que devem ser convertidos para outros tipos
     */
    protected $casts = [
        'id' => 'string',
        'data_proposta' => 'date',
        'economia' => 'decimal:2',
        'bandeira' => 'decimal:2',
        'beneficios' => 'json',
        'unidades_consumidoras' => 'json',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime'
    ];

    /**
     * Campos protegidos (não serão mostrados em JSON)
     */
    protected $hidden = [
        'deleted_at'
    ];

    /**
     * Valores padrão para novos modelos
     */
    protected $attributes = [
        'recorrencia' => '3%',
        'economia' => 20.00,
        'bandeira' => 20.00,
        'status' => 'Em Análise'
    ];

    /**
     * Status válidos para validação
     */
    public const STATUS_OPCOES = [
        'Em Análise',
        'Aguardando',
        'Fechado',
        'Perdido'
    ];

    /**
     * ===================================
     * RELACIONAMENTOS
     * ===================================
     */

    /**
     * Relacionamento com Usuário (quem criou a proposta)
     */
    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id', 'id');
    }

    /**
     * Relacionamento com Controle Clube
     */
    public function controles()
    {
        return $this->hasMany(ControleClube::class, 'proposta_id', 'id');
    }

    /**
     * ===================================
     * SCOPES
     * ===================================
     */

    /**
     * Scope para filtrar por status
     */
    public function scopeComStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope para filtrar por consultor
     */
    public function scopeDoConsultor($query, $consultor)
    {
        return $query->where('consultor', 'ILIKE', '%' . $consultor . '%');
    }

    /**
     * Scope para filtrar por cliente
     */
    public function scopeDoCliente($query, $cliente)
    {
        return $query->where('nome_cliente', 'ILIKE', '%' . $cliente . '%');
    }

    /**
     * Scope para filtrar por período
     */
    public function scopeEntreDatas($query, $dataInicio, $dataFim)
    {
        return $query->whereBetween('data_proposta', [$dataInicio, $dataFim]);
    }

    /**
     * Scope para filtrar por usuário
     */
    public function scopeDoUsuario($query, $usuarioId)
    {
        return $query->where('usuario_id', $usuarioId);
    }

    /**
     * ===================================
     * MUTATORS & ACCESSORS
     * ===================================
     */

    /**
     * Accessor para formatar benefícios
     */
    public function getBeneficiosAttribute($value)
    {
        if (is_string($value)) {
            return json_decode($value, true) ?: [];
        }
        return $value ?: [];
    }

    /**
     * Mutator para salvar benefícios
     */
    public function setBeneficiosAttribute($value)
    {
        $this->attributes['beneficios'] = is_array($value) ? json_encode($value) : $value;
    }

    /**
     * Accessor para formatar unidades consumidoras
     */
    public function getUnidadesConsumidorasAttribute($value)
    {
        if (is_string($value)) {
            return json_decode($value, true) ?: [];
        }
        return $value ?: [];
    }

    /**
     * Mutator para salvar unidades consumidoras
     */
    public function setUnidadesConsumidorasAttribute($value)
    {
        $this->attributes['unidades_consumidoras'] = is_array($value) ? json_encode($value) : $value;
    }

    /**
     * Accessor para formatar economia com símbolo %
     */
    public function getEconomiaFormatadaAttribute()
    {
        return $this->economia . '%';
    }

    /**
     * Accessor para formatar bandeira com símbolo %
     */
    public function getBandeiraFormatadaAttribute()
    {
        return $this->bandeira . '%';
    }

    /**
     * ===================================
     * MÉTODOS AUXILIARES
     * ===================================
     */

    /**
     * Verificar se a proposta está fechada
     */
    public function esteFechada(): bool
    {
        return $this->status === 'Fechado';
    }

    /**
     * Verificar se a proposta está perdida
     */
    public function estePerdida(): bool
    {
        return $this->status === 'Perdido';
    }

    /**
     * Verificar se a proposta está em análise
     */
    public function esteEmAnalise(): bool
    {
        return $this->status === 'Em Análise';
    }

    /**
     * Verificar se a proposta está aguardando
     */
    public function esteAguardando(): bool
    {
        return $this->status === 'Aguardando';
    }

    /**
     * Obter quantidade de unidades consumidoras
     */
    public function getQuantidadeUcsAttribute(): int
    {
        return count($this->unidades_consumidoras);
    }

    /**
     * Obter consumo total das UCs
     */
    public function getConsumoTotalAttribute(): float
    {
        $total = 0;
        foreach ($this->unidades_consumidoras as $uc) {
            $total += $uc['consumo_medio'] ?? 0;
        }
        return $total;
    }

    /**
     * Obter cor do status para UI
     */
    public function getCorStatusAttribute(): string
    {
        return match($this->status) {
            'Em Análise' => 'orange',
            'Aguardando' => 'blue',
            'Fechado' => 'green',
            'Perdido' => 'red',
            default => 'gray'
        };
    }

    /**
     * Verificar se pode ser editada
     */
    public function podeSerEditada(): bool
    {
        return in_array($this->status, ['Em Análise', 'Aguardando']);
    }

    /**
     * Verificar se pode ser excluída
     */
    public function podeSerExcluida(): bool
    {
        return !$this->esteFechada();
    }

    /**
     * ===================================
     * QUERY BUILDERS
     * ===================================
     */

    /**
     * Obter propostas ativas (não excluídas)
     */
    public static function ativas()
    {
        return static::whereNull('deleted_at');
    }

    /**
     * Obter propostas fechadas
     */
    public static function fechadas()
    {
        return static::where('status', 'Fechado');
    }

    /**
     * Obter propostas em análise
     */
    public static function emAnalise()
    {
        return static::where('status', 'Em Análise');
    }

    /**
     * Obter propostas do mês atual
     */
    public static function doMesAtual()
    {
        return static::whereMonth('data_proposta', now()->month)
                    ->whereYear('data_proposta', now()->year);
    }

    /**
     * Obter propostas do ano atual
     */
    public static function doAnoAtual()
    {
        return static::whereYear('data_proposta', now()->year);
    }

    /**
     * ===================================
     * EVENTOS DO MODEL
     * ===================================
     */

    /**
     * Boot do modelo
     */
    protected static function boot()
    {
        parent::boot();

        // Antes de criar
        static::creating(function ($proposta) {
            if (empty($proposta->id)) {
                $proposta->id = (string) \Illuminate\Support\Str::uuid();
            }
            
            // Se não tem número da proposta, gerar automaticamente
            if (empty($proposta->numero_proposta)) {
                $ano = date('Y');
                $proximoNumero = static::whereYear('created_at', $ano)->count() + 1;
                $proposta->numero_proposta = sprintf('%s/%03d', $ano, $proximoNumero);
            }
        });

        // Antes de atualizar
        static::updating(function ($proposta) {
            // Log das mudanças importantes
            if ($proposta->isDirty('status')) {
                \Log::info('Status da proposta alterado', [
                    'proposta_id' => $proposta->id,
                    'numero_proposta' => $proposta->numero_proposta,
                    'status_anterior' => $proposta->getOriginal('status'),
                    'status_novo' => $proposta->status,
                    'usuario_id' => auth()->user()->id ?? 'sistema'
                ]);
            }
        });
    }

    /**
     * ===================================
     * SERIALIZAÇÃO
     * ===================================
     */

    /**
     * Definir como o modelo deve ser serializado para JSON
     */
    public function toArray()
    {
        $array = parent::toArray();
        
        // Adicionar campos calculados
        $array['quantidade_ucs'] = $this->quantidade_ucs;
        $array['consumo_total'] = $this->consumo_total;
        $array['cor_status'] = $this->cor_status;
        $array['economia_formatada'] = $this->economia_formatada;
        $array['bandeira_formatada'] = $this->bandeira_formatada;
        $array['pode_ser_editada'] = $this->podeSerEditada();
        $array['pode_ser_excluida'] = $this->podeSerExcluida();
        
        return $array;
    }
}