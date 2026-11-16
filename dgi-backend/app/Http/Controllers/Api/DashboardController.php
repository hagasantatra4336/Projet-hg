<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Alerte;
use App\Models\Centre;
use App\Models\DossierHistorique;
use App\Models\Fonction;
use App\Models\User;
use App\Services\AnomalieAnalyseur;
use App\Services\ReglesDetection;
use App\Services\VerrouAlerte;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Tableau de bord : chiffres clés, anomalies par mois, derniers dossiers clôturés,
 * utilisateurs ayant clôturé le plus récemment et journal des actions de l'utilisateur connecté.
 *
 * Le périmètre suit celui des listes de dossiers :
 *  - agent : ses dossiers assignés ou déjà clôturés ;
 *  - admin : tous les dossiers de son centre ;
 *  - central / superadmin : tous les centres.
 */
class DashboardController extends Controller
{
    /** Nombre d'exercices proposés dans le graphique mensuel. */
    private const NB_ANNEES = 3;

    /** Nombre de lignes affichées dans les listes courtes. */
    private const NB_RECENTS = 6;

    /** Nombre d'opérations personnelles affichées. */
    private const NB_HISTORIQUE = 10;

    public function __invoke(Request $request): JsonResponse
    {
        $me = $request->user();
        abort_if($me->role === null, 403, "Votre compte n'a pas de fonction attribuée.");

        $base = $this->scopedQuery($me);

        return response()->json([
            'totaux' => $this->totaux($me, $base),
            'anomalies_par_mois' => $this->anomaliesParMois($base),
            'clotureurs' => $this->clotureurs($base),
            'derniers_dossiers' => $this->derniersDossiers($me),
            'historique' => $this->historique($me),
            'derniere_analyse' => Cache::get(AnomalieAnalyseur::CACHE_DERNIERE_ANALYSE),
        ]);
    }

    /**
     * Chiffres clés du périmètre. Les totaux « centres » et « utilisateurs » sont hors périmètre
     * d'un agent (null) : le front masque alors ces cartes.
     *
     * @return array<string, int|null>
     */
    private function totaux(User $me, Builder $base): array
    {
        $libres = (clone $base);
        VerrouAlerte::libres($libres);

        $prises = (clone $base);
        VerrouAlerte::verrouillees($prises);

        $dossiersLibres = (clone $base);
        VerrouAlerte::libres($dossiersLibres);

        return [
            'centres' => $this->nombreCentres($me),
            'utilisateurs' => $this->nombreUtilisateurs($me),
            'fonctions' => $me->role === Role::Superadmin ? Fonction::count() : null,
            'anomalies_a_traiter' => $libres->count(),
            'anomalies_en_traitement' => $prises->count(),
            'anomalies_traitees' => (clone $base)
                ->whereIn('alertes.statut', [Alerte::TRAITEE, Alerte::REGULARISEE])
                ->count(),
            'anomalies_total' => (clone $base)->count(),
            'dossiers_a_traiter' => $dossiersLibres->distinct()->count('alertes.contribuable_id'),
        ];
    }

    private function nombreCentres(User $me): ?int
    {
        if (in_array($me->role, [Role::Superadmin, Role::Central], true)) {
            return Centre::count();
        }

        return $me->role === Role::Admin ? 1 : null;
    }

    private function nombreUtilisateurs(User $me): ?int
    {
        if (in_array($me->role, [Role::Superadmin, Role::Central], true)) {
            return User::count();
        }

        if ($me->role === Role::Admin) {
            if ($me->centre_id === null) {
                return 0;
            }

            // Le chef de centre ne gère que les agents de son centre (mêmes règles que UserController).
            return User::query()
                ->where('centre_id', $me->centre_id)
                ->whereHas('fonction', fn ($q) => $q->where('role', Role::Agent))
                ->count();
        }

        return null;
    }

    /**
     * Répartition des anomalies par mois et par exercice.
     *
     * Les anomalies mensuelles (TVA) portent déjà leur mois. Les anomalies annuelles (défaut de dépôt)
     * sont rattachées au mois de leur échéance ; à défaut, au mois de décembre (clôture de l'exercice).
     *
     * @return array{annees: array<int, int>, series: array<int, array{annee: int, mois: int, total: int}>}
     */
    private function anomaliesParMois(Builder $base): array
    {
        $mensuelles = (clone $base)
            ->whereNotNull('alertes.mois')
            ->whereBetween('alertes.mois', [1, 12])
            ->selectRaw('alertes.annee_fiscale as annee, alertes.mois as mois, count(*) as total')
            ->groupBy('alertes.annee_fiscale', 'alertes.mois')
            ->get();

        // Regroupement par jour d'échéance (peu de valeurs distinctes) : le mois est calculé en PHP
        // pour rester portable entre SQLite, PostgreSQL et Oracle.
        $annuelles = (clone $base)
            ->where(fn ($q) => $q->whereNull('alertes.mois')->orWhere('alertes.mois', 0))
            ->selectRaw('alertes.annee_fiscale as annee, alertes.date_limite as date_limite, count(*) as total')
            ->groupBy('alertes.annee_fiscale', 'alertes.date_limite')
            ->get();

        /** @var array<string, int> $totaux clé « annee-mois » => nombre d'anomalies */
        $totaux = [];

        foreach ($mensuelles as $ligne) {
            $cle = (int) $ligne->annee.'-'.(int) $ligne->mois;
            $totaux[$cle] = ($totaux[$cle] ?? 0) + (int) $ligne->total;
        }

        foreach ($annuelles as $ligne) {
            $mois = $ligne->date_limite ? (int) Carbon::parse($ligne->date_limite)->format('n') : 12;
            $cle = (int) $ligne->annee.'-'.$mois;
            $totaux[$cle] = ($totaux[$cle] ?? 0) + (int) $ligne->total;
        }

        $annees = array_values(array_unique(array_map(
            fn (string $cle) => (int) explode('-', $cle)[0],
            array_keys($totaux)
        )));
        rsort($annees);
        $annees = array_slice($annees, 0, self::NB_ANNEES);

        $series = [];
        foreach ($annees as $annee) {
            for ($mois = 1; $mois <= 12; $mois++) {
                $series[] = [
                    'annee' => $annee,
                    'mois' => $mois,
                    'total' => $totaux[$annee.'-'.$mois] ?? 0,
                ];
            }
        }

        return ['annees' => $annees, 'series' => $series];
    }

    /**
     * Utilisateurs qui ont clôturé le plus récemment : nombre de dossiers et d'anomalies traités.
     *
     * @return array<int, array<string, mixed>>
     */
    private function clotureurs(Builder $base): array
    {
        $lignes = (clone $base)
            ->whereIn('alertes.statut', [Alerte::TRAITEE, Alerte::REGULARISEE])
            ->whereNotNull('alertes.traite_par')
            ->selectRaw(
                'alertes.traite_par as user_id, '
                .'count(distinct alertes.contribuable_id) as dossiers, '
                .'count(*) as anomalies, '
                .'max(alertes.traite_le) as derniere'
            )
            ->groupBy('alertes.traite_par')
            ->orderByDesc('derniere')
            ->limit(self::NB_RECENTS)
            ->get();

        $users = User::with(['centre', 'fonction'])
            ->whereIn('id', $lignes->pluck('user_id')->filter()->all())
            ->get()
            ->keyBy('id');

        return $lignes->map(function ($ligne) use ($users): array {
            $user = $users->get((int) $ligne->user_id);

            return [
                'id' => (int) $ligne->user_id,
                'nom' => $user?->nom ?? 'Utilisateur supprimé',
                'centre' => $user?->centre?->nom,
                'role' => $user?->role?->value,
                'dossiers' => (int) $ligne->dossiers,
                'anomalies' => (int) $ligne->anomalies,
                'derniere_cloture' => $ligne->derniere ? Carbon::parse($ligne->derniere)->toIso8601String() : null,
            ];
        })->values()->all();
    }

    /**
     * Derniers dossiers clôturés, avec leur auteur (journal des dossiers).
     *
     * @return array<int, array<string, mixed>>
     */
    private function derniersDossiers(User $me): array
    {
        $query = DossierHistorique::query()
            ->with(['contribuable.centre'])
            ->where('action', DossierHistorique::CLOTURE)
            ->orderByDesc('id')
            ->limit(self::NB_RECENTS);

        if ($me->role === Role::Agent) {
            $query->where('user_id', $me->id);
        } elseif ($me->role === Role::Admin) {
            $query->whereHas('contribuable', fn ($q) => $q->where('centre_id', $me->centre_id));
        }

        return $query->get()->map(fn (DossierHistorique $h): array => [
            'id' => $h->id,
            'contribuable' => [
                'id' => $h->contribuable?->id,
                'nom' => $h->contribuable?->nom,
                'nif' => $h->contribuable?->nif,
                'centre' => $h->contribuable?->centre?->nom,
            ],
            'auteur' => $h->auteur,
            'detail' => $h->detail,
            'statut_apres' => $h->statut_apres,
            'le' => $h->created_at?->toIso8601String(),
        ])->all();
    }

    /**
     * Journal des opérations faites par l'utilisateur connecté (prise, reprise, affectation,
     * libération, clôture, réouverture), la plus récente d'abord.
     *
     * @return array<int, array<string, mixed>>
     */
    private function historique(User $me): array
    {
        return DossierHistorique::query()
            ->with('contribuable')
            ->where('user_id', $me->id)
            ->orderByDesc('id')
            ->limit(self::NB_HISTORIQUE)
            ->get()
            ->map(fn (DossierHistorique $h): array => [
                'id' => $h->id,
                'action' => $h->action,
                'detail' => $h->detail,
                'contribuable' => [
                    'nom' => $h->contribuable?->nom,
                    'nif' => $h->contribuable?->nif,
                ],
                'statut_avant' => $h->statut_avant,
                'statut_apres' => $h->statut_apres,
                'le' => $h->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /** Alertes visibles par l'utilisateur, dans son périmètre (mêmes règles que les listes de dossiers). */
    private function scopedQuery(User $me): Builder
    {
        $query = Alerte::query();
        ReglesDetection::limiterAlertes($query); // règles désactivées ou impôts décochés : alertes masquées

        if ($me->role === Role::Agent) {
            // Dossiers assignés à l'agent ou qu'il a clôturés (suivi de ses anciens dossiers).
            $query->whereIn(
                'alertes.contribuable_id',
                Alerte::query()
                    ->select('contribuable_id')
                    ->where(fn ($q) => $q->where('alertes.pris_par', $me->id)->orWhere('alertes.traite_par', $me->id))
            );
        } elseif ($me->role === Role::Admin) {
            if ($me->centre_id === null) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereHas('contribuable', fn ($q) => $q->where('centre_id', $me->centre_id));
            }
        }

        return $query;
    }
}
