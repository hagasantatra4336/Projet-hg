<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Une action datée sur un dossier contribuable (journal affiché dans le dossier). */
class DossierHistorique extends Model
{
    public const PRISE = 'prise';
    public const REPRISE = 'reprise';
    public const LIBERATION = 'liberation';
    public const CLOTURE = 'cloture';
    public const REOUVERTURE = 'reouverture';

    protected $fillable = [
        'contribuable_id',
        'alerte_id',
        'user_id',
        'auteur',
        'action',
        'statut_avant',
        'statut_apres',
    ];

    public function contribuable(): BelongsTo
    {
        return $this->belongsTo(Contribuable::class);
    }

    public function alerte(): BelongsTo
    {
        return $this->belongsTo(Alerte::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
