<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    public function getEmailForPasswordReset(): ?string
    {
        return $this->email_verified_at ? $this->email : null;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return ! $this->isBlocked();
    }

    protected $attributes = [
        'is_admin' => false,
        'can_start_campaigns' => false,
        'can_manage_structure' => false,
    ];

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'login',
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'pd_consent_at' => 'datetime',
            'blocked_at' => 'datetime',
            'is_admin' => 'boolean',
            'can_start_campaigns' => 'boolean',
            'can_manage_structure' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public function isAdmin(): bool
    {
        return $this->is_admin;
    }

    public function isBlocked(): bool
    {
        return $this->blocked_at !== null;
    }

    public function canSeeNode(Node $node): bool
    {
        return $this->isAdmin() || ($this->node !== null
            && ($this->node->is($node) || $this->node->isAncestorOf($node)));
    }

    public function canManageUser(User $other): bool
    {
        if ($this->is($other)) {
            return false;
        }

        return $this->isAdmin() || ($this->node !== null && $other->node !== null
            && $this->node->isAncestorOf($other->node));
    }

    /** Список управляемых пользователей: только строго нижестоящие узлы. */
    public function scopeInBranchOf(Builder $query, User $viewer): Builder
    {
        if ($viewer->isAdmin()) {
            return $query;
        }

        if ($viewer->node === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('node', fn (Builder $nodes) => $nodes->inBranch($viewer->node, false));
    }
}
