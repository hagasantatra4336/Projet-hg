<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contribuable extends Model
{
    public const ETAT_ACTIF = 1;
    public const ETAT_BLOQUE = 2;
    public const ETAT_CESSATION = 3;
    public const ETAT_RADIE = 4;

    public const ETATS = [
        self::ETAT_ACTIF => 'Actif',
        self::ETAT_BLOQUE => 'Bloqué',
        self::ETAT_CESSATION => 'Cessation',
        self::ETAT_RADIE => 'Radié',
    ];

    protected $fillable = [
        'nif', 'nom', 'centre_id', 'etat', 'date_cessation', 'date_radiation', 'date_blocage',
        'telephone', 'email', 'adresse', 'activite',
    ];

    protected function casts(): array
    {
        return [
            'etat' => 'integer',
            'date_cessation' => 'date',
            'date_radiation' => 'date',
            'date_blocage' => 'date',
        ];
    }

    public function centre(): BelongsTo
    {
        return $this->belongsTo(Centre::class);
    }

    public function declarations(): HasMany
    {
        return $this->hasMany(Declaration::class);
    }

    public function assujettissements(): HasMany
    {
        return $this->hasMany(Assujettissement::class);
    }

    public function alertes(): HasMany
    {
        return $this->hasMany(Alerte::class);
    }

    public function observations(): HasMany
    {
        return $this->hasMany(FicheObservation::class);
    }
}
