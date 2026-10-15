<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegleSimulation extends Model
{
    protected $table = 'regles_simulations';

    protected $fillable = ['regle_id', 'user_id', 'empreinte', 'version', 'date_analyse', 'centre_id', 'statut', 'erreur', 'resultats'];

    protected function casts(): array
    {
        return ['resultats' => 'array', 'date_analyse' => 'date'];
    }

    public function regle(): BelongsTo
    {
        return $this->belongsTo(Regle::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
