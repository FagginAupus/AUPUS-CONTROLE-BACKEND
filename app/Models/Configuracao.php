<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class Configuracao extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'configuracoes';

    protected $fillable = [
        'chave',
        'valor',
        'tipo',
        'descricao',
        'grupo',
        'updated_by'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Relacionamento com usuário que fez a alteração
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'updated_by');
    }

    // Scopes
    public function scopePorGrupo($query, $grupo)
    {
        return $query->where('grupo', $grupo);
    }

    public function scopePorTipo($query, $tipo)
    {
        return $query->where('tipo', $tipo);
    }

    public function scopePorChave($query, $chave)
    {
        return $query->where('chave', $chave);
    }

    // Accessors
    public function getValorTipado()
    {
        return match($this->tipo) {
            'number' => (float) $this->valor,
            'boolean' => filter_var($this->valor, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode($this->valor, true),
            default => $this->valor
        };
    }

    public function getValorFormatado(): string
    {
        return match($this->tipo) {
            'number' => number_format((float) $this->valor, 2, ',', '.'),
            'boolean' => $this->getValorTipado() ? 'Sim' : 'Não',
            'json' => 'Dados estruturados',
            default => (string) $this->valor
        };
    }

    // Métodos estáticos para configurações frequentes
    public static function getCalibragemGlobal(): float
    {
        return Cache::remember('config_calibragem_global', 3600, function () {
            return self::getValor('calibragem_global', 0.0);
        });
    }

    public static function getEconomiaPadrao(): float
    {
        return Cache::remember('config_economia_padrao', 3600, function () {
            return self::getValor('economia_padrao', 20.0);
        });
    }

    public static function getBandeiraPadrao(): float
    {
        return Cache::remember('config_bandeira_padrao', 3600, function () {
            return self::getValor('bandeira_padrao', 20.0);
        });
    }

    public static function getRecorrenciaPadrao(): string
    {
        return Cache::remember('config_recorrencia_padrao', 3600, function () {
            return self::getValor('recorrencia_padrao', '3%');
        });
    }

    public static function getBeneficiosPadrao(): array
    {
        return Cache::remember('config_beneficios_padrao', 3600, function () {
            return self::getValor('beneficios_padrao', []);
        });
    }

    public static function getEmpresaNome(): string
    {
        return Cache::remember('config_empresa_nome', 3600, function () {
            return self::getValor('empresa_nome', 'Aupus Energia');
        });
    }

    public static function getSistemaVersao(): string
    {
        return Cache::remember('config_sistema_versao', 3600, function () {
            return self::getValor('sistema_versao', '2.0');
        });
    }

    // Método genérico para obter valores
    public static function getValor(string $chave, $valorPadrao = null)
    {
        $config = self::where('chave', $chave)->first();
        
        if (!$config) {
            return $valorPadrao;
        }
        
        return $config->getValorTipado();
    }

    // Método genérico para definir valores
    public static function setValor(string $chave, $valor, Usuario $usuario = null, string $tipo = 'string'): bool
    {
        // Validar tipo
        if (!in_array($tipo, ['string', 'number', 'boolean', 'json'])) {
            throw new \InvalidArgumentException("Tipo '{$tipo}' não é válido");
        }

        // Converter valor baseado no tipo
        $valorProcessado = match($tipo) {
            'json' => is_string($valor) ? $valor : json_encode($valor),
            'boolean' => $valor ? '1' : '0',
            'number' => (string) $valor,
            default => (string) $valor
        };

        $config = self::updateOrCreate(
            ['chave' => $chave],
            [
                'valor' => $valorProcessado,
                'tipo' => $tipo,
                'updated_by' => $usuario?->id
            ]
        );

        // Limpar cache
        Cache::forget("config_{$chave}");
        self::limparCacheGrupo();

        return $config->wasRecentlyCreated || $config->wasChanged();
    }

    // Método para obter configurações por grupo
    public static function getPorGrupo(string $grupo): array
    {
        return Cache::remember("config_grupo_{$grupo}", 3600, function () use ($grupo) {
            return self::where('grupo', $grupo)
                      ->get()
                      ->keyBy('chave')
                      ->map(function ($config) {
                          return [
                              'valor' => $config->getValorTipado(),
                              'tipo' => $config->tipo,
                              'descricao' => $config->descricao,
                              'formatado' => $config->getValorFormatado()
                          ];
                      })
                      ->toArray();
        });
    }

    // Método para aplicar configurações em lote
    public static function aplicarLote(array $configuracoes, Usuario $usuario): array
    {
        $resultados = [];
        
        foreach ($configuracoes as $chave => $dados) {
            try {
                $valor = $dados['valor'] ?? $dados;
                $tipo = $dados['tipo'] ?? 'string';
                
                $sucesso = self::setValor($chave, $valor, $usuario, $tipo);
                $resultados[$chave] = $sucesso;
                
            } catch (\Exception $e) {
                $resultados[$chave] = false;
                \Log::error("Erro ao configurar '{$chave}': " . $e->getMessage());
            }
        }
        
        return $resultados;
    }

    // Método para resetar configurações para padrão
    public static function resetarParaPadrao(array $chaves = [], Usuario $usuario = null): int
    {
        $configuracoesReset = 0;
        
        $configuracoesPadrao = [
            'calibragem_global' => ['valor' => '0.00', 'tipo' => 'number'],
            'economia_padrao' => ['valor' => '20.00', 'tipo' => 'number'],
            'bandeira_padrao' => ['valor' => '20.00', 'tipo' => 'number'],
            'recorrencia_padrao' => ['valor' => '3%', 'tipo' => 'string'],
            'empresa_nome' => ['valor' => 'Aupus Energia', 'tipo' => 'string'],
            'sistema_versao' => ['valor' => '2.0', 'tipo' => 'string']
        ];
        
        $chavesParaReset = empty($chaves) ? array_keys($configuracoesPadrao) : $chaves;
        
        foreach ($chavesParaReset as $chave) {
            if (isset($configuracoesPadrao[$chave])) {
                $padrao = $configuracoesPadrao[$chave];
                
                if (self::setValor($chave, $padrao['valor'], $usuario, $padrao['tipo'])) {
                    $configuracoesReset++;
                }
            }
        }
        
        return $configuracoesReset;
    }

    // Método para exportar configurações
    public static function exportar(string $grupo = null): array
    {
        $query = self::query();
        
        if ($grupo) {
            $query->where('grupo', $grupo);
        }
        
        return $query->get()->map(function ($config) {
            return [
                'chave' => $config->chave,
                'valor' => $config->getValorTipado(),
                'tipo' => $config->tipo,
                'descricao' => $config->descricao,
                'grupo' => $config->grupo,
                'atualizado_em' => $config->updated_at?->format('d/m/Y H:i:s'),
                'atualizado_por' => $config->updatedBy?->nome
            ];
        })->toArray();
    }

    // Método para validar configuração
    public function validar(): array
    {
        $errors = [];
        
        // Validar chave
        if (empty($this->chave)) {
            $errors[] = 'Chave é obrigatória';
        }
        
        if (strlen($this->chave) > 50) {
            $errors[] = 'Chave deve ter no máximo 50 caracteres';
        }
        
        // Validar tipo
        if (!in_array($this->tipo, ['string', 'number', 'boolean', 'json'])) {
            $errors[] = 'Tipo deve ser string, number, boolean ou json';
        }
        
        // Validar valor baseado no tipo
        switch ($this->tipo) {
            case 'number':
                if (!is_numeric($this->valor)) {
                    $errors[] = 'Valor deve ser numérico';
                }
                break;
                
            case 'boolean':
                if (!in_array($this->valor, ['0', '1', 'true', 'false'])) {
                    $errors[] = 'Valor booleano deve ser 0, 1, true ou false';
                }
                break;
                
            case 'json':
                if (!json_decode($this->valor)) {
                    $errors[] = 'Valor deve ser um JSON válido';
                }
                break;
        }
        
        return $errors;
    }

    // Limpar cache por grupo
    private static function limparCacheGrupo(): void
    {
        $grupos = ['geral', 'calibragem', 'propostas', 'sistema'];
        
        foreach ($grupos as $grupo) {
            Cache::forget("config_grupo_{$grupo}");
        }
    }

    // Boot method para eventos
    protected static function boot()
    {
        parent::boot();

        static::saved(function ($config) {
            // Limpar cache específico
            Cache::forget("config_{$config->chave}");
            
            // Log de alterações
            \Log::info("Configuração alterada: {$config->chave} = {$config->valor} (por: {$config->updatedBy?->nome})");
        });

        static::deleted(function ($config) {
            // Limpar cache específico
            Cache::forget("config_{$config->chave}");
            
            // Log de remoção
            \Log::info("Configuração removida: {$config->chave} (por: {$config->updatedBy?->nome})");
        });
    }
}