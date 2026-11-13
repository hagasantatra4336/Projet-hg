<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Une désactivation ou une réactivation de compte, avec son motif et son auteur. */
class UserStatutHistorique extends Model
{
    public const DESACTIVATION = 'desactivation';
    public const ACTIVATION = 'activation';

    protected $fillable = ['user_id', 'auteur_id', 'auteur', 'action', 'motif'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
