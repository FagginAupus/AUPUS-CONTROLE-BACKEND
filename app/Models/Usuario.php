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

    // JWT implementation
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [
            'role' => $this->role ?? 'user',
            'email' => $this->email,
            'nome' => $this->nome,
            'is_active' => $this->is_active ?? true
        ];
    }

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

    // Atributos computados para compatibilidade
    public function getNameAttribute()
    {
        return $this->nome;
    }

    public function getPasswordAttribute()
    {
        return $this->senha;
    }

    // Métodos de conveniência para roles
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isGestor(): bool
    {
        return in_array($this->role, ['admin', 'gestor']);
    }

    public function isConsultor(): bool
    {
        return $this->role === 'consultor';
    }

    /**
     * Relacionamentos
     */
    public function propostas(): HasMany
    {
        return $this->hasMany(Proposta::class, 'usuario_id');
    }

    public function unidadesConsumidoras(): HasMany
    {
        return $this->hasMany(UnidadeConsumidora::class, 'usuario_id');
    }

    public function controles(): HasMany
    {
        return $this->hasMany(ControleClube::class, 'usuario_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'manager_id');
    }

    public function subordinados(): HasMany
    {
        return $this->hasMany(Usuario::class, 'manager_id');
    }

    public function notificacoes(): HasMany
    {
        return $this->hasMany(Notificacao::class, 'usuario_id');
    }

    /**
     * Scopes
     */
    public function scopeAtivos($query)
    {
        return $query->where('is_active', true);
    }

    public function scopePorRole($query, $role)
    {
        return $query->where('role', $role);
    }

    public function scopePorEmail($query, $email)
    {
        return $query->where('email', $email);
    }

    /**
     * Métodos auxiliares
     */
    public function getPermissoes(): array
    {
        $permissions = [
            'view_dashboard' => true,
            'view_propostas' => true,
        ];

        switch ($this->role) {
            case 'admin':
                $permissions = array_merge($permissions, [
                    'manage_users' => true,
                    'manage_system' => true,
                    'view_all_data' => true,
                    'edit_all_data' => true,
                    'delete_data' => true,
                ]);
                break;

            case 'gestor':
                $permissions = array_merge($permissions, [
                    'view_team_data' => true,
                    'manage_team' => true,
                    'edit_team_data' => true,
                ]);
                break;

            case 'consultor':
                $permissions = array_merge($permissions, [
                    'create_propostas' => true,
                    'edit_own_data' => true,
                ]);
                break;
        }

        return $permissions;
    }

    public function podeVerUsuario(Usuario $usuario): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        if ($this->isGestor()) {
            return $usuario->manager_id === $this->id || $usuario->id === $this->id;
        }

        return $usuario->id === $this->id;
    }

    public function podeEditarUsuario(Usuario $usuario): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        if ($this->isGestor()) {
            return $usuario->manager_id === $this->id;
        }

        return $usuario->id === $this->id;
    }
}