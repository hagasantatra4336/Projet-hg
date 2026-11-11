<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Centre extends Model
{
    public const TYPE_CENTRAL = 'central';

    public const TYPE_FISCAL = '-';

    // code : identifiant officiel du centre (ex. « 21-1-702 ») ; abreviation : ex. « CF TANA I »
    protected $fillable = ['nom', 'adresse', 'code', 'abreviation', 'type_centre', 'affiche'];

    protected function casts(): array
    {
        return ['affiche' => 'boolean'];
    }

    /** Centres proposés dans l'application (inscription) : les centres fiscaux, et les services centraux cochés. */
    public function scopeProposes(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('type_centre', '!=', self::TYPE_CENTRAL)
            ->orWhere('affiche', true));
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
