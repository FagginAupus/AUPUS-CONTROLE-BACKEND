<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;
use Illuminate\Support\Str;

class Notificacao extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'notificacoes';

    // ULID configuration
    public $incrementing = false;
    protected $keyType = 'string';

    /**
     * Generate a new ULID for the model.
     */
    public function newUniqueId(): string
    {
        return (string) Str::ulid();
    }

    protected $fillable = [
        'usuario_id',
        'titulo',
        'descricao',
        'lida',
        'tipo',
        'link'
    ];

    protected $casts = [
        'lida' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $attributes = [
        'lida' => false,
        'tipo' => 'info'
    ];

    // Relacionamentos
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    // Scopes
    public function scopeNaoLidas($query)
    {
        return $query->where('lida', false);
    }

    public function scopeLidas($query)
    {
        return $query->where('lida', true);
    }

    public function scopePorUsuario($query, $usuarioId)
    {
        return $query->where('usuario_id', $usuarioId);
    }

    public function scopePorTipo($query, $tipo)
    {
        return $query->where('tipo', $tipo);
    }

    public function scopeRecentes($query, $dias = 7)
    {
        return $query->where('created_at', '>=', Carbon::now()->subDays($dias));
    }

    public function scopeOrdenadaPorData($query, $ordem = 'desc')
    {
        return $query->orderBy('created_at', $ordem);
    }

    // Accessors
    public function getTipoIconAttribute(): string
    {
        switch($this->tipo) {
            case 'sucesso': return '✅';
            case 'aviso': return '⚠️';
            case 'erro': return '❌';
            case 'info': return 'ℹ️';
            case 'proposta': return '📝';
            case 'controle': return '📊';
            case 'ug': return '🏭';
            case 'calibragem': return '⚖️';
            case 'sistema': return '🔧';
            default: return 'ℹ️';
        }
    }

    public function getTipoColorAttribute(): string
    {
        switch($this->tipo) {
            case 'sucesso': return 'success';
            case 'aviso': return 'warning';
            case 'erro': return 'danger';
            case 'info': return 'info';
            case 'proposta': return 'primary';
            case 'controle': return 'secondary';
            case 'ug': return 'dark';
            case 'calibragem': return 'warning';
            case 'sistema': return 'info';
            default: return 'info';
        }
    }

    public function getTempoDecorridoAttribute(): string
    {
        $agora = Carbon::now();
        $diff = $this->created_at->diff($agora);

        if ($diff->days > 0) {
            return $diff->days . ' dia' . ($diff->days > 1 ? 's' : '') . ' atrás';
        }

        if ($diff->h > 0) {
            return $diff->h . ' hora' . ($diff->h > 1 ? 's' : '') . ' atrás';
        }

        if ($diff->i > 0) {
            return $diff->i . ' minuto' . ($diff->i > 1 ? 's' : '') . ' atrás';
        }

        return 'Agora';
    }

    public function getDataFormatadaAttribute(): string
    {
        return $this->created_at->format('d/m/Y H:i');
    }

    // Métodos de Negócio
    public function marcarComoLida(): bool
    {
        if ($this->lida) {
            return false;
        }
        
        $this->lida = true;
        return $this->save();
    }

    public function marcarComoNaoLida(): bool
    {
        if (!$this->lida) {
            return false;
        }
        
        $this->lida = false;
        return $this->save();
    }

    // Métodos estáticos para criar notificações específicas
    public static function criarPropostaCriada(Proposta $proposta): self
    {
        return self::create([
            'usuario_id' => $proposta->usuario_id,
            'titulo' => 'Nova proposta criada',
            'descricao' => "Proposta {$proposta->numero_proposta} criada para {$proposta->nome_cliente}",
            'tipo' => 'proposta',
            'link' => "/prospec"
        ]);
    }

    public static function criarPropostaFechada(Proposta $proposta): self
    {
        return self::create([
            'usuario_id' => $proposta->usuario_id,
            'titulo' => 'Proposta fechada',
            'descricao' => "Proposta {$proposta->numero_proposta} foi fechada com sucesso!",
            'tipo' => 'sucesso',
            'link' => "/controle"
        ]);
    }

    public static function criarPropostaAlterada(Proposta $proposta, string $statusAnterior): self
    {
        return self::create([
            'usuario_id' => $proposta->usuario_id,
            'titulo' => 'Status da proposta alterado',
            'descricao' => "Proposta {$proposta->numero_proposta} alterada de '{$statusAnterior}' para '{$proposta->status}'",
            'tipo' => 'info',
            'link' => "/prospec"
        ]);
    }

    public static function criarCalibragemAplicada(ControleClube $controle, float $percentual, Usuario $aplicadoPor): self
    {
        return self::create([
            'usuario_id' => $controle->usuario_id,
            'titulo' => 'Calibragem aplicada',
            'descricao' => "Calibragem de " . number_format($percentual, 2, ',', '.') . "% aplicada no controle {$controle->numero_proposta}/{$controle->numero_uc}",
            'tipo' => 'calibragem',
            'link' => "/controle"
        ]);
    }

    public static function criarUGVinculada(ControleClube $controle, UnidadeConsumidora $ug): self
    {
        return self::create([
            'usuario_id' => $controle->usuario_id,
            'titulo' => 'UG vinculada ao controle',
            'descricao' => "UG {$ug->nome_usina} vinculada ao controle {$controle->numero_proposta}/{$controle->numero_uc}",
            'tipo' => 'ug',
            'link' => "/controle"
        ]);
    }

    public static function criarControleInativado(ControleClube $controle, string $motivo): self
    {
        return self::create([
            'usuario_id' => $controle->usuario_id,
            'titulo' => 'Controle inativado',
            'descricao' => "Controle {$controle->numero_proposta}/{$controle->numero_uc} foi inativado. Motivo: {$motivo}",
            'tipo' => 'aviso',
            'link' => "/controle"
        ]);
    }

    public static function criarUsuarioCriado(Usuario $novoUsuario, Usuario $criadoPor): self
    {
        return self::create([
            'usuario_id' => $novoUsuario->id,
            'titulo' => 'Bem-vindo ao Aupus Energia!',
            'descricao' => "Sua conta foi criada por {$criadoPor->nome}. Acesse o sistema e comece a trabalhar!",
            'tipo' => 'sucesso',
            'link' => "/dashboard"
        ]);
    }

    public static function criarUsuarioInativado(Usuario $usuario, Usuario $inativadoPor): self
    {
        return self::create([
            'usuario_id' => $usuario->id,
            'titulo' => 'Conta desativada',
            'descricao' => "Sua conta foi desativada por {$inativadoPor->nome}. Entre em contato com seu superior.",
            'tipo' => 'aviso',
            'link' => null
        ]);
    }

    public static function criarUsuarioReativado(Usuario $usuario, Usuario $reativadoPor): self
    {
        return self::create([
            'usuario_id' => $usuario->id,
            'titulo' => 'Conta reativada',
            'descricao' => "Sua conta foi reativada por {$reativadoPor->nome}. Bem-vindo de volta!",
            'tipo' => 'sucesso',
            'link' => "/dashboard"
        ]);
    }

    public static function criarNotificacaoSistema(Usuario $usuario, string $titulo, string $descricao, string $tipo = 'sistema', string $link = null): self
    {
        return self::create([
            'usuario_id' => $usuario->id,
            'titulo' => $titulo,
            'descricao' => $descricao,
            'tipo' => $tipo,
            'link' => $link
        ]);
    }

    // Método para notificar múltiplos usuários
    public static function notificarMultiplos(array $usuarioIds, string $titulo, string $descricao, string $tipo = 'info', string $link = null): array
    {
        $notificacoes = [];
        
        foreach ($usuarioIds as $usuarioId) {
            $notificacoes[] = self::create([
                'usuario_id' => $usuarioId,
                'titulo' => $titulo,
                'descricao' => $descricao,
                'tipo' => $tipo,
                'link' => $link
            ]);
        }
        
        return $notificacoes;
    }

    // Método para notificar hierarquia (admin, consultores, etc)
    public static function notificarHierarquia(array $roles, string $titulo, string $descricao, string $tipo = 'sistema', string $link = null): array
    {
        $usuarios = Usuario::whereIn('role', $roles)->where('is_active', true)->pluck('id')->toArray();
        
        return self::notificarMultiplos($usuarios, $titulo, $descricao, $tipo, $link);
    }

    // Método para notificar subordinados de um usuário
    public static function notificarSubordinados(Usuario $manager, string $titulo, string $descricao, string $tipo = 'info', string $link = null): array
    {
        $subordinados = $manager->getAllSubordinates();
        $usuarioIds = array_column($subordinados, 'id');
        
        return self::notificarMultiplos($usuarioIds, $titulo, $descricao, $tipo, $link);
    }

    // Métodos para notificações de documentos Autentique
    public static function criarDocumentoAssinado(string $nomeCliente, string $numeroUC): array
    {
        $titulo = 'Documento assinado via Autentique';
        $descricao = "Termo de adesão do cliente {$nomeCliente} (UC: {$numeroUC}) foi assinado com sucesso";

        return self::notificarHierarquia(['admin', 'analista'], $titulo, $descricao, 'sucesso', '/controle');
    }

    public static function criarDocumentoRejeitado(string $nomeCliente, string $numeroUC, string $motivo = ''): array
    {
        $titulo = 'Documento rejeitado via Autentique';
        $descricao = "Termo de adesão do cliente {$nomeCliente} (UC: {$numeroUC}) foi rejeitado";
        if ($motivo) {
            $descricao .= ". Motivo: {$motivo}";
        }

        return self::notificarHierarquia(['admin', 'analista'], $titulo, $descricao, 'erro', '/controle');
    }

    // Método para limpar notificações antigas
    public static function limparAntigas(int $diasParaManter = 30): int
    {
        return self::where('created_at', '<', Carbon::now()->subDays($diasParaManter))->delete();
    }

    // Método para marcar todas como lidas para um usuário
    public static function marcarTodasLidasPorUsuario(string $usuarioId): int
    {
        return self::where('usuario_id', $usuarioId)
                  ->where('lida', false)
                  ->update(['lida' => true]);
    }

    // Estatísticas de notificações
    public static function getEstatisticasPorUsuario(string $usuarioId): array
    {
        $query = self::where('usuario_id', $usuarioId);
        
        return [
            'total' => $query->count(),
            'nao_lidas' => $query->clone()->where('lida', false)->count(),
            'lidas' => $query->clone()->where('lida', true)->count(),
            'por_tipo' => $query->clone()
                               ->selectRaw('tipo, COUNT(*) as total')
                               ->groupBy('tipo')
                               ->pluck('total', 'tipo')
                               ->toArray(),
            'recentes' => $query->clone()->recentes()->count(),
            'hoje' => $query->clone()->whereDate('created_at', Carbon::today())->count()
        ];
    }

    // Validações
    public function validar(): array
    {
        $errors = [];

        if (empty($this->usuario_id)) {
            $errors[] = 'Usuário é obrigatório';
        }

        if (empty($this->titulo)) {
            $errors[] = 'Título é obrigatório';
        }

        if (strlen($this->titulo) > 200) {
            $errors[] = 'Título deve ter no máximo 200 caracteres';
        }

        if (empty($this->descricao)) {
            $errors[] = 'Descrição é obrigatória';
        }

        if (strlen($this->descricao) > 1000) {
            $errors[] = 'Descrição deve ter no máximo 1000 caracteres';
        }

        $tiposValidos = ['sucesso', 'aviso', 'erro', 'info', 'proposta', 'controle', 'ug', 'calibragem', 'sistema'];
        if (!in_array($this->tipo, $tiposValidos)) {
            $errors[] = 'Tipo deve ser um dos valores válidos: ' . implode(', ', $tiposValidos);
        }

        return $errors;
    }

    // Boot method para eventos
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($notificacao) {
            // Validar antes de criar
            $errors = $notificacao->validar();
            if (!empty($errors)) {
                throw new \Exception('Erro na validação da notificação: ' . implode(', ', $errors));
            }
        });

        static::created(function ($notificacao) {
            \Log::info("Notificação criada", [
                'id' => $notificacao->id,
                'usuario_id' => $notificacao->usuario_id,
                'titulo' => $notificacao->titulo,
                'tipo' => $notificacao->tipo
            ]);
        });

        static::updated(function ($notificacao) {
            if ($notificacao->isDirty('lida') && $notificacao->lida) {
                \Log::info("Notificação marcada como lida", [
                    'id' => $notificacao->id,
                    'usuario_id' => $notificacao->usuario_id
                ]);
            }
        });
    }
}