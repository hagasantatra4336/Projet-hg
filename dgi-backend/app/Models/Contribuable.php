<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contribuable extends Model
{
    protected $fillable = ['nif', 'nom', 'centre_id'];

    public function centre(): BelongsTo
    {
        return $this->belongsTo(Centre::class);
    }

    public function declarations(): HasMany
    {
        return $this->hasMany(Declaration::class);
    }

    public function alertes(): HasMany
    {
        return $this->hasMany(Alerte::class);
    }
}
