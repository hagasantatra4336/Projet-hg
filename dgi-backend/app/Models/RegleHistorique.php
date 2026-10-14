<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegleHistorique extends Model
{
    protected $table = 'regles_historique';

    protected $fillable = ['regle_id', 'user_id', 'action', 'avant', 'apres'];

    protected function casts(): array
    {
        return ['avant' => 'array', 'apres' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
