<?php

namespace App\Services;

use App\Models\Indicateur;
use App\Models\Regle;
use App\Models\RegleHistorique;
use App\Models\RegleSimulation;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Cycle de vie d'une règle personnalisée :  brouillon → simulation → activation  (puis désactivation, modification, nouvelle simulation...).
 *
 *  - Une règle se crée à partir d'un MODÈLE (ModelesRegles) : elle naît en BROUILLON et ne crée aucune alerte.
 *  - La SIMULATION calcule ce que la règle donnerait sur les données actuelles, sans créer d'alerte, et garde le résultat.
 *  - L'ACTIVATION n'est possible que si la dernière simulation réussie porte sur la définition ACTUELLE de la règle
 *    (même empreinte) : toute modification invalide la simulation.
 *  - Une règle active ou désactivée se modifie en la repassant d'abord hors service : on ne change pas une règle qui produit des alertes.
 *  - Chaque étape est inscrite dans regles_historique (qui, quand, avant, après).
 */
class ReglesPersonnalisees
{
    public const NIVEAUX = ['faible', 'moyen', 'eleve'];

    public function __construct(private readonly DetecteurPersonnalise $detecteur)
    {
    }

    /** Codes d'indicateurs utilisables dans les conditions. @return array<int, string> */
    public static function indicateursActifs(): array
    {
        return Indicateur::where('actif', true)->pluck('code')->all();
    }

    /** @param array<string, mixed> $params  @param array<int, string> $impots */
    public function creerDepuisModele(User $user, string $modele, string $nom, array $params, array $impots, ?string $niveau = null): Regle
    {
        if (! ModelesRegles::existe($modele)) {
            throw ValidationException::withMessages(['modele' => 'Modèle inconnu.']);
        }

        $conditions = ModelesRegles::construire($modele, $params);
        $niveau ??= ModelesRegles::niveauDefaut($modele);
        $this->verifier($nom, $conditions, $impots, $niveau);

        return DB::transaction(function () use ($user, $modele, $nom, $conditions, $impots, $niveau) {
            $regle = Regle::create([
                'code' => 'tmp_' . Str::random(10), // remplacé juste après par perso_<id>
                'type' => Regle::PERSONNALISEE,
                'modele' => $modele,
                'statut' => Regle::BROUILLON,
                'nom' => $nom,
                'description' => collect(ModelesRegles::catalogue())->firstWhere('code', $modele)['description'] ?? null,
                'actif' => false,
                'parametres' => ['fenetre_annees' => 1],
                'conditions' => $conditions,
                'impots' => array_values(array_unique($impots)),
                'niveau' => $niveau,
                'version' => 1,
                'cree_par' => $user->id,
                'modifie_par' => $user->id,
            ]);
            $regle->update(['code' => 'perso_' . $regle->id, 'empreinte' => self::empreinte($regle)]);

            $this->journal($regle, $user, 'creation', [], $this->etat($regle));

            return $regle->fresh();
        });
    }

    /**
     * Modifie un brouillon ou une règle désactivée. Champs acceptés : nom, conditions, impots, niveau, fenetre_annees.
     *
     * @param array<string, mixed> $data
     */
    public function mettreAJour(Regle $regle, User $user, array $data): Regle
    {
        $this->exigerPersonnalisee($regle);

        if ($regle->statut === Regle::ACTIVE) {
            throw ValidationException::withMessages(['statut' => 'Désactivez la règle avant de la modifier.']);
        }

        $avant = $this->etat($regle);

        $nom = (string) ($data['nom'] ?? $regle->nom);
        $conditions = (array) ($data['conditions'] ?? $regle->conditions);
        $impots = (array) ($data['impots'] ?? $regle->impots);
        $niveau = (string) ($data['niveau'] ?? $regle->niveau);
        $fenetre = (int) ($data['fenetre_annees'] ?? ($regle->parametres['fenetre_annees'] ?? 1));

        if ($fenetre < 0 || $fenetre > 5) {
            throw ValidationException::withMessages(['fenetre_annees' => 'Entre 0 et 5 exercices.']);
        }
        $this->verifier($nom, $conditions, $impots, $niveau);

        $regle->fill([
            'nom' => $nom,
            'conditions' => $conditions,
            'impots' => array_values(array_unique($impots)),
            'niveau' => $niveau,
            'parametres' => ['fenetre_annees' => $fenetre],
            'statut' => Regle::BROUILLON, // une règle modifiée repasse en brouillon : nouvelle simulation obligatoire
            'actif' => false,
            'modifie_par' => $user->id,
        ]);
        $regle->version = $regle->version + 1;
        $regle->empreinte = self::empreinte($regle);
        $regle->save();

        $this->journal($regle, $user, 'modification', $avant, $this->etat($regle));

        return $regle->fresh();
    }

    /**
     * Simule la règle : aucune alerte n'est créée ni modifiée. Le résultat (ou l'erreur) est enregistré.
     */
    public function simuler(Regle $regle, User $user, ?DateTimeInterface $date = null, ?int $centreId = null): RegleSimulation
    {
        $this->exigerPersonnalisee($regle);

        $date ??= AnomalieAnalyseur::aujourdhui();

        try {
            $this->verifier($regle->nom, (array) $regle->conditions, (array) $regle->impots, (string) $regle->niveau);
            $resultats = $this->detecteur->analyser($regle, $date, $centreId, simuler: true);
            $statut = 'reussie';
            $erreur = null;
        } catch (ValidationException $e) {
            [$statut, $resultats, $erreur] = ['erreur', null, implode(' ', collect($e->errors())->flatten()->all())];
        } catch (Throwable $e) {
            [$statut, $resultats, $erreur] = ['erreur', null, mb_substr($e->getMessage(), 0, 500)];
        }

        $simulation = RegleSimulation::create([
            'regle_id' => $regle->id,
            'user_id' => $user->id,
            'empreinte' => $regle->empreinte,
            'version' => $regle->version,
            'date_analyse' => $date->format('Y-m-d'),
            'centre_id' => $centreId,
            'statut' => $statut,
            'erreur' => $erreur,
            'resultats' => $resultats,
        ]);

        $this->journal($regle, $user, 'simulation', [], [
            'simulation_id' => $simulation->id,
            'statut' => $statut,
            'alertes' => $resultats['alertes'] ?? null,
            'analyses' => $resultats['analyses'] ?? null,
            'erreur' => $erreur,
        ]);

        return $simulation;
    }

    /** Dernière simulation réussie portant sur la définition actuelle de la règle (null s'il n'y en a pas). */
    public function simulationValide(Regle $regle): ?RegleSimulation
    {
        return $regle->simulations()
            ->where('empreinte', $regle->empreinte)
            ->where('statut', 'reussie')
            ->latest('id')
            ->first();
    }

    public function activer(Regle $regle, User $user): Regle
    {
        $this->exigerPersonnalisee($regle);

        if ($regle->statut === Regle::ACTIVE) {
            return $regle;
        }
        if ($this->simulationValide($regle) === null) {
            throw ValidationException::withMessages([
                'simulation' => 'Simulez la règle (dans sa version actuelle) avant de l\'activer : aucune simulation réussie ne correspond à sa définition.',
            ]);
        }
        $this->verifier($regle->nom, (array) $regle->conditions, (array) $regle->impots, (string) $regle->niveau);

        $avant = $this->etat($regle);
        $regle->update(['statut' => Regle::ACTIVE, 'actif' => true, 'modifie_par' => $user->id]);
        $this->journal($regle, $user, 'activation', $avant, $this->etat($regle));

        return $regle->fresh();
    }

    /** Met la règle hors service : ses alertes sont masquées (ni supprimées ni modifiées) et plus aucune n'est créée. */
    public function desactiver(Regle $regle, User $user): Regle
    {
        $this->exigerPersonnalisee($regle);

        $avant = $this->etat($regle);
        $regle->update(['statut' => Regle::DESACTIVEE, 'actif' => false, 'modifie_par' => $user->id]);
        $this->journal($regle, $user, 'desactivation', $avant, $this->etat($regle));

        return $regle->fresh();
    }

    /** Supprime un brouillon qui n'a jamais produit d'alerte. */
    public function supprimer(Regle $regle): void
    {
        $this->exigerPersonnalisee($regle);

        if ($regle->statut === Regle::ACTIVE) {
            throw ValidationException::withMessages(['statut' => 'Désactivez la règle avant de la supprimer.']);
        }
        if (\App\Models\Alerte::where('regle', $regle->code)->exists()) {
            throw ValidationException::withMessages(['statut' => 'Cette règle a produit des alertes : elle ne peut pas être supprimée (désactivez-la).']);
        }

        $regle->delete(); // historique et simulations supprimés en cascade
    }

    /** Empreinte de la définition : change dès qu'une condition, un impôt, le niveau ou la fenêtre change. */
    public static function empreinte(Regle $regle): string
    {
        $impots = array_values((array) $regle->impots);
        sort($impots);

        return sha1(json_encode([
            'conditions' => $regle->conditions,
            'impots' => $impots,
            'niveau' => $regle->niveau,
            'fenetre' => $regle->parametres['fenetre_annees'] ?? 1,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    // ------------------------------------------------------------------

    /** @param array<string, mixed> $conditions @param array<int, string> $impots */
    private function verifier(string $nom, array $conditions, array $impots, string $niveau): void
    {
        $erreurs = [];

        if (trim($nom) === '' || mb_strlen($nom) > 150) {
            $erreurs['nom'][] = 'Donnez un nom à la règle (150 caractères au plus).';
        }
        if (! in_array($niveau, self::NIVEAUX, true)) {
            $erreurs['niveau'][] = 'Niveau de risque invalide.';
        }
        $autorises = array_values((array) config('anomalies.defaillance.impots'));
        if ($impots === [] || array_diff($impots, $autorises) !== []) {
            $erreurs['impots'][] = 'Choisissez au moins un impôt parmi : ' . implode(', ', $autorises) . '.';
        }
        $e = EvaluateurConditions::verifier($conditions, self::indicateursActifs());
        if ($e !== []) {
            $erreurs['conditions'] = $e;
        }

        if ($erreurs !== []) {
            throw ValidationException::withMessages($erreurs);
        }
    }

    private function exigerPersonnalisee(Regle $regle): void
    {
        if (! $regle->estPersonnalisee()) {
            throw ValidationException::withMessages(['regle' => 'Cette opération ne concerne que les règles personnalisées.']);
        }
    }

    /** @return array<string, mixed> */
    private function etat(Regle $r): array
    {
        return [
            'statut' => $r->statut,
            'actif' => (bool) $r->actif,
            'version' => $r->version,
            'nom' => $r->nom,
            'niveau' => $r->niveau,
            'impots' => $r->impots,
            'conditions' => $r->conditions,
            'parametres' => $r->parametres,
        ];
    }

    /** @param array<string, mixed> $avant @param array<string, mixed> $apres */
    private function journal(Regle $regle, User $user, string $action, array $avant, array $apres): void
    {
        RegleHistorique::create([
            'regle_id' => $regle->id,
            'user_id' => $user->id,
            'action' => $action,
            'avant' => $avant,
            'apres' => $apres,
        ]);
    }
}
