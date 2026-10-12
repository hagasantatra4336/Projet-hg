<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Centre extends Model
{
    // code : identifiant officiel du centre (ex. « 21-1-702 ») ; abreviation : ex. « CF TANA I »
    protected $fillable = ['nom', 'adresse', 'code', 'abreviation'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
