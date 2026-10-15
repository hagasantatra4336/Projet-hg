<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Indicateur : une valeur lue dans une colonne de la table « declarations ». */
class Indicateur extends Model
{
    protected $table = 'indicateurs';

    protected $fillable = ['code', 'libelle', 'description', 'colonne', 'unite', 'impots', 'actif', 'systeme'];

    protected function casts(): array
    {
        return ['impots' => 'array', 'actif' => 'boolean', 'systeme' => 'boolean'];
    }
}
