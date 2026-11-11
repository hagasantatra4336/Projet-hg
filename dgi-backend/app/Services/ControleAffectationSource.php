<?php

namespace App\Services;

use App\Models\Centre;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Contrôle du rattachement du personnel dans la base source (E-Kajy), à chaque connexion.
 *
 * Si l'agent n'est plus rattaché au centre enregistré dans l'application, son compte est rattaché
 * automatiquement au centre indiqué par la base source. Le compte, son mot de passe et ses dossiers
 * restent inchangés : seules les règles d'accès au centre évoluent. Les dossiers qu'il a déjà traités
 * restent consultables en lecture seule depuis ses archives.
 */
class ControleAffectationSource
{
    /** Retourne le nouveau centre quand le compte vient d'être rattaché à un autre centre, sinon null. */
    public function synchroniser(User $user): ?Centre
    {
        $cfg = (array) config('dgi.source.agents', []);
        $table = trim((string) ($cfg['table'] ?? ''));
        $connexion = (string) config('dgi.source.connection');

        if ($table === '' || trim((string) $user->email) === '') {
            return null;
        }

        try {
            if (! Schema::connection($connexion)->hasTable($table)) {
                return null; // base source sans table du personnel : le centre actuel est conservé
            }

            $colonnes = $this->colonnesReelles($connexion, $table, (array) ($cfg['colonnes'] ?? []));
            $ligne = $this->ligneAgent(DB::connection($connexion), $table, $colonnes, $user);

            if ($ligne === null || ! $this->actif($ligne)) {
                return null;
            }

            $centreId = CentreResolver::depuisBase()->resoudre(
                $this->texte($ligne, 'centre_libelle'),
                $this->texte($ligne, 'centre_code'),
            );

            if ($centreId === null || $centreId === (int) $user->centre_id) {
                return null;
            }

            $ancien = $user->centre_id;
            $user->forceFill(['centre_id' => $centreId])->save();

            Log::info('Centre du compte synchronisé depuis la base source.', [
                'user_id' => $user->id,
                'email' => $user->email,
                'ancien_centre_id' => $ancien,
                'nouveau_centre_id' => $centreId,
            ]);

            return Centre::find($centreId);
        } catch (Throwable $e) {
            // La connexion ne doit jamais échouer à cause de la base source : le centre actuel est conservé.
            Log::warning('Contrôle du centre du personnel impossible : ' . $e->getMessage(), ['user_id' => $user->id]);

            return null;
        }
    }

    /**
     * Vrais noms de colonnes (la casse dépend du SGBD et de la façon dont la table a été créée).
     *
     * @param  array<string, string|null>  $voulues
     * @return array<string, string|null>  champ => vrai nom (null si absent)
     */
    private function colonnesReelles(string $connexion, string $table, array $voulues): array
    {
        $reelles = [];
        foreach (Schema::connection($connexion)->getColumnListing($table) as $nom) {
            $reelles[mb_strtolower((string) $nom)] = (string) $nom;
        }

        $resultat = [];
        foreach ($voulues as $champ => $nom) {
            $resultat[$champ] = $nom === null || trim((string) $nom) === ''
                ? null
                : ($reelles[mb_strtolower(trim((string) $nom))] ?? null);
        }

        return $resultat;
    }

    /**
     * Ligne du personnel correspondant au compte : e-mail d'abord, à défaut matricule (IM).
     *
     * @param  array<string, string|null>  $colonnes
     * @return array<string, mixed>|null  clés en minuscules
     */
    private function ligneAgent(ConnectionInterface $connexion, string $table, array $colonnes, User $user): ?array
    {
        $select = [];
        foreach ($colonnes as $champ => $nom) {
            if ($nom !== null) {
                $select[] = $nom . ' as ' . $champ;
            }
        }

        if ($select === []) {
            return null;
        }

        $email = mb_strtolower(trim((string) $user->email));
        $ligne = $colonnes['email'] !== null
            ? $connexion->table($table)->select($select)->whereRaw('LOWER(' . $this->identifiant($connexion, $colonnes['email']) . ') = ?', [$email])->first()
            : null;

        if ($ligne === null && $colonnes['matricule'] !== null && trim((string) $user->im) !== '') {
            $ligne = $connexion->table($table)->select($select)
                ->whereRaw('LOWER(' . $this->identifiant($connexion, $colonnes['matricule']) . ') = ?', [mb_strtolower(trim((string) $user->im))])
                ->first();
        }

        if ($ligne === null) {
            return null;
        }

        $donnees = [];
        foreach ((array) $ligne as $cle => $valeur) {
            $donnees[mb_strtolower((string) $cle)] = $valeur;
        }

        return $donnees;
    }

    /** Nom de colonne protégé pour une expression SQL brute (LOWER(...)). */
    private function identifiant(ConnectionInterface $connexion, string $colonne): string
    {
        return $connexion->getQueryGrammar()->wrap($colonne);
    }

    /** @param array<string, mixed> $ligne */
    private function actif(array $ligne): bool
    {
        $valeur = $ligne['actif'] ?? null;

        if ($valeur === null) {
            return true; // colonne absente ou vide : l'agent est considéré en poste
        }

        // Colonne booléenne : le test doit précéder la conversion en texte (false donnerait « »).
        if (is_bool($valeur)) {
            return $valeur;
        }

        $texte = trim((string) $valeur);

        if ($texte === '') {
            return true;
        }

        return ! in_array(mb_strtoupper($texte), ['0', 'N', 'NON', 'FALSE', 'INACTIF'], true);
    }

    /** @param array<string, mixed> $ligne */
    private function texte(array $ligne, string $champ): ?string
    {
        $valeur = trim((string) ($ligne[$champ] ?? ''));

        return $valeur === '' ? null : $valeur;
    }
}
