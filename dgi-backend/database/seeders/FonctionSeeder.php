<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Fonction;
use Illuminate\Database\Seeder;

/**
 * Fonctions de départ (modifiables ensuite dans l'application) :
 *   php artisan db:seed --class=FonctionSeeder
 * Peut être relancé sans créer de doublons.
 */
class FonctionSeeder extends Seeder
{
    public function run(): void
    {
        $fonctions = [
            'Super administrateur' => Role::Superadmin,
            'Responsable central' => Role::Central,
            'Chef de centre' => Role::Admin,
            'Agent' => Role::Agent,
        ];

        foreach ($fonctions as $nom => $role) {
            Fonction::firstOrCreate(['nom' => $nom], ['role' => $role]);
        }
    }
}
