<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Alerte;
use App\Models\BaseRisque;
use App\Models\User;
use App\Services\AnomalieAnalyseur;
use App\Services\FormulesRegles;
use App\Services\ReglesDetection;
use App\Services\VerrouAlerte;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;
use Illuminate\Validation\Rule;

/**
 * Alertes d'anomalies (défaillance de déclaration, baisse du chiffre d'affaires).
 *
 *  - Consultation et traitement : tous les rôles.
 *      agent / admin        → uniquement les alertes de LEUR centre ;
 *      central / superadmin → tous les centres.
 *  - Lancer l'analyse : superadmin, central, admin (l'admin est limité à son centre).
 *  - Traitement d'un dossier : « Traiter » PREND le dossier (statut affiché « En traitement ») ; les autres utilisateurs ne peuvent
 *    plus le prendre (verrou VerrouAlerte). Un admin, central ou superadmin peut reprendre ou libérer un dossier pris.
 */
class DefaillanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'regle' => ['nullable', Rule::in(array_merge(Alerte::REGLES, ReglesDetection::codesPersonnalises()))],
            'niveau' => ['nullable', Rule::in(Alerte::NIVEAUX)],
            'type_impot' => ['nullable', Rule::in(config('anomalies.defaillance.impots'))],
            'statut' => ['nullable', Rule::in([Alerte::A_TRAITER, 'en_traitement', Alerte::TRAITEE, Alerte::REGULARISEE])],
            'annee_fiscale' => ['nullable', 'integer', 'between:1990,2100'],
            'centre_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(['niveau', 'type_impot', 'annee_fiscale', 'traite_le'])],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        $query = $this->scopedQuery($request)->with(['contribuable.centre', 'traitePar']);

        foreach (['regle', 'niveau', 'type_impot', 'annee_fiscale'] as $champ) {
            if (! empty($data[$champ])) {
                $query->where('alertes.' . $champ, $data[$champ]);
            }
        }

        // « À traiter » = dossiers LIBRES ; « En traitement » = dossiers pris par un agent
        if (($data['statut'] ?? '') === Alerte::A_TRAITER) {
            VerrouAlerte::libres($query);
        } elseif (($data['statut'] ?? '') === 'en_traitement') {
            VerrouAlerte::verrouillees($query);
        } elseif (! empty($data['statut'])) {
            $query->where('alertes.statut', $data['statut']);
        }

        if (! empty($data['search'])) {
            $term = '%' . trim($data['search']) . '%';
            $query->whereHas('contribuable', function ($q) use ($term) {
                // ILIKE : insensible à la casse (PostgreSQL)
                $q->where('nom', 'ilike', $term)->orWhere('nif', 'ilike', $term);
            });
        }

        // Tri par défaut : les plus risquées d'abord, puis les plus récentes.
        $dir = ($data['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $sort = $data['sort'] ?? 'niveau';

        if ($sort === 'niveau') {
            $query->orderByRaw("CASE alertes.niveau WHEN 'eleve' THEN 3 WHEN 'moyen' THEN 2 ELSE 1 END {$dir}")
                ->orderBy('alertes.annee_fiscale', 'desc');
        } else {
            $query->orderBy('alertes.' . $sort, $dir);
        }
        $query->orderBy('alertes.id', 'desc');

        $page = $query->paginate(10);
        $noms = $this->nomsPreneurs($page->getCollection());

        return response()->json($page->through(fn (Alerte $a) => $this->present($a, $noms, $request->user())));
    }

    /** Compteurs pour les cartes du haut de page (alertes à traiter par niveau + totaux). */
    public function summary(Request $request): JsonResponse
    {
        $rows = $this->scopedQuery($request)
            ->selectRaw('alertes.statut, alertes.niveau, count(*) as total')
            ->groupBy('alertes.statut', 'alertes.niveau')
            ->get();

        $summary = ['a_traiter' => 0, 'eleve' => 0, 'moyen' => 0, 'faible' => 0, 'traitees' => 0, 'regularisees' => 0];

        foreach ($rows as $r) {
            $n = (int) $r->total;

            if ($r->statut === Alerte::A_TRAITER) {
                $summary['a_traiter'] += $n;
                $summary[$r->niveau] += $n;
            } elseif ($r->statut === Alerte::TRAITEE) {
                $summary['traitees'] += $n;
            } else {
                $summary['regularisees'] += $n;
            }
        }

        // Dossiers pris : retirés de « à traiter » (et de leur niveau), comptés à part
        $summary['en_traitement'] = 0;
        $pris = $this->scopedQuery($request);
        VerrouAlerte::verrouillees($pris);
        foreach ($pris->selectRaw('alertes.niveau, count(*) as total')->groupBy('alertes.niveau')->get() as $r) {
            $n = (int) $r->total;
            $summary['a_traiter'] -= $n;
            $summary[$r->niveau] -= $n;
            $summary['en_traitement'] += $n;
        }

        return response()->json($summary + [
            'derniere_analyse' => Cache::get(AnomalieAnalyseur::CACHE_DERNIERE_ANALYSE),
        ]);
    }

    /** EXF-008 : marquer une alerte comme traitée (ou la rouvrir) avec un commentaire libre. */
    public function update(Request $request, Alerte $alerte): JsonResponse
    {
        $me = $request->user();
        $this->assertAccess($me, $alerte);

        $data = $request->validate([
            'statut' => ['sometimes', Rule::in([Alerte::A_TRAITER, Alerte::TRAITEE])],
            'commentaire' => ['nullable', 'string', 'max:2000'],
        ]);

        // Dossier pris par un autre utilisateur : modification refusée (sauf admin, central, superadmin)
        if (VerrouAlerte::verrouille($alerte) && (int) $alerte->getAttribute('pris_par') !== $me->id && ! $this->peutReprendre($me)) {
            return response()->json(['message' => 'Ce dossier est en cours de traitement par ' . $this->nomPreneur($alerte) . '.'], 423);
        }

        if (array_key_exists('statut', $data) && $alerte->statut === Alerte::REGULARISEE) {
            return response()->json([
                'message' => "Cette alerte est régularisée (l'anomalie n'est plus constatée) : son statut ne peut pas être modifié.",
            ], 422);
        }

        if (array_key_exists('commentaire', $data)) {
            $alerte->commentaire = trim((string) $data['commentaire']) !== '' ? trim((string) $data['commentaire']) : null;
        }

        if (array_key_exists('statut', $data) && $data['statut'] !== $alerte->statut) {
            $alerte->statut = $data['statut'];
            $alerte->traite_par = $data['statut'] === Alerte::TRAITEE ? $me->id : null;
            $alerte->traite_le = $data['statut'] === Alerte::TRAITEE ? now() : null;
        }

        // Le verrou suit le traitement : prolongé pendant que l'on travaille, levé quand le dossier est traité ou rouvert
        if ($alerte->statut === Alerte::A_TRAITER && (int) $alerte->getAttribute('pris_par') === $me->id) {
            $alerte->forceFill(['pris_le' => now()]);
        } elseif ($alerte->statut !== Alerte::A_TRAITER || array_key_exists('statut', $data)) {
            $alerte->forceFill(['pris_par' => null, 'pris_le' => null]);
        }

        $alerte->save();

        return response()->json($this->present($alerte->load(['contribuable.centre', 'traitePar']), [], $me));
    }

    /**
     * « Traiter » : PREND le dossier. L'opération est atomique (deux agents cliquant en même temps : un seul gagne).
     * Dossier déjà pris par un autre : 409, sauf si un admin, central ou superadmin envoie « forcer » (reprise).
     */
    public function prendre(Request $request, Alerte $alerte): JsonResponse
    {
        $me = $request->user();
        $this->assertAccess($me, $alerte);

        $forcer = $request->boolean('forcer') && $this->peutReprendre($me);

        $n = Alerte::query()->whereKey($alerte->id)->where('statut', Alerte::A_TRAITER)
            ->where(function ($q) use ($me, $forcer) {
                if ($forcer) {
                    return;
                }
                $q->whereNull('pris_par')->orWhereNull('pris_le')->orWhere('pris_par', $me->id)->orWhere('pris_le', '<=', VerrouAlerte::limite());
            })
            ->update(['pris_par' => $me->id, 'pris_le' => now()]);

        $alerte = $alerte->fresh(['contribuable.centre', 'traitePar']);

        if ($n === 0) {
            $message = match (true) {
                $alerte->statut === Alerte::REGULARISEE => "Cette alerte est régularisée (l'anomalie n'est plus constatée) : il n'y a plus rien à traiter.",
                $alerte->statut === Alerte::TRAITEE => 'Ce dossier est déjà traité.',
                default => 'Ce dossier est en cours de traitement par ' . $this->nomPreneur($alerte) . '.',
            };

            return response()->json(['message' => $message, 'alerte' => $this->present($alerte, [], $me)], $alerte->statut === Alerte::A_TRAITER ? 409 : 422);
        }

        return response()->json($this->present($alerte, [], $me));
    }

    /** Libère un dossier pris (par son propriétaire, ou un admin, central, superadmin) : il redevient « À traiter » pour tous. */
    public function liberer(Request $request, Alerte $alerte): JsonResponse
    {
        $me = $request->user();
        $this->assertAccess($me, $alerte);

        if (VerrouAlerte::verrouille($alerte) && (int) $alerte->getAttribute('pris_par') !== $me->id && ! $this->peutReprendre($me)) {
            return response()->json(['message' => 'Ce dossier est en cours de traitement par ' . $this->nomPreneur($alerte) . '.'], 423);
        }

        $alerte->forceFill(['pris_par' => null, 'pris_le' => null])->save();

        return response()->json($this->present($alerte->load(['contribuable.centre', 'traitePar']), [], $me));
    }

    /**
     * Page de traitement : l'alerte, ce qui l'explique (valeurs de la base d'analyse risque par année, formule de la règle)
     * et l'état du verrou. L'historique des déclarations et les autres alertes viennent de /contribuables/{id}.
     */
    public function dossier(Request $request, Alerte $alerte): JsonResponse
    {
        $me = $request->user();
        $this->assertAccess($me, $alerte);
        $alerte->load(['contribuable.centre', 'traitePar']);

        $indicateurs = ['IR' => [], 'TVA' => [], 'RECOUPEMENT' => [], 'DAU' => []];
        try {
            $rows = BaseRisque::query()
                ->where('contribuable_id', $alerte->contribuable_id)
                ->where('annee_fiscale', '>=', $alerte->annee_fiscale - 2)
                ->orderBy('annee_fiscale')->orderBy('mois')
                ->get();

            foreach ($rows as $r) {
                $valeurs = [];
                foreach (BaseRisque::COLONNES[$r->source] ?? [] as $c) {
                    $valeurs[$c] = $r->{$c} !== null ? (float) $r->{$c} : null;
                }
                $indicateurs[$r->source][] = ['annee' => (int) $r->annee_fiscale, 'mois' => (int) $r->mois, 'valeurs' => $valeurs];
            }
        } catch (Throwable) {
            // base d'analyse risque pas encore créée : on affiche le reste du dossier
        }

        $formule = null;
        if (in_array($alerte->regle, ReglesDetection::CODES, true)) {
            $formule = FormulesRegles::pour($alerte->regle, ReglesDetection::parametres($alerte->regle));
        }

        return response()->json([
            'alerte' => $this->present($alerte, [], $me),
            'indicateurs' => $indicateurs,
            'formule' => $formule,
            'verrou_minutes' => VerrouAlerte::DUREE_MINUTES,
        ]);
    }

    /** Lance le moteur de détection (superadmin, central, admin) à la date indiquée (aujourd'hui par défaut). */
    public function analyser(Request $request): JsonResponse
    {
        $me = $request->user();

        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $date = isset($data['date']) ? new DateTimeImmutable($data['date']) : AnomalieAnalyseur::aujourdhui();

        // L'admin n'analyse que son propre centre.
        $centreId = null;
        if ($me->role === Role::Admin) {
            abort_if($me->centre_id === null, 403, "Votre compte n'est rattaché à aucun centre.");
            $centreId = $me->centre_id;
        }

        $stats = app(AnomalieAnalyseur::class)->analyser($date, $centreId);

        return response()->json($stats + [
            'message' => sprintf(
                "Analyse terminée : %d nouvelle(s) alerte(s) (%d défaillance(s), %d baisse(s) du chiffre d'affaires), %d mise(s) à jour, %d régularisée(s).",
                $stats['crees'],
                $stats['defaillance']['crees'],
                $stats['baisse_ca']['crees'],
                $stats['mis_a_jour'],
                $stats['regularisees']
            ) . ($stats['desactivees'] !== [] ? ' Règle(s) désactivée(s) dans les réglages : ' . implode(', ', $stats['desactivees']) . '.' : ''),
        ]);
    }

    // ------------------------------------------------------------------

    /** Alertes visibles par l'utilisateur connecté (filtrage par centre selon son rôle). */
    private function scopedQuery(Request $request): Builder
    {
        $me = $request->user();
        abort_if($me->role === null, 403, "Votre compte n'a pas de fonction attribuée.");

        $query = Alerte::query();
        ReglesDetection::limiterAlertes($query); // règles désactivées ou impôts décochés : alertes masquées

        if (in_array($me->role, [Role::Agent, Role::Admin], true)) {
            abort_if($me->centre_id === null, 403, "Votre compte n'est rattaché à aucun centre.");
            $query->whereHas('contribuable', fn ($q) => $q->where('centre_id', $me->centre_id));
        } elseif ($request->filled('centre_id')) {
            $query->whereHas('contribuable', fn ($q) => $q->where('centre_id', (int) $request->input('centre_id')));
        }

        return $query;
    }

    private function assertAccess(User $me, Alerte $alerte): void
    {
        abort_if($me->role === null, 403, "Votre compte n'a pas de fonction attribuée.");

        if (in_array($me->role, [Role::Agent, Role::Admin], true)) {
            $alerte->loadMissing('contribuable');
            abort_if(
                $me->centre_id === null || $alerte->contribuable->centre_id !== $me->centre_id,
                403,
                "Cette alerte n'appartient pas à votre centre."
            );
        }
    }

    /** Admin, central et superadmin peuvent reprendre ou libérer un dossier pris par un autre. */
    private function peutReprendre(User $me): bool
    {
        return in_array($me->role, [Role::Superadmin, Role::Central, Role::Admin], true);
    }

    private function nomPreneur(Alerte $a): string
    {
        $id = $a->getAttribute('pris_par');

        return ($id !== null ? User::find($id)?->nom : null) ?? 'un autre utilisateur';
    }

    /** Noms des utilisateurs qui ont pris les dossiers d'une page (une seule requête). @return array<int, string> */
    private function nomsPreneurs($alertes): array
    {
        $ids = $alertes->pluck('pris_par')->filter()->unique()->all();

        return $ids === [] ? [] : User::whereIn('id', $ids)->pluck('nom', 'id')->all();
    }

    /**
     * @param array<int, string> $noms  id => nom des preneurs (évite une requête par alerte)
     * @return array<string, mixed>
     */
    private function present(Alerte $a, array $noms = [], ?User $me = null): array
    {
        $pris = VerrouAlerte::verrouille($a);
        $prisPar = $a->getAttribute('pris_par');

        return [
            'id' => $a->id,
            'contribuable' => [
                'id' => $a->contribuable->id,
                'nif' => $a->contribuable->nif,
                'nom' => $a->contribuable->nom,
                'centre' => $a->contribuable->centre?->nom,
            ],
            'regle' => $a->regle,
            'type_impot' => $a->type_impot,
            'annee_fiscale' => $a->annee_fiscale,
            'mois' => $a->mois,
            'date_limite' => $a->date_limite?->toDateString(),
            'periodes_manquees' => $a->periodes_manquees,
            'periodes_declarees' => $a->periodes_declarees,
            'niveau' => $a->niveau,
            'motif' => $a->motif,
            'details' => $a->details,
            'statut' => $pris ? 'en_traitement' : $a->statut,
            'commentaire' => $a->commentaire,
            'traite_par' => $a->traitePar?->nom,
            'traite_le' => $a->traite_le?->toIso8601String(),
            'pris_par' => $pris ? ($noms[$prisPar] ?? $this->nomPreneur($a)) : null,
            'pris_le' => $pris ? Carbon::parse($a->getAttribute('pris_le'))->toIso8601String() : null,
            'pris_par_moi' => $pris && $me !== null && (int) $prisPar === $me->id,
        ];
    }
}
