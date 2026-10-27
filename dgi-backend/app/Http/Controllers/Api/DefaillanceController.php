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
            'grouper' => ['nullable', 'boolean'],
        ]);

        // Vue groupée (une ligne par contribuable) : demandée par la page Anomalies et la page Traitements.
        if ($request->boolean('grouper')) {
            return $this->indexGroupes($request, $data);
        }

        $query = $this->scopedQuery($request)->with(['contribuable.centre', 'traitePar']);
        $this->appliquerFiltres($query, $data);

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

    /**
     * Vue groupée : un « dossier contribuable » par ligne, contenant toutes ses anomalies visibles.
     * Les filtres portent sur les alertes : un contribuable n'apparaît que s'il a au moins une alerte correspondante,
     * et la ligne ne détaille que ces alertes.
     *
     * @param array<string, mixed> $data
     */
    private function indexGroupes(Request $request, array $data): JsonResponse
    {
        $base = $this->scopedQuery($request);
        $this->appliquerFiltres($base, $data);

        $dir = ($data['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $sort = $data['sort'] ?? 'niveau';

        $groupes = (clone $base)
            ->selectRaw("alertes.contribuable_id, "
                . "MAX(CASE alertes.niveau WHEN 'eleve' THEN 3 WHEN 'moyen' THEN 2 ELSE 1 END) as rang, "
                . 'MAX(alertes.annee_fiscale) as annee_max, MAX(alertes.traite_le) as traite_max, MAX(alertes.type_impot) as impot_max')
            ->groupBy('alertes.contribuable_id');

        match ($sort) {
            'annee_fiscale' => $groupes->orderBy('annee_max', $dir),
            'traite_le' => $groupes->orderBy('traite_max', $dir),
            'type_impot' => $groupes->orderBy('impot_max', $dir),
            default => $groupes->orderBy('rang', $dir)->orderBy('annee_max', 'desc'),
        };
        $groupes->orderBy('alertes.contribuable_id');

        $page = $groupes->paginate(10);
        $ids = array_map(fn ($id) => (int) $id, array_column($page->items(), 'contribuable_id'));

        $alertes = $ids === [] ? collect() : (clone $base)
            ->whereIn('alertes.contribuable_id', $ids)
            ->with(['contribuable.centre', 'traitePar'])
            ->orderByRaw("CASE alertes.niveau WHEN 'eleve' THEN 3 WHEN 'moyen' THEN 2 ELSE 1 END DESC")
            ->orderByDesc('alertes.annee_fiscale')
            ->orderByDesc('alertes.id')
            ->get()
            ->groupBy(fn (Alerte $a) => (int) $a->contribuable_id);

        $noms = $this->nomsPreneurs($alertes->flatten());

        return response()->json($page->through(
            fn ($row) => $this->presentGroupe($alertes->get((int) $row->contribuable_id, collect()), $noms, $request->user())
        ));
    }

    /** Filtres communs aux vues simple et groupée. @param array<string, mixed> $data */
    private function appliquerFiltres(Builder $query, array $data): void
    {
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
            $term = '%' . mb_strtolower(trim($data['search'])) . '%';
            $query->whereHas('contribuable', function ($q) use ($term) {
                // LOWER + LIKE : insensible à la casse, quel que soit le SGBD (Oracle, PostgreSQL, SQLite)
                $q->where(fn ($x) => $x->whereRaw('LOWER(nom) LIKE ?', [$term])->orWhereRaw('LOWER(nif) LIKE ?', [$term]));
            });
        }
    }

    /** Toutes les alertes visibles d'un contribuable (même périmètre que les listes). */
    private function requeteGroupe(Request $request, Alerte $alerte): Builder
    {
        return $this->scopedQuery($request)->where('alertes.contribuable_id', $alerte->contribuable_id);
    }

    /**
     * Présentation d'un dossier contribuable : ses alertes et l'état agrégé (niveau maximal, statut, verrou).
     *
     * @param \Illuminate\Support\Collection<int, Alerte> $alertes
     * @param array<int, string> $noms
     * @return array<string, mixed>|null
     */
    private function presentGroupe($alertes, array $noms, ?User $me): ?array
    {
        if ($alertes->isEmpty()) {
            return null;
        }

        $presentes = $alertes->map(fn (Alerte $a) => $this->present($a, $noms, $me))->values();
        $rang = ['faible' => 1, 'moyen' => 2, 'eleve' => 3];
        $niveau = $presentes->sortByDesc(fn ($p) => $rang[$p['niveau']] ?? 0)->first()['niveau'];
        $statuts = $presentes->pluck('statut')->unique()->values();

        $statut = $statuts->contains('en_traitement')
            ? 'en_traitement'
            : ($statuts->every(fn ($s) => in_array($s, [Alerte::TRAITEE, Alerte::REGULARISEE], true))
                ? ($statuts->contains(Alerte::TRAITEE) ? Alerte::TRAITEE : Alerte::REGULARISEE)
                : Alerte::A_TRAITER);

        $verrouillees = $presentes->filter(fn ($p) => $p['statut'] === 'en_traitement');
        $pris = $verrouillees->first();
        $traitees = $presentes->filter(fn ($p) => $p['traite_le'] !== null)->sortByDesc('traite_le');

        // Dossier à ouvrir : une anomalie libre de préférence, sinon la plus risquée
        $representant = $presentes->first(fn ($p) => $p['statut'] === Alerte::A_TRAITER && empty($p['pris_par']))
            ?? $presentes->first(fn ($p) => $p['statut'] === Alerte::A_TRAITER)
            ?? $presentes->first();

        return [
            'contribuable' => $presentes->first()['contribuable'],
            'alertes' => $presentes->all(),
            'representant_id' => $representant['id'],
            'niveau' => $niveau,
            'statut' => $statut,
            'pris_par' => $pris['pris_par'] ?? null,
            'pris_le' => $pris['pris_le'] ?? null,
            'pris_par_moi' => $verrouillees->isNotEmpty() && $verrouillees->every(fn ($p) => $p['pris_par_moi']),
            'traite_par' => $traitees->first()['traite_par'] ?? null,
            'traite_le' => $traitees->first()['traite_le'] ?? null,
            'commentaire' => $presentes->first(fn ($p) => ! empty($p['commentaire']))['commentaire'] ?? null,
            'nb_alertes' => $presentes->count(),
            'regles' => $presentes->pluck('regle')->unique()->values()->all(),
            'types_impot' => $presentes->pluck('type_impot')->unique()->values()->all(),
            'annees' => $presentes->pluck('annee_fiscale')->unique()->sort()->values()->all(),
        ];
    }

    /** Le dossier contribuable complet, pour les actions et la page de traitement. @return array<string, mixed> */
    private function presentGroupeComplet(Request $request, Alerte $alerte): array
    {
        $alertes = $this->requeteGroupe($request, $alerte)
            ->with(['contribuable.centre', 'traitePar'])
            ->orderByRaw("CASE alertes.niveau WHEN 'eleve' THEN 3 WHEN 'moyen' THEN 2 ELSE 1 END DESC")
            ->orderByDesc('alertes.annee_fiscale')
            ->orderByDesc('alertes.id')
            ->get();

        return $this->presentGroupe($alertes, $this->nomsPreneurs($alertes), $request->user()) ?? [];
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
        foreach ((clone $pris)->selectRaw('alertes.niveau, count(*) as total')->groupBy('alertes.niveau')->get() as $r) {
            $n = (int) $r->total;
            $summary['a_traiter'] -= $n;
            $summary[$r->niveau] -= $n;
            $summary['en_traitement'] += $n;
        }

        // Une ligne par contribuable dans les listes groupées : compteurs de dossiers pour la page Traitements
        $libres = $this->scopedQuery($request);
        VerrouAlerte::libres($libres);
        $summary['dossiers_a_traiter'] = $libres->distinct()->count('alertes.contribuable_id');
        $summary['dossiers_en_traitement'] = (clone $pris)->distinct()->count('alertes.contribuable_id');
        $summary['dossiers_traites'] = $this->scopedQuery($request)
            ->where('alertes.statut', Alerte::TRAITEE)
            ->distinct()->count('alertes.contribuable_id');

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
            'groupe' => ['sometimes', 'boolean'],
        ]);

        if ($request->boolean('groupe')) {
            return $this->updateGroupe($request, $alerte, $data, $me);
        }

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
     * Mise à jour du dossier contribuable : le statut et le commentaire s'appliquent à toutes ses anomalies
     * non régularisées. Un dossier pris par un autre agent est refusé, sauf pour un admin/central/superadmin.
     *
     * @param array<string, mixed> $data
     */
    private function updateGroupe(Request $request, Alerte $alerte, array $data, User $me): JsonResponse
    {
        $alertes = $this->requeteGroupe($request, $alerte)->get();

        foreach ($alertes as $a) {
            if (VerrouAlerte::verrouille($a) && (int) $a->getAttribute('pris_par') !== $me->id && ! $this->peutReprendre($me)) {
                return response()->json(['message' => 'Ce dossier est en cours de traitement par ' . $this->nomPreneur($a) . '.'], 423);
            }
        }

        $modifiables = $alertes->reject(fn (Alerte $a) => $a->statut === Alerte::REGULARISEE);

        if (array_key_exists('statut', $data) && $modifiables->isEmpty()) {
            return response()->json([
                'message' => "Cette alerte est régularisée (l'anomalie n'est plus constatée) : son statut ne peut pas être modifié.",
            ], 422);
        }

        $changerCommentaire = array_key_exists('commentaire', $data);
        $commentaire = $changerCommentaire && trim((string) $data['commentaire']) !== '' ? trim((string) $data['commentaire']) : null;

        foreach ($modifiables as $a) {
            if ($changerCommentaire) {
                $a->commentaire = $commentaire;
            }

            if (array_key_exists('statut', $data) && $data['statut'] !== $a->statut) {
                $a->statut = $data['statut'];
                $a->traite_par = $data['statut'] === Alerte::TRAITEE ? $me->id : null;
                $a->traite_le = $data['statut'] === Alerte::TRAITEE ? now() : null;
            }

            // Le verrou suit le traitement : prolongé pendant le travail, levé quand le dossier est clos ou rouvert
            if ($a->statut !== Alerte::A_TRAITER || array_key_exists('statut', $data)) {
                $a->forceFill(['pris_par' => null, 'pris_le' => null]);
            } elseif ((int) $a->getAttribute('pris_par') === $me->id) {
                $a->forceFill(['pris_le' => now()]);
            }

            $a->save();
        }

        $alerte = $alerte->fresh(['contribuable.centre', 'traitePar']);

        return response()->json($this->present($alerte, [], $me) + ['groupe' => $this->presentGroupeComplet($request, $alerte)]);
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

        if ($request->boolean('groupe')) {
            return $this->prendreGroupe($request, $alerte, $me, $forcer);
        }

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

    /** Prend toutes les anomalies encore « à traiter » du contribuable : le dossier devient « En traitement ». */
    private function prendreGroupe(Request $request, Alerte $alerte, User $me, bool $forcer): JsonResponse
    {
        $alertes = $this->requeteGroupe($request, $alerte)->get();
        $aTraiter = $alertes->filter(fn (Alerte $a) => $a->statut === Alerte::A_TRAITER);

        if ($aTraiter->isEmpty()) {
            $alerte = $alerte->fresh(['contribuable.centre', 'traitePar']);

            return response()->json([
                'message' => "Ce dossier ne contient plus d'anomalie à traiter.",
                'alerte' => $this->present($alerte, [], $me),
                'groupe' => $this->presentGroupeComplet($request, $alerte),
            ], 422);
        }

        if (! $forcer) {
            $bloquee = $aTraiter->first(
                fn (Alerte $a) => VerrouAlerte::verrouille($a) && (int) $a->getAttribute('pris_par') !== $me->id
            );

            if ($bloquee !== null) {
                $alerte = $alerte->fresh(['contribuable.centre', 'traitePar']);

                return response()->json([
                    'message' => 'Ce dossier est en cours de traitement par ' . $this->nomPreneur($bloquee) . '.',
                    'alerte' => $this->present($alerte, [], $me),
                    'groupe' => $this->presentGroupeComplet($request, $alerte),
                ], 409);
            }
        }

        Alerte::query()->whereKey($aTraiter->pluck('id')->all())->update(['pris_par' => $me->id, 'pris_le' => now()]);

        $alerte = $alerte->fresh(['contribuable.centre', 'traitePar']);

        return response()->json($this->present($alerte, [], $me) + ['groupe' => $this->presentGroupeComplet($request, $alerte)]);
    }

    /** Libère un dossier pris (par son propriétaire, ou un admin, central, superadmin) : il redevient « À traiter » pour tous. */
    public function liberer(Request $request, Alerte $alerte): JsonResponse
    {
        $me = $request->user();
        $this->assertAccess($me, $alerte);

        if ($request->boolean('groupe')) {
            $alertes = $this->requeteGroupe($request, $alerte)->get();

            foreach ($alertes as $a) {
                if (VerrouAlerte::verrouille($a) && (int) $a->getAttribute('pris_par') !== $me->id && ! $this->peutReprendre($me)) {
                    return response()->json(['message' => 'Ce dossier est en cours de traitement par ' . $this->nomPreneur($a) . '.'], 423);
                }
            }

            Alerte::query()->whereKey($alertes->pluck('id')->all())->update(['pris_par' => null, 'pris_le' => null]);
            $alerte = $alerte->fresh(['contribuable.centre', 'traitePar']);

            return response()->json($this->present($alerte, [], $me) + ['groupe' => $this->presentGroupeComplet($request, $alerte)]);
        }

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

        // Dossier contribuable : toutes ses anomalies visibles et la formule de chacune.
        $groupe = $this->presentGroupeComplet($request, $alerte);
        $formules = [];
        foreach ($groupe['regles'] ?? [] as $code) {
            if (in_array($code, ReglesDetection::CODES, true)) {
                $formules[$code] = FormulesRegles::pour($code, ReglesDetection::parametres($code));
            }
        }

        return response()->json([
            'alerte' => $this->present($alerte, [], $me),
            'indicateurs' => $indicateurs,
            'formule' => $formule,
            'formules' => $formules,
            'groupe' => $groupe,
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
