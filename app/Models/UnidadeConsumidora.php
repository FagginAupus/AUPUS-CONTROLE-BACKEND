<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids; // ✅ MUDANÇA
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class UnidadeConsumidora extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $table = 'unidades_consumidoras';

    protected $fillable = [
        // Relacionamentos
        'usuario_id',
        'associado_id',
        'concessionaria_id',
        'endereco_id',
        'proposta_id',
        
        // Dados básicos
        'mesmo_titular',
        'nome_titular_diferente',
        'apelido',
        'numero_cliente',
        'numero_unidade',
        'tipo',
        
        // Geração e consumo
        'gerador', // MANTIDO: campo correto
        'geracao_prevista',
        'consumo_medio',
        
        // Modalidades
        'service',
        'project',
        'nexus_clube', // ADICIONADO: campo necessário
        'nexus_cativo', // ADICIONADO: campo necessário
        'proprietario',
        
        // Dados técnicos
        'tensao_nominal',
        'grupo',
        'ligacao',
        'irrigante',
        'valor_beneficio_irrigante',
        'calibragem_percentual',
        'relacao_te',
        'classe',
        'subclasse',
        'tipo_conexao',
        'estrutura_tarifaria',
        'contrato',
        'vencimento_contrato',
        'demanda_geracao',
        'demanda_consumo',
        'desconto_fatura',
        'desconto_bandeira',
        
        // Novos campos
        'distribuidora',
        'tipo_ligacao',
        'valor_fatura',
        'nome_usina',
        'potencia_cc',
        'potencia_ca',
        'fator_capacidade',
        'capacidade_calculada',
        'localizacao',
        'observacoes_ug',
        'deleted_by' // ADICIONADO: para soft delete
    ];

    protected $casts = [
        // Booleans - CORRIGIDOS
        'mesmo_titular' => 'boolean',
        'gerador' => 'boolean', // MANTIDO: campo correto
        'service' => 'boolean',
        'project' => 'boolean',
        'nexus_clube' => 'boolean', // ADICIONADO
        'nexus_cativo' => 'boolean', // ADICIONADO
        'proprietario' => 'boolean',
        'irrigante' => 'boolean',
        // REMOVIDO: 'is_ug' => 'boolean', // Campo removido
        
        // Decimals
        'geracao_prevista' => 'decimal:2',
        'consumo_medio' => 'decimal:2',
        'valor_beneficio_irrigante' => 'decimal:2',
        'calibragem_percentual' => 'decimal:2',
        'relacao_te' => 'decimal:2',
        'demanda_geracao' => 'decimal:2',
        'demanda_consumo' => 'decimal:2',
        'desconto_fatura' => 'decimal:2',
        'desconto_bandeira' => 'decimal:2',
        'valor_fatura' => 'decimal:2',
        'potencia_cc' => 'decimal:2',
        'potencia_ca'=> 'decimal:2',
        'fator_capacidade' => 'decimal:2',
        'capacidade_calculada' => 'decimal:2',
        
        // Integers
        'numero_cliente' => 'integer',
        'numero_unidade' => 'integer',
        'tensao_nominal' => 'integer',
        
        // Dates
        'vencimento_contrato' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $attributes = [
        'mesmo_titular' => true,
        'gerador' => false, 
        'service' => false,
        'project' => false,
        'nexus_clube' => false, 
        'nexus_cativo' => false, 
        'proprietario' => true,
        'irrigante' => false,
        'tensao_nominal' => 220,
        'grupo' => 'B',
        'classe' => 'Residencial',
        'subclasse' => 'Residencial',
        'tipo_conexao' => 'Baixa Tensão',
        'estrutura_tarifaria' => 'Convencional',
    ];

    protected $hidden = [
        'deleted_at',
        'deleted_by',
    ];

    /**
     * Relacionamento com Usuario (dono da unidade)
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id', 'id');
    }

    /**
     * Relacionamento com Proposta
     */
    public function proposta(): BelongsTo
    {
        return $this->belongsTo(Proposta::class, 'proposta_id', 'id');
    }

    /**
     * Relacionamento com Associado
     */
    public function associado(): BelongsTo
    {
        return $this->belongsTo(Associado::class, 'associado_id', 'id');
    }

    /**
     * Relacionamento com ControleClube (quando é UC)
     */
    public function controles(): HasMany
    {
        return $this->hasMany(ControleClube::class, 'uc_id', 'id');
    }

    /**
     * Relacionamento com ControleClube (quando é UG)
     */
    public function controlesUG(): HasMany
    {
        return $this->hasMany(ControleClube::class, 'ug_id', 'id');
    }

    // ========================================
    // SCOPES
    // ========================================

    /**
     * Scope para filtrar apenas UCs (Unidades Consumidoras)
     */
    public function scopeUCs($query)
    {
        return $query->where('gerador', false); // CORRIGIDO
    }

    /**
     * Scope para filtrar apenas UGs (Usinas Geradoras) do clube
     */
    public function scopeUGs($query)
    {
        return $query->where('gerador', true)
                    ->where('nexus_clube', true); // CORRIGIDO: lógica completa
    }

    /**
     * Scope para filtrar por modalidade
     */
    public function scopePorModalidade($query, $modalidade)
    {
        return match($modalidade) {
            'nexus_clube' => $query->where('nexus_clube', true),
            'nexus_cativo' => $query->where('nexus_cativo', true),
            'service' => $query->where('service', true),
            'project' => $query->where('project', true),
            'gerador' => $query->where('gerador', true),
            default => $query
        };
    }

    /**
     * Scope para filtrar por distribuidora
     */
    public function scopePorDistribuidora($query, $distribuidora)
    {
        return $query->where('distribuidora', 'ILIKE', '%' . $distribuidora . '%');
    }

    /**
     * Scope para unidades do usuário
     */
    public function scopeDoUsuario($query, $usuarioId)
    {
        return $query->where('usuario_id', $usuarioId);
    }

    /**
     * Scope para unidades da concessionária
     */
    public function scopeDaConcessionaria($query, $concessionariaId)
    {
        return $query->where('concessionaria_id', $concessionariaId);
    }

    // ========================================
    // ACCESSORS & MUTATORS
    // ========================================

    /**
     * Accessor: Verificar se é UG
     */
    public function getIsUgAttribute(): bool
    {
        return $this->gerador && $this->nexus_clube; // CORRIGIDO: lógica completa
    }

    /**
     * Accessor: Obter modalidades ativas
     */
    public function getModalidadesAtivasAttribute(): array
    {
        $modalidades = [];
        
        if ($this->nexus_clube) $modalidades[] = 'Nexus Clube';
        if ($this->nexus_cativo) $modalidades[] = 'Nexus Cativo';
        if ($this->service) $modalidades[] = 'Service';
        if ($this->project) $modalidades[] = 'Project';
        if ($this->gerador) $modalidades[] = 'Gerador';
        
        return $modalidades;
    }

    /**
     * Accessor: Capacidade formatada
     */
    public function getCapacidadeFormatadaAttribute(): string
    {
        if (!$this->capacidade_calculada) {
            return 'N/A';
        }
        
        // CORREÇÃO: Não usar separador de milhares para evitar confusão
        return number_format($this->capacidade_calculada, 0, ',', '') . ' kWh/mês';
    }

    /**
     * Accessor: Potência formatada
     */
    public function getPotenciaFormatadaAttribute(): string
    {
        if (!$this->potencia_cc) {
            return 'N/A';
        }
        
        return number_format($this->potencia_cc, 2, ',', '.') . ' kWp';
    }

    /**
     * Accessor: Consumo médio formatado
     */
    public function getConsumoMedioFormatadoAttribute(): string
    {
        if (!$this->consumo_medio) {
            return 'N/A';
        }
        
        return number_format($this->consumo_medio, 0, ',', '.') . ' kWh';
    }

    // ========================================
    // MÉTODOS DE NEGÓCIO
    // ========================================

    /**
     * Verificar se pode ser UG (tem os campos necessários)
     */
    public function podeSerUG(): bool
    {
        return !empty($this->nome_usina) && 
               $this->potencia_cc > 0 && 
               $this->fator_capacidade > 0;
    }

    /**
     * Calcular capacidade automaticamente
     */
    public function calcularCapacidade(): float
    {
        if (!$this->potencia_cc || !$this->fator_capacidade) {
            return 0;
        }
        
        return 720 * $this->potencia_cc * ($this->fator_capacidade);
    }

    /**
     * Atualizar capacidade calculada
     */
    public function atualizarCapacidade(): bool
    {
        if ($this->gerador && $this->podeSerUG()) {
            $this->capacidade_calculada = $this->calcularCapacidade();
            return $this->save();
        }
        
        return false;
    }

    /**
     * Verificar se tem controle ativo
     */
    public function temControleAtivo(): bool
    {
        return $this->controles()->whereNull('deleted_at')->exists();
    }

    /**
     * Obter estatísticas da UG (se for uma)
     */
    public function getEstatisticasUG(): array
    {
        if (!$this->gerador) {
            return [];
        }
        
        return [
            'nome_usina' => $this->nome_usina,
            'potencia_cc' => $this->potencia_cc,
            'fator_capacidade' => $this->fator_capacidade,
            'capacidade_calculada' => $this->capacidade_calculada,
            'capacidade_formatada' => $this->capacidade_formatada,
            'potencia_formatada' => $this->potencia_formatada,
        ];
    }

    // ========================================
    // EVENTOS DO MODEL
    // ========================================

    protected static function booted()
    {
        // Ao criar uma nova unidade
        static::creating(function ($unidade) {
            // Se for UG, calcular capacidade automaticamente
            if ($unidade->gerador && $unidade->podeSerUG()) {
                $unidade->capacidade_calculada = $unidade->calcularCapacidade();
            }
        });

        // Ao atualizar uma unidade
        static::updating(function ($unidade) {
            // Se mudou dados de UG, recalcular capacidade
            if ($unidade->gerador && $unidade->isDirty(['potencia_cc', 'fator_capacidade'])) {
                $unidade->capacidade_calculada = $unidade->calcularCapacidade();
            }
        });
    }
}