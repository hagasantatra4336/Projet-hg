<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Alerte;
use App\Models\Centre;
use App\Models\Contribuable;
use App\Models\DossierHistorique;
use App\Models\User;
use App\Services\ReglesDetection;
use App\Services\VerrouAlerte;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Comparatif des centres : taux de clôture, délais, retards et charge par centre.
 *
 * Les indicateurs sont calculés avec les mêmes règles que les listes de dossiers :
 *  - un « dossier » regroupe les anomalies d'un même contribuable ;
 *  - « à traiter » ne compte que les dossiers libres (verrou de traitement expiré ou absent) ;
 *  - une anomalie régularisée n'est pas comptée comme traitée par un agent.
 *
 * Le calcul des délais se fait en PHP après agrégation par dossier, afin de rester
 * portable entre SQLite (tests), PostgreSQL (développement) et Oracle (production).
 */
class ComparatifCentresController extends Controller
{
    /** Un dossier ouvert depuis plus de N jours est considéré « en retard ». */
    private const SEUIL_RETARD = 30;

    public function __invoke(Request $request): JsonResponse
    {
        $me = $request->user();

        abort_if(
            ! in_array($me->role, [Role::Superadmin, Role::Central], true),
            403,
            'Le comparatif des centres est réservé au niveau central.'
        );

        $data = $request->validate([
            'annee_fiscale' => ['nullable', 'integer', 'between:1990,2100'],
            'regle' => ['nullable', Rule::in(array_merge(Alerte::REGLES, ReglesDetection::codesPersonnalises()))],
            'inclure_centraux' => ['nullable', 'boolean'],
        ]);

        $annee = ! empty($data['annee_fiscale']) ? (int) $data['annee_fiscale'] : null;
        $inclureCentraux = $request->boolean('inclure_centraux');

        $base = $this->scopedQuery($data);
        $annees = $this->anneesDisponibles($data);

        $compteurs = $this->compteursParCentre($base);
        $verrouillees = $this->verrouilleesParCentre($base);
        $dossiers = $this->dossiersParCentre($base);
        $reouvertures = $this->reouverturesParCentre($annee);
        $contribuables = $this->contribuablesParCentre();
        $agents = $this->agentsParCentre();

        $centres = Centre::query()
            ->when(! $inclureCentraux, fn (Builder $q) => $q->where('type_centre', '!=', Centre::TYPE_CENTRAL))
            ->orderBy('nom')
            ->get(['id', 'nom', 'code', 'abreviation', 'type_centre']);

        $lignes = [];

        foreach ($centres as $centre) {
            $lignes[(string) $centre->id] = $this->ligne(
                (int) $centre->id,
                $centre->nom,
                $centre->abreviation,
                $centre->code,
                (string) $centre->type_centre,
                $compteurs,
                $verrouillees,
                $dossiers,
                $reouvertures,
                $contribuables,
                $agents,
            );
        }

        // Contribuables sans centre : ils existent dans les données mais hors organigramme.
        $aDesOrphelins = collect($compteurs)->contains(fn (array $compteur): bool => $compteur['centre_id'] === null);

        if ($aDesOrphelins || isset($contribuables['sans'])) {
            $lignes['sans'] = $this->ligne(
                null,
                'Sans centre',
                null,
                null,
                null,
                $compteurs,
                $verrouillees,
                $dossiers,
                $reouvertures,
                $contribuables,
                $agents,
            );
        }

        $lignes = array_values($lignes);

        // Les centres en difficulté d'abord (taux le plus faible), puis les plus gros volumes.
        usort($lignes, function (array $a, array $b): int {
            $tauxA = $a['taux_cloture'] ?? 101;
            $tauxB = $b['taux_cloture'] ?? 101;

            return $tauxA <=> $tauxB ?: $b['dossiers_total'] <=> $a['dossiers_total'];
        });

        return response()->json([
            'annees' => $annees,
            'filtres' => [
                'annee_fiscale' => $annee,
                'regle' => $data['regle'] ?? null,
                'inclure_centraux' => $inclureCentraux,
            ],
            'totaux' => $this->totaux($lignes),
            'medianes' => [
                'taux_cloture' => $this->mediane(array_column($lignes, 'taux_cloture')),
                'delai_moyen_jours' => $this->mediane(array_column($lignes, 'delai_moyen_jours')),
                'attente_moyenne_jours' => $this->mediane(array_column($lignes, 'attente_moyenne_jours')),
            ],
            'centres' => $lignes,
        ]);
    }

    /**
     * Alertes visibles : le comparatif étant réservé au central et au superadmin,
     * le périmètre est national. Les règles désactivées restent masquées.
     *
     * @param  array<string, mixed>  $data
     */
    private function scopedQuery(array $data): Builder
    {
        $query = Alerte::query();
        ReglesDetection::limiterAlertes($query);

        if (! empty($data['annee_fiscale'])) {
            $query->where('alertes.annee_fiscale', (int) $data['annee_fiscale']);
        }

        if (! empty($data['regle'])) {
            $query->where('alertes.regle', $data['regle']);
        }

        return $query;
    }

    /**
     * Exercices proposés dans le filtre (indépendants de l'exercice sélectionné).
     *
     * @param  array<string, mixed>  $data
     * @return array<int, int>
     */
    private function anneesDisponibles(array $data): array
    {
        $query = Alerte::query();
        ReglesDetection::limiterAlertes($query);

        if (! empty($data['regle'])) {
            $query->where('alertes.regle', $data['regle']);
        }

        return $query->distinct()
            ->orderByDesc('alertes.annee_fiscale')
            ->pluck('alertes.annee_fiscale')
            ->map(fn ($annee) => (int) $annee)
            ->values()
            ->all();
    }

    /**
     * Nombre d'anomalies par centre et par statut.
     *
     * @return array<int, array{centre_id: int|null, statut: string, total: int}>
     */
    private function compteursParCentre(Builder $base): array
    {
        return (clone $base)
            ->join('contribuables', 'contribuables.id', '=', 'alertes.contribuable_id')
            ->selectRaw('contribuables.centre_id as centre_id, alertes.statut as statut, count(*) as total')
            ->groupBy('contribuables.centre_id', 'alertes.statut')
            ->get()
            ->map(fn ($ligne): array => [
                'centre_id' => $ligne->centre_id !== null ? (int) $ligne->centre_id : null,
                'statut' => (string) $ligne->statut,
                'total' => (int) $ligne->total,
            ])
            ->all();
    }

    /**
     * Anomalies à traiter actuellement prises par un agent (verrou non expiré).
     *
     * @return array<int, array{centre_id: int|null, total: int}>
     */
    private function verrouilleesParCentre(Builder $base): array
    {
        $query = (clone $base);
        VerrouAlerte::verrouillees($query);

        return $query->join('contribuables', 'contribuables.id', '=', 'alertes.contribuable_id')
            ->selectRaw('contribuables.centre_id as centre_id, count(*) as total')
            ->groupBy('contribuables.centre_id')
            ->get()
            ->map(fn ($ligne): array => [
                'centre_id' => $ligne->centre_id !== null ? (int) $ligne->centre_id : null,
                'total' => (int) $ligne->total,
            ])
            ->all();
    }

    /**
     * Agrégats par dossier (un contribuable = un dossier) pour calculer les délais et les retards.
     *
     * @return array<int, array<string, mixed>>
     */
    private function dossiersParCentre(Builder $base): array
    {
        return (clone $base)
            ->join('contribuables', 'contribuables.id', '=', 'alertes.contribuable_id')
            ->selectRaw(
                'contribuables.centre_id as centre_id, alertes.contribuable_id as contribuable_id, '
                .'MIN(alertes.created_at) as ouverture, MIN(alertes.pris_le) as prise, '
                .'MAX(alertes.traite_le) as cloture, '
                ."MAX(CASE WHEN alertes.statut = '".Alerte::A_TRAITER."' THEN 1 ELSE 0 END) as reste"
            )
            ->groupBy('contribuables.centre_id', 'alertes.contribuable_id')
            ->get()
            ->all();
    }

    /**
     * Dossiers réouverts par centre (indicateur de qualité : une clôture hâtive se paie ici).
     *
     * @return array<string, int>
     */
    private function reouverturesParCentre(?int $annee): array
    {
        $query = DossierHistorique::query()
            ->join('contribuables', 'contribuables.id', '=', 'dossier_historiques.contribuable_id')
            ->where('dossier_historiques.action', DossierHistorique::REOUVERTURE);

        if ($annee !== null) {
            $debut = Carbon::create($annee, 1, 1)->startOfDay();
            $query->where('dossier_historiques.created_at', '>=', $debut)
                ->where('dossier_historiques.created_at', '<', $debut->copy()->addYear());
        }

        $resultat = [];

        foreach ($query->selectRaw('contribuables.centre_id as centre_id, count(DISTINCT dossier_historiques.contribuable_id) as total')
            ->groupBy('contribuables.centre_id')
            ->get() as $ligne) {
            $resultat[$this->cle($ligne->centre_id)] = (int) $ligne->total;
        }

        return $resultat;
    }

    /**
     * Portefeuille de contribuables par centre (sert à la densité d'anomalies).
     *
     * @return array<string, int>
     */
    private function contribuablesParCentre(): array
    {
        $resultat = [];

        foreach (Contribuable::query()
            ->selectRaw('centre_id, count(*) as total')
            ->groupBy('centre_id')
            ->get() as $ligne) {
            $resultat[$this->cle($ligne->centre_id)] = (int) $ligne->total;
        }

        return $resultat;
    }

    /**
     * Agents actifs par centre (charge moyenne par agent).
     *
     * @return array<string, int>
     */
    private function agentsParCentre(): array
    {
        $resultat = [];

        $lignes = User::query()
            ->join('fonctions', 'fonctions.id', '=', 'users.fonction_id')
            ->where('fonctions.role', Role::Agent->value)
            ->whereNull('users.desactive_le')
            ->selectRaw('users.centre_id as centre_id, count(*) as total')
            ->groupBy('users.centre_id')
            ->get();

        foreach ($lignes as $ligne) {
            $resultat[$this->cle($ligne->centre_id)] = (int) $ligne->total;
        }

        return $resultat;
    }

    /**
     * Construit la ligne d'un centre à partir des agrégats.
     *
     * @param  array<int, array{centre_id: int|null, statut: string, total: int}>  $compteurs
     * @param  array<int, array{centre_id: int|null, total: int}>  $verrouillees
     * @param  array<int, array<string, mixed>>  $dossiers
     * @param  array<string, int>  $reouvertures
     * @param  array<string, int>  $contribuables
     * @param  array<string, int>  $agents
     * @return array<string, mixed>
     */
    private function ligne(
        ?int $id,
        string $nom,
        ?string $abreviation,
        ?string $code,
        ?string $typeCentre,
        array $compteurs,
        array $verrouillees,
        array $dossiers,
        array $reouvertures,
        array $contribuables,
        array $agents,
    ): array {
        $cle = $this->cle($id);

        $anomalies = [
            Alerte::A_TRAITER => 0,
            Alerte::TRAITEE => 0,
            Alerte::REGULARISEE => 0,
        ];

        foreach ($compteurs as $compteur) {
            if ($this->cle($compteur['centre_id']) !== $cle) {
                continue;
            }

            if (isset($anomalies[$compteur['statut']])) {
                $anomalies[$compteur['statut']] += $compteur['total'];
            }
        }

        $enTraitement = 0;

        foreach ($verrouillees as $verrou) {
            if ($this->cle($verrou['centre_id']) === $cle) {
                $enTraitement += $verrou['total'];
            }
        }

        $libres = max(0, $anomalies[Alerte::A_TRAITER] - $enTraitement);
        $seuilRetard = now()->subDays(self::SEUIL_RETARD);

        $delais = [];
        $attentes = [];
        $totalDossiers = 0;
        $ouverts = 0;
        $clotures = 0;
        $enRetard = 0;

        foreach ($dossiers as $dossier) {
            if ($this->cle($dossier->centre_id) !== $cle) {
                continue;
            }

            $ouverture = $dossier->ouverture ? Carbon::parse($dossier->ouverture) : null;

            if ($ouverture === null) {
                continue;
            }

            $totalDossiers++;

            if ($dossier->prise) {
                $attentes[] = max(0, $ouverture->diffInMinutes(Carbon::parse($dossier->prise)) / 1440);
            }

            if ((int) $dossier->reste === 1) {
                $ouverts++;

                if ($ouverture->lte($seuilRetard)) {
                    $enRetard++;
                }
            } elseif ($dossier->cloture) {
                $clotures++;
                $delais[] = max(0, $ouverture->diffInMinutes(Carbon::parse($dossier->cloture)) / 1440);
            }
        }

        $portefeuille = $contribuables[$cle] ?? 0;
        $nbAgents = $agents[$cle] ?? 0;
        $reouverturesCentre = $reouvertures[$cle] ?? 0;
        $denominateur = $ouverts + $clotures;

        return [
            'id' => $id,
            'nom' => $nom,
            'abreviation' => $abreviation,
            'code' => $code,
            'type_centre' => $typeCentre,
            'contribuables' => $portefeuille,
            'agents' => $nbAgents,
            'dossiers_total' => $totalDossiers,
            'dossiers_ouverts' => $ouverts,
            'dossiers_clotures' => $clotures,
            'dossiers_en_retard' => $enRetard,
            'anomalies_a_traiter' => $libres,
            'anomalies_en_traitement' => $enTraitement,
            'anomalies_traitees' => $anomalies[Alerte::TRAITEE],
            'anomalies_regularisees' => $anomalies[Alerte::REGULARISEE],
            'anomalies_total' => array_sum($anomalies),
            'taux_cloture' => $denominateur > 0 ? round(100 * $clotures / $denominateur, 1) : null,
            'delai_moyen_jours' => $this->moyenne($delais),
            'attente_moyenne_jours' => $this->moyenne($attentes),
            'densite' => $portefeuille > 0 ? round(100 * $libres / $portefeuille, 1) : null,
            'charge_par_agent' => $nbAgents > 0 ? round($libres / $nbAgents, 1) : null,
            'reouvertures' => $reouverturesCentre,
            'taux_reouverture' => $clotures > 0 ? round(100 * $reouverturesCentre / $clotures, 1) : null,
        ];
    }

    /**
     * Totaux du périmètre affiché (les centres sans activité comptent dans « centres »).
     *
     * @param  array<int, array<string, mixed>>  $lignes
     * @return array<string, int|float>
     */
    private function totaux(array $lignes): array
    {
        $somme = fn (string $champ): int|float => array_sum(array_column($lignes, $champ));

        $actifs = array_filter($lignes, fn (array $ligne): bool => $ligne['dossiers_total'] > 0);

        return [
            'centres' => count($lignes),
            'centres_actifs' => count($actifs),
            'centres_sans_activite' => count($lignes) - count($actifs),
            'dossiers_total' => (int) $somme('dossiers_total'),
            'dossiers_ouverts' => (int) $somme('dossiers_ouverts'),
            'dossiers_clotures' => (int) $somme('dossiers_clotures'),
            'dossiers_en_retard' => (int) $somme('dossiers_en_retard'),
            'anomalies_a_traiter' => (int) $somme('anomalies_a_traiter'),
            'anomalies_en_traitement' => (int) $somme('anomalies_en_traitement'),
            'anomalies_traitees' => (int) $somme('anomalies_traitees'),
            'anomalies_regularisees' => (int) $somme('anomalies_regularisees'),
            'anomalies_total' => (int) $somme('anomalies_total'),
        ];
    }

    /** Clé de regroupement : un centre, ou « sans » pour les contribuables orphelins. */
    private function cle(mixed $centreId): string
    {
        return $centreId === null ? 'sans' : (string) (int) $centreId;
    }

    /** Moyenne arrondie d'une liste de valeurs (null si la liste est vide). */
    private function moyenne(array $valeurs): ?float
    {
        return $valeurs === [] ? null : round(array_sum($valeurs) / count($valeurs), 1);
    }

    /**
     * Médiane d'une liste de valeurs, en ignorant les valeurs nulles.
     *
     * @param  array<int, int|float|null>  $valeurs
     */
    private function mediane(array $valeurs): ?float
    {
        $valeurs = array_values(array_filter($valeurs, fn ($valeur): bool => $valeur !== null));

        if ($valeurs === []) {
            return null;
        }

        sort($valeurs);
        $milieu = intdiv(count($valeurs), 2);

        $mediane = count($valeurs) % 2 === 1
            ? $valeurs[$milieu]
            : ($valeurs[$milieu - 1] + $valeurs[$milieu]) / 2;

        return round($mediane, 1);
    }
}
