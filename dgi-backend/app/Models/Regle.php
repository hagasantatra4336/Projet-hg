<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Règle de détection.
 *  - type « systeme » : défaillance et baisse_ca (étape 1) ; activation et réglages modifiables.
 *  - type « personnalisee » : créée dans l'application à partir d'un modèle ; statut brouillon → (simulation) → active.
 */
class Regle extends Model
{
    public const SYSTEME = 'systeme';
    public const PERSONNALISEE = 'personnalisee';

    public const BROUILLON = 'brouillon';
    public const ACTIVE = 'active';
    public const DESACTIVEE = 'desactivee';

    protected $table = 'regles';

    protected $fillable = [
        'code', 'type', 'modele', 'statut', 'nom', 'description', 'actif', 'parametres', 'conditions', 'impots',
        'niveau', 'empreinte', 'version', 'modifie_par', 'cree_par',
    ];

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
            'parametres' => 'array',
            'conditions' => 'array',
            'impots' => 'array',
            'version' => 'integer',
        ];
    }

    public function scopePersonnalisees(Builder $q): Builder
    {
        return $q->where('type', self::PERSONNALISEE);
    }

    public function estPersonnalisee(): bool
    {
        return $this->type === self::PERSONNALISEE;
    }

    public function modifiePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'modifie_par');
    }

    public function creePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par');
    }

    public function historique(): HasMany
    {
        return $this->hasMany(RegleHistorique::class);
    }

    public function simulations(): HasMany
    {
        return $this->hasMany(RegleSimulation::class);
    }
}
