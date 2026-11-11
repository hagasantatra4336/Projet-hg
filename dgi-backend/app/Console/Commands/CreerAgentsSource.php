<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Table du personnel dans la base source (E-Kajy), utilisée pour contrôler le centre des agents à la connexion
 * (voir App\Services\ControleAffectationSource) :
 *   php artisan dgi:agents-source
 * Crée la table si elle n'existe pas, puis ajoute les comptes de l'application qui n'y figurent pas encore
 * (e-mail, matricule, nom, centre, grade, actif). Les lignes déjà présentes ne sont jamais modifiées :
 * la base source fait foi, c'est l'administrateur E-Kajy qui la tient à jour.
 */
class CreerAgentsSource extends Command
{
    protected $signature = 'dgi:agents-source';

    protected $description = "Crée la table des agents dans la base source et l'alimente avec les comptes de l'application";

    public function handle(): int
    {
        $connexion = (string) config('dgi.source.connection');
        $cfg = (array) config('dgi.source.agents', []);
        $table = trim((string) ($cfg['table'] ?? ''));
        $colonnes = (array) ($cfg['colonnes'] ?? []);

        if ($table === '' || empty($colonnes['email'])) {
            $this->error('Table ou colonne e-mail des agents non configurée (config/dgi.php, section source.agents).');

            return self::FAILURE;
        }

        try {
            $schema = Schema::connection($connexion);

            if (! $schema->hasTable($table)) {
                $schema->create($table, function (Blueprint $t) use ($colonnes): void {
                    $t->string((string) $colonnes['email'])->unique();

                    foreach (['matricule', 'nom', 'centre_code', 'centre_libelle', 'grade'] as $champ) {
                        if (! empty($colonnes[$champ])) {
                            $t->string((string) $colonnes[$champ])->nullable();
                        }
                    }

                    if (! empty($colonnes['actif'])) {
                        $t->boolean((string) $colonnes['actif'])->default(true);
                    }
                });

                $this->line("Table « {$table} » créée dans la base source.");
            }

            $reelles = $this->colonnesReelles($connexion, $table, $colonnes);

            if (empty($reelles['email'])) {
                $this->error("La colonne e-mail est introuvable dans la table « {$table} ».");

                return self::FAILURE;
            }

            $cx = DB::connection($connexion);
            $existants = $cx->table($table)->pluck($reelles['email'])
                ->map(fn ($email) => mb_strtolower(trim((string) $email)))
                ->filter()
                ->flip();

            $ajoutes = 0;

            User::with(['centre', 'fonction'])->orderBy('id')->each(function (User $u) use ($cx, $table, $reelles, $existants, &$ajoutes): void {
                $email = mb_strtolower(trim((string) $u->email));

                if ($email === '' || $existants->has($email)) {
                    return;
                }

                $valeurs = [$reelles['email'] => $email];
                $valeurs += $this->valeurs($u, $reelles);

                $cx->table($table)->insert($valeurs);
                $existants->put($email, true);
                $ajoutes++;
            });

            $this->info("Table « {$table} » prête : {$ajoutes} agent(s) ajouté(s), {$existants->count()} au total.");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error("Impossible de préparer la table des agents : {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /**
     * @param  array<string, string|null>  $reelles
     * @return array<string, mixed>
     */
    private function valeurs(User $u, array $reelles): array
    {
        $valeurs = [];

        foreach (['matricule' => $u->im, 'nom' => $u->nom, 'centre_code' => $u->centre?->code,
            'centre_libelle' => $u->centre?->nom, 'grade' => $u->fonction?->nom ?? $u->role?->label()] as $champ => $valeur) {
            if (! empty($reelles[$champ]) && trim((string) $valeur) !== '') {
                $valeurs[$reelles[$champ]] = trim((string) $valeur);
            }
        }

        if (! empty($reelles['actif'])) {
            $valeurs[$reelles['actif']] = true;
        }

        return $valeurs;
    }

    /**
     * @param  array<string, string|null>  $voulues
     * @return array<string, string|null>
     */
    private function colonnesReelles(string $connexion, string $table, array $voulues): array
    {
        $reelles = [];
        foreach (Schema::connection($connexion)->getColumnListing($table) as $nom) {
            $reelles[mb_strtolower((string) $nom)] = (string) $nom;
        }

        $resultat = [];
        foreach ($voulues as $champ => $nom) {
            $resultat[$champ] = $nom === null ? null : ($reelles[mb_strtolower((string) $nom)] ?? null);
        }

        return $resultat;
    }
}
