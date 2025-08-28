<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Hash;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Carbon\Carbon;
use Illuminate\Support\Str;

class Usuario extends Authenticatable implements JWTSubject
{
    use HasFactory, HasUuids, SoftDeletes, Notifiable, HasRoles;

    protected $table = 'usuarios';

    // ==========================================
    // CONFIGURAÇÃO DE ID - USAR ULID
    // ==========================================
    
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
        'nome',
        'email',
        'senha',
        'telefone',
        'instagram',
        'status',
        'cpf_cnpj',
        'cidade',
        'estado',
        'endereco',
        'cep',
        'pix', // ADICIONADO CAMPO PIX
        'role',
        'manager_id',
        'is_active',
        'concessionaria_atual_id',
        'organizacao_atual_id'
    ];

    protected $hidden = [
        'senha',
        'remember_token'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime'
    ];

    protected $attributes = [
        'status' => 'Ativo',
        'role' => 'vendedor',
        'is_active' => true
    ];

    // ==========================================
    // MUTATORS - IMPORTANTE: SEM AUTO-HASH
    // ==========================================
    
    /**
     * IMPORTANTE: Removido o hash automático aqui
     * O hash será feito explicitamente nos controllers
     */
    public function setSenhaAttribute($value)
    {
        // Se já é um hash, não hashear novamente
        if (password_get_info($value)['algo']) {
            $this->attributes['senha'] = $value;
        } else {
            // Se não é hash, hashear
            $this->attributes['senha'] = Hash::make($value);
        }
    }

    // ==========================================
    // ACESSORS
    // ==========================================

    public function getPasswordAttribute()
    {
        return $this->senha;
    }

    public function getNomeCompletoAttribute()
    {
        return $this->nome;
    }

    public function getIsActiveTextAttribute()
    {
        return $this->is_active ? 'Ativo' : 'Inativo';
    }

    // ==========================================
    // RELACIONAMENTOS
    // ==========================================

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'manager_id');
    }

    public function subordinados(): HasMany
    {
        return $this->hasMany(Usuario::class, 'manager_id');
    }

    public function propostas(): HasMany
    {
        return $this->hasMany(Proposta::class, 'usuario_id');
    }

    public function controleClube(): HasMany
    {
        return $this->hasMany(ControleClube::class, 'usuario_id');
    }

    public function unidadesConsumidoras(): HasMany
    {
        return $this->hasMany(UnidadeConsumidora::class, 'usuario_id');
    }

    public function notificacoes(): HasMany
    {
        return $this->hasMany(Notificacao::class, 'usuario_id');
    }

    // ==========================================
    // SCOPES
    // ==========================================

    public function scopeAtivos($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInativos($query)
    {
        return $query->where('is_active', false);
    }

    public function scopePorRole($query, $role)
    {
        return $query->where('role', $role);
    }

    public function scopeAdmins($query)
    {
        return $query->where('role', 'admin');
    }

    public function scopeConsultores($query)
    {
        return $query->where('role', 'consultor');
    }

    public function scopeGerentes($query)
    {
        return $query->where('role', 'gerente');
    }

    public function scopeVendedores($query)
    {
        return $query->where('role', 'vendedor');
    }

    public function scopeComManager($query)
    {
        return $query->whereNotNull('manager_id')->with('manager');
    }

    public function scopeComSubordinados($query)
    {
        return $query->has('subordinados')->with('subordinados');
    }

    public function scopePorHierarquia($query, Usuario $usuario)
    {
        if ($usuario->isAdmin()) {
            return $query;
        }
        
        if ($usuario->isConsultor()) {
            return $query->where(function ($q) use ($usuario) {
                $q->where('id', $usuario->id)
                  ->orWhere('manager_id', $usuario->id)
                  ->orWhereIn('manager_id', 
                      $usuario->subordinados()->pluck('id')->toArray()
                  );
            });
        }
        
        if ($usuario->isGerente()) {
            return $query->where(function ($q) use ($usuario) {
                $q->where('id', $usuario->id)
                  ->orWhere('manager_id', $usuario->id);
            });
        }
        
        // Vendedor só vê a si mesmo
        return $query->where('id', $usuario->id);
    }

    // ==========================================
    // MÉTODOS DE ROLE
    // ==========================================

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isConsultor(): bool
    {
        return $this->role === 'consultor';
    }

    public function isGerente(): bool
    {
        return $this->role === 'gerente';
    }

    public function isVendedor(): bool
    {
        return $this->role === 'vendedor';
    }

    public function hasRole($role): bool
    {
        return $this->role === $role;
    }

    public function canManage(Usuario $otherUser): bool
    {
        if ($this->isAdmin()) return true;
        if ($this->isConsultor() && in_array($otherUser->role, ['gerente', 'vendedor'])) return true;
        if ($this->isGerente() && $otherUser->isVendedor()) return true;
        
        return false;
    }

    // ==========================================
    // MÉTODOS DE PERMISSÃO
    // ==========================================

    public function getPermissoes(): array
    {
        $basePermissions = [];
        
        switch ($this->role) {
            case 'admin':
                $basePermissions = [
                    'all',
                    'manage_users',
                    'manage_proposals',
                    'manage_ugs',
                    'manage_calibration',
                    'view_all_data',
                    'manage_system',
                    'export_data'
                ];
                break;
                
            case 'consultor':
                $basePermissions = [
                    'manage_proposals',
                    'manage_ugs',
                    'view_team_data',
                    'create_users',
                    'export_data'
                ];
                break;
                
            case 'gerente':
                $basePermissions = [
                    'manage_proposals',
                    'view_team_data',
                    'create_vendedor'
                ];
                break;
                
            case 'vendedor':
                $basePermissions = [
                    'create_proposals',
                    'view_own_data'
                ];
                break;
                
            default:
                $basePermissions = ['view_own_data'];
        }
        
        return $basePermissions;
    }

    public function hasPermission(string $permission): bool
    {
        $permissions = $this->getPermissoes();
        
        return in_array('all', $permissions) || in_array($permission, $permissions);
    }

    // ==========================================
    // ESTATÍSTICAS
    // ==========================================

    public function getEstatisticas(): array
    {
        $hoje = Carbon::today();
        $mesAtual = Carbon::now()->startOfMonth();
        
        return [
            'propostas_total' => $this->propostas()->count(),
            'propostas_mes' => $this->propostas()->where('created_at', '>=', $mesAtual)->count(),
            'propostas_fechadas' => $this->propostas()->where('status', 'Fechado')->count(),
            'ugs_gerenciadas' => $this->unidadesConsumidoras()->where('is_ug', true)->count(),
            'subordinados_ativos' => $this->subordinados()->ativos()->count(),
            'ultima_atividade' => $this->updated_at,
        ];
    }

    // ==========================================
    // MÉTODOS DE HIERARQUIA
    // ==========================================

    /**
     * Obtém todos os subordinados (diretos e indiretos)
     */
    public function getAllSubordinates(): array
    {
        $allSubordinates = [];
        
        // Subordinados diretos
        $directSubordinates = $this->subordinados()->get();
        
        foreach ($directSubordinates as $subordinate) {
            $allSubordinates[] = $subordinate->toArray();
            
            // Recursivamente buscar subordinados dos subordinados
            $indirectSubordinates = $subordinate->getAllSubordinates();
            $allSubordinates = array_merge($allSubordinates, $indirectSubordinates);
        }
        
        return $allSubordinates;
    }

    /**
     * Retorna o nível hierárquico do usuário
     */
    public function getHierarchyLevel(): int
    {
        switch ($this->role) {
            case 'admin':
                return 1;
            case 'consultor':
                return 2;
            case 'gerente':
                return 3;
            case 'vendedor':
                return 4;
            default:
                return 5;
        }
    }

    /**
     * Verifica se pode gerenciar outro usuário
     */
    public function canManageUser(Usuario $otherUser): bool
    {
        if ($this->isAdmin()) return true;
        
        // Consultor pode gerenciar gerentes e vendedores
        if ($this->isConsultor() && in_array($otherUser->role, ['gerente', 'vendedor'])) {
            return true;
        }
        
        // Gerente pode gerenciar apenas vendedores
        if ($this->isGerente() && $otherUser->isVendedor()) {
            return true;
        }
        
        return false;
    }

    // ==========================================
    // JWT METHODS
    // ==========================================

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [
            'role' => $this->role,
            'nome' => $this->nome,
            'is_active' => $this->is_active
        ];
    }

    // ==========================================
    // MÉTODOS UTILITÁRIOS
    // ==========================================

    public function ativar(): bool
    {
        return $this->update(['is_active' => true]);
    }

    public function desativar(): bool
    {
        return $this->update(['is_active' => false]);
    }

    public function toggleStatus(): bool
    {
        return $this->update(['is_active' => !$this->is_active]);
    }

    public function getHierarquiaCompleta(): array
    {
        $hierarquia = [];
        
        // Manager atual
        if ($this->manager) {
            $hierarquia['manager'] = [
                'id' => $this->manager->id,
                'nome' => $this->manager->nome,
                'role' => $this->manager->role
            ];
        }
        
        // Subordinados
        $hierarquia['subordinados'] = $this->subordinados->map(function ($subordinado) {
            return [
                'id' => $subordinado->id,
                'nome' => $subordinado->nome,
                'role' => $subordinado->role,
                'is_active' => $subordinado->is_active
            ];
        })->toArray();
        
        return $hierarquia;
    }

    // ==========================================
    // VALIDAÇÕES CUSTOMIZADAS
    // ==========================================

    public function podeSerDesativado(): bool
    {
        // Admin principal não pode ser desativado
        if ($this->isAdmin() && $this->email === 'admin@aupus.com') {
            return false;
        }
        
        // Usuários com subordinados ativos não podem ser desativados
        if ($this->subordinados()->ativos()->exists()) {
            return false;
        }
        
        return true;
    }

    public function podeSerExcluido(): bool
    {
        // Não pode excluir se tem propostas
        if ($this->propostas()->exists()) {
            return false;
        }
        
        // Não pode excluir se tem subordinados
        if ($this->subordinados()->exists()) {
            return false;
        }
        
        return true;
    }

    // ==========================================
    // MÉTODOS DE FORMATAÇÃO
    // ==========================================

    public function toArray()
    {
        $data = parent::toArray();
        
        // Remover campos sensíveis
        unset($data['senha']);
        unset($data['remember_token']);
        
        // Adicionar campos computados
        $data['nome_completo'] = $this->nome;
        $data['is_active_text'] = $this->is_active_text;
        $data['permissions'] = $this->getPermissoes();
        
        return $data;
    }

    // Método para autenticação Laravel
    public function getAuthPassword()
    {
        return $this->senha;
    }

    // Override do método de verificação de senha
    public function checkPassword($password)
    {
        return Hash::check($password, $this->senha);
    }
}