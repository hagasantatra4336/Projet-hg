<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Une observation datée sur un contribuable (journal de la fiche de résultat). */
class FicheObservation extends Model
{
    protected $fillable = ['contribuable_id', 'user_id', 'texte'];

    public function contribuable(): BelongsTo
    {
        return $this->belongsTo(Contribuable::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
