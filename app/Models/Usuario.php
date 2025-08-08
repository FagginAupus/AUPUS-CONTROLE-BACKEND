<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use Tymon\JWTAuth\Contracts\JWTSubject;

class Usuario extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable, HasUuids, SoftDeletes, HasRoles;

    protected $table = 'usuarios';

    protected $fillable = [
        'concessionaria_atual_id',
        'organizacao_atual_id',
        'nome',
        'email',
        'telefone',
        'instagram',
        'status',
        'cpf_cnpj',
        'cidade',
        'estado',
        'endereco',
        'cep',
        'senha',
        'role',
        'manager_id',
        'is_active'
    ];

    protected $hidden = [
        'senha',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_active' => 'boolean',
        'deleted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Para compatibilidade com Auth do Laravel
    public function getAuthPassword()
    {
        return $this->senha;
    }

    public function getAuthIdentifierName()
    {
        return 'email';
    }

    public function getAuthIdentifier()
    {
        return $this->email;
    }

    // Compatibilidade com factories/seeders Laravel
    public function getNameAttribute()
    {
        return $this->nome;
    }

    public function getPasswordAttribute()
    {
        return $this->senha;
    }

    // JWT Methods
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [
            'role' => $this->role,
            'nome' => $this->nome,
            'email' => $this->email,
            'is_active' => $this->is_active
        ];
    }

    // Relacionamentos
    public function concessionaria(): BelongsTo
    {
        return $this->belongsTo(Concessionaria::class, 'concessionaria_atual_id');
    }

    public function organizacao(): BelongsTo
    {
        return $this->belongsTo(Organizacao::class, 'organizacao_atual_id');
    }

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

    public function unidadesConsumidoras(): HasMany
    {
        return $this->hasMany(UnidadeConsumidora::class, 'usuario_id');
    }

    public function notificacoes(): HasMany
    {
        return $this->hasMany(Notificacao::class, 'usuario_id');
    }

    // Scopes
    public function scopeAtivos($query)
    {
        return $query->where('is_active', true);
    }

    public function scopePorRole($query, $role)
    {
        return $query->where('role', $role);
    }

    public function scopeSubordinadosDe($query, $managerId)
    {
        return $query->where('manager_id', $managerId);
    }

    // Métodos de Hierarquia
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

    public function canManageUser(Usuario $user): bool
    {
        if ($this->isAdmin()) return true;
        
        if ($this->isConsultor()) {
            return $user->role !== 'admin' && $user->role !== 'consultor';
        }
        
        if ($this->isGerente()) {
            return $user->role === 'vendedor' && $user->manager_id === $this->id;
        }
        
        return false;
    }

    public function getHierarchyLevel(): int
    {
        return match($this->role) {
            'admin' => 4,
            'consultor' => 3,
            'gerente' => 2,
            'vendedor' => 1,
            default => 0
        };
    }

    public function getAllSubordinates(): array
    {
        $subordinates = [];
        
        foreach ($this->subordinados as $subordinate) {
            $subordinates[] = $subordinate;
            $subordinates = array_merge($subordinates, $subordinate->getAllSubordinates());
        }
        
        return $subordinates;
    }

    // Métodos de Permissão baseados no frontend
    public function canAccessData($data): bool
    {
        if ($this->isAdmin()) return true;
        
        // Lógica baseada na hierarquia do frontend
        $allowedConsultors = [];
        
        if ($this->isConsultor()) {
            $subordinates = $this->getAllSubordinates();
            $allowedConsultors = array_merge([$this->nome], array_column($subordinates, 'nome'));
        } else {
            $allowedConsultors = [$this->nome];
        }
        
        // Verificar se pode acessar baseado no consultor ou cliente
        return in_array($data['consultor'] ?? '', $allowedConsultors) ||
               in_array($data['nomeCliente'] ?? '', $allowedConsultors);
    }

    // Mutators
    public function setSenhaAttribute($value)
    {
        $this->attributes['senha'] = bcrypt($value);
    }

    // Para compatibilidade com Laravel Auth que espera 'password'
    public function setPasswordAttribute($value)
    {
        $this->setSenhaAttribute($value);
    }

    // Accessors
    public function getNomeCompletoAttribute(): string
    {
        return $this->nome;
    }

    public function getStatusDisplayAttribute(): string
    {
        return $this->is_active ? 'Ativo' : 'Inativo';
    }

    // Boot method para eventos
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($usuario) {
            if (empty($usuario->role)) {
                $usuario->role = 'vendedor';
            }
            if ($usuario->is_active === null) {
                $usuario->is_active = true;
            }
        });
    }
}