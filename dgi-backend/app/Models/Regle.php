<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Règle de détection : activation et réglages modifiables depuis l'application. */
class Regle extends Model
{
    protected $table = 'regles';

    protected $fillable = ['code', 'nom', 'description', 'actif', 'parametres', 'modifie_par'];

    protected function casts(): array
    {
        return ['actif' => 'boolean', 'parametres' => 'array'];
    }

    public function modifiePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'modifie_par');
    }

    public function historique(): HasMany
    {
        return $this->hasMany(RegleHistorique::class);
    }
}
