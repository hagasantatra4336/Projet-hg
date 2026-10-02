<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Fonction;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Crée (ou promeut) le tout premier super administrateur, sans lequel personne ne pourrait
 * accéder à la gestion des utilisateurs :
 *   php artisan dgi:make-superadmin vous@exemple.com --nom="Votre nom"
 */
class MakeSuperadmin extends Command
{
    protected $signature = 'dgi:make-superadmin {email} {--nom=Super administrateur}';

    protected $description = 'Crée ou promeut un utilisateur en super administrateur';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Adresse e-mail invalide.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('Mot de passe (8 caractères minimum)');

        if (strlen($password) < 8) {
            $this->error('Mot de passe trop court.');

            return self::FAILURE;
        }

        $fonction = Fonction::where('role', Role::Superadmin->value)->first()
            ?? Fonction::create(['nom' => 'Super administrateur', 'role' => Role::Superadmin]);

        $user = User::firstOrNew(['email' => $email]);
        $user->nom = $user->nom ?: (string) $this->option('nom');
        $user->fonction_id = $fonction->id;
        $user->password = $password; // haché automatiquement (cast "hashed")
        $user->email_verified_at ??= now();
        $user->save();

        $this->info("Super administrateur prêt : {$email}");

        return self::SUCCESS;
    }
}
