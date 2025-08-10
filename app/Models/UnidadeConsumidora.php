<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class UnidadeConsumidora extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'unidades_consumidoras';

    protected $fillable = [
        // Relacionamentos
        'usuario_id',
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
        'gerador',
        'geracao_prevista',
        'consumo_medio',
        
        // Modalidades
        'service',
        'project',
        'nexus_clube',
        'nexus_cativo',
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
        'is_ug',
        'nome_usina',
        'potencia_cc',
        'fator_capacidade',
        'capacidade_calculada',
        'localizacao',
        'observacoes_ug',
        'ucs_atribuidas',
        'media_consumo_atribuido'
    ];

    protected $casts = [
        // Booleans
        'mesmo_titular' => 'boolean',
        'gerador' => 'boolean',
        'service' => 'boolean',
        'project' => 'boolean',
        'nexus_clube' => 'boolean',
        'nexus_cativo' => 'boolean',
        'proprietario' => 'boolean',
        'irrigante' => 'boolean',
        'is_ug' => 'boolean',
        
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
        'fator_capacidade' => 'decimal:2',
        'capacidade_calculada' => 'decimal:2',
        'media_consumo_atribuido' => 'decimal:2',
        
        // Integers
        'numero_cliente' => 'integer',
        'numero_unidade' => 'integer',
        'tensao_nominal' => 'integer',
        'ucs_atribuidas' => 'integer',
        
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
        'is_ug' => false,
        'tensao_nominal' => 220,
        'grupo' => 'B',
        'classe' => 'Residencial',
        'subclasse' => 'Residencial',
        'tipo_conexao' => 'Baixa Tensão',
        'estrutura_tarifaria' => 'Convencional',
        'distribuidora' => 'CEMIG',
        'tipo_ligacao' => 'Monofásica'
    ];

    // Relacionamentos
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function concessionaria(): BelongsTo
    {
        return $this->belongsTo(Concessionaria::class, 'concessionaria_id');
    }

    public function endereco(): BelongsTo
    {
        return $this->belongsTo(Endereco::class, 'endereco_id');
    }

    public function proposta(): BelongsTo
    {
        return $this->belongsTo(Proposta::class, 'proposta_id');
    }

    public function controleClube(): HasMany
    {
        return $this->hasMany(ControleClube::class, 'uc_id');
    }

    // Para UGs - UCs atribuídas
    public function ucsAtribuidas(): HasMany
    {
        return $this->hasMany(UnidadeConsumidora::class, 'ug_principal_id');
    }

    public function ugPrincipal(): BelongsTo
    {
        return $this->belongsTo(UnidadeConsumidora::class, 'ug_principal_id');
    }

    // Scopes
    public function scopeUCs($query)
    {
        return $query->where('is_ug', false);
    }

    public function scopeUGs($query)
    {
        return $query->where('is_ug', true);
    }

    public function scopeAtivas($query)
    {
        return $query->whereNull('deleted_at');
    }

    public function scopePorUsuario($query, $usuarioId)
    {
        return $query->where('usuario_id', $usuarioId);
    }

    public function scopePorProposta($query, $propostaId)
    {
        return $query->where('proposta_id', $propostaId);
    }

    public function scopeGeradoras($query)
    {
        return $query->where('gerador', true);
    }

    public function scopeConsumidoras($query)
    {
        return $query->where('gerador', false);
    }

    public function scopeNexusClube($query)
    {
        return $query->where('nexus_clube', true);
    }

    public function scopeNexusCativo($query)
    {
        return $query->where('nexus_cativo', true);
    }

    public function scopePorDistribuidora($query, $distribuidora)
    {
        return $query->where('distribuidora', $distribuidora);
    }

    public function scopeComFiltroHierarquico($query, Usuario $usuario)
    {
        if ($usuario->isAdmin()) {
            return $query; // Admin vê tudo
        }
        
        if ($usuario->isConsultor()) {
            $subordinados = $usuario->getAllSubordinates();
            $usuariosPermitidos = array_merge([$usuario->id], array_column($subordinados, 'id'));
            return $query->whereIn('usuario_id', $usuariosPermitidos);
        }
        
        // Gerente e Vendedor veem apenas suas próprias UCs
        return $query->where('usuario_id', $usuario->id);
    }

    // Accessors
    public function getTipoDisplayAttribute(): string
    {
        if ($this->is_ug) {
            return '🏭 Usina Geradora (UG)';
        }
        
        if ($this->gerador) {
            return '⚡ Unidade Geradora';
        }
        
        return '🏢 Unidade Consumidora';
    }

    public function getStatusDisplayAttribute(): string
    {
        if ($this->nexus_clube && $this->nexus_cativo) {
            return '🔄 Nexus Clube + Cativo';
        }
        
        if ($this->nexus_clube) {
            return '🌐 Nexus Clube';
        }
        
        if ($this->nexus_cativo) {
            return '🏭 Nexus Cativo';
        }
        
        if ($this->service) {
            return '🔧 Service';
        }
        
        if ($this->project) {
            return '📋 Project';
        }
        
        return '📊 Padrão';
    }

    public function getConsumoFormatadoAttribute(): string
    {
        return number_format($this->consumo_medio, 2, ',', '.') . ' kWh';
    }

    public function getGeracaoFormatadaAttribute(): string
    {
        return number_format($this->geracao_prevista ?? 0, 2, ',', '.') . ' kWh';
    }

    public function getValorFaturaFormatadoAttribute(): string
    {
        return 'R$ ' . number_format($this->valor_fatura ?? 0, 2, ',', '.');
    }

    public function getPotenciaCcFormatadaAttribute(): string
    {
        return number_format($this->potencia_cc ?? 0, 2, ',', '.') . ' kWp';
    }

    public function getCapacidadeCalculadaFormatadaAttribute(): string
    {
        return number_format($this->capacidade_calculada ?? 0, 2, ',', '.') . ' kWh';
    }

    public function getFatorCapacidadeFormatadoAttribute(): string
    {
        return number_format($this->fator_capacidade ?? 0, 2, ',', '.') . '%';
    }

    // Métodos de Negócio
    public function calcularCapacidadeUG(): float
    {
        if (!$this->is_ug || !$this->potencia_cc || !$this->fator_capacidade) {
            return 0;
        }
        
        // Fórmula: 720h * Potência CC * (Fator Capacidade / 100)
        return 720 * $this->potencia_cc * ($this->fator_capacidade / 100);
    }

    public function aplicarCalibragem(float $percentualCalibragem): void
    {
        if ($this->consumo_medio) {
            $fatorCalibragem = 1 + ($percentualCalibragem / 100);
            $this->consumo_medio = $this->consumo_medio * $fatorCalibragem;
            $this->calibragem_percentual = $percentualCalibragem;
        }
    }

    public function vincularProposta(Proposta $proposta): bool
    {
        $this->proposta_id = $proposta->id;
        return $this->save();
    }

    public function desvincularProposta(): bool
    {
        $this->proposta_id = null;
        return $this->save();
    }

    public function converterParaUG(array $dadosUG): bool
    {
        $this->is_ug = true;
        $this->nome_usina = $dadosUG['nome_usina'] ?? null;
        $this->potencia_cc = $dadosUG['potencia_cc'] ?? null;
        $this->fator_capacidade = $dadosUG['fator_capacidade'] ?? null;
        $this->localizacao = $dadosUG['localizacao'] ?? null;
        $this->observacoes_ug = $dadosUG['observacoes_ug'] ?? null;
        
        // Calcular capacidade automaticamente
        $this->capacidade_calculada = $this->calcularCapacidadeUG();
        
        return $this->save();
    }

    public function reverterParaUC(): bool
    {
        $this->is_ug = false;
        $this->nome_usina = null;
        $this->potencia_cc = null;
        $this->fator_capacidade = null;
        $this->capacidade_calculada = null;
        $this->localizacao = null;
        $this->observacoes_ug = null;
        $this->ucs_atribuidas = 0;
        $this->media_consumo_atribuido = null;
        
        return $this->save();
    }

    // Validações
    public function isValidForUG(): array
    {
        $errors = [];
        
        if (!$this->is_ug) {
            return $errors;
        }
        
        if (empty($this->nome_usina)) {
            $errors[] = 'Nome da usina é obrigatório para UGs';
        }
        
        if (empty($this->potencia_cc) || $this->potencia_cc <= 0) {
            $errors[] = 'Potência CC deve ser maior que zero';
        }
        
        if (empty($this->fator_capacidade) || $this->fator_capacidade <= 0 || $this->fator_capacidade > 100) {
            $errors[] = 'Fator de capacidade deve estar entre 0 e 100%';
        }
        
        return $errors;
    }

    // Estatísticas
    public static function getEstatisticas($filtros = [])
    {
        $query = self::query();
        
        if (isset($filtros['usuario_id'])) {
            $query->where('usuario_id', $filtros['usuario_id']);
        }
        
        if (isset($filtros['usuario']) && $filtros['usuario'] instanceof Usuario) {
            $query->comFiltroHierarquico($filtros['usuario']);
        }
        
        return [
            'total_ucs' => $query->clone()->UCs()->count(),
            'total_ugs' => $query->clone()->UGs()->count(),
            'total_geradoras' => $query->clone()->geradoras()->count(),
            'total_nexus_clube' => $query->clone()->nexusClube()->count(),
            'total_nexus_cativo' => $query->clone()->nexusCativo()->count(),
            'consumo_total' => $query->clone()->UCs()->sum('consumo_medio'),
            'geracao_total' => $query->clone()->geradoras()->sum('geracao_prevista'),
            'capacidade_ugs_total' => $query->clone()->UGs()->sum('capacidade_calculada'),
            'potencia_total_ugs' => $query->clone()->UGs()->sum('potencia_cc')
        ];
    }

    // Boot method para eventos
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($uc) {
            // Auto-calcular capacidade para UGs
            if ($uc->is_ug) {
                $uc->capacidade_calculada = $uc->calcularCapacidadeUG();
            }
        });

        static::updating(function ($uc) {
            // Recalcular capacidade para UGs quando necessário
            if ($uc->is_ug && ($uc->isDirty('potencia_cc') || $uc->isDirty('fator_capacidade'))) {
                $uc->capacidade_calculada = $uc->calcularCapacidadeUG();
            }
        });
    }
}