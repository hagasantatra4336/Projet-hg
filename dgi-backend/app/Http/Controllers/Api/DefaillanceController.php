<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Alerte;
use App\Models\BaseRisque;
use App\Models\DossierHistorique;
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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

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
    private const MESSAGE_REOUVERTURE = 'Seul le chef du centre (ou un central) peut rouvrir un dossier traité. '
        .'Demandez-lui de le rouvrir et de le libérer, puis reprenez-le.';

    /** Délai au-delà duquel un dossier encore ouvert est compté en retard (même seuil que le surlignage « Âge »). */
    private const SEUIL_RETARD = 30;

    /** Libellés lisibles dans l'export CSV (le fichier est lu hors de l'application). */
    private const LIBELLES_NIVEAU = ['faible' => 'Faible', 'moyen' => 'Moyen', 'eleve' => 'Élevé'];

    private const LIBELLES_STATUT = [
        'a_traiter' => 'À traiter',
        'en_traitement' => 'En traitement',
        'traitee' => 'Traitée',
        'regularisee' => 'Régularisée',
    ];

    private const LIBELLES_REGLE = [
        'defaillance' => 'R4 Défaut de dépôt',
        'baisse_ca' => "Baisse du chiffre d'affaires",
    ];

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate($this->reglesFiltres() + [
            'sort' => ['nullable', Rule::in(['niveau', 'type_impot', 'annee_fiscale', 'traite_le', 'cree_le'])],
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
            // « cree_le » est le nom d'affichage : la colonne réelle est created_at.
            $query->orderBy('alertes.'.($sort === 'cree_le' ? 'created_at' : $sort), $dir);
        }
        $query->orderBy('alertes.id', 'desc');

        $page = $query->paginate(10);
        $noms = $this->nomsPreneurs($page->getCollection());

        return response()->json($page->through(fn (Alerte $a) => $this->present($a, $noms, $request->user())));
    }

    /** Règles de validation communes à la liste, aux compteurs et à l'export. @return array<string, mixed> */
    private function reglesFiltres(): array
    {
        return [
            'regle' => ['nullable', Rule::in(array_merge(Alerte::REGLES, ReglesDetection::codesPersonnalises()))],
            'niveau' => ['nullable', Rule::in(Alerte::NIVEAUX)],
            'type_impot' => ['nullable', Rule::in(config('anomalies.defaillance.impots'))],
            'statut' => ['nullable', Rule::in([Alerte::A_TRAITER, 'en_traitement', Alerte::TRAITEE, Alerte::REGULARISEE])],
            // Archives : 1 = seulement les dossiers clôturés (toutes anomalies traitées/régularisées)
            'archive' => ['nullable', 'boolean'],
            'annee_fiscale' => ['nullable', 'integer', 'between:1990,2100'],
            'centre_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            // Ancienneté du dossier en jours : « créé depuis moins de N jours »
            'age_min' => ['nullable', 'integer', 'between:1,3650'],
        ];
    }

    /**
     * Vue groupée : un « dossier contribuable » par ligne, contenant toutes ses anomalies visibles.
     * Les filtres portent sur les alertes : un contribuable n'apparaît que s'il a au moins une alerte correspondante,
     * et la ligne ne détaille que ces alertes.
     *
     * @param  array<string, mixed>  $data
     */
    private function indexGroupes(Request $request, array $data): JsonResponse
    {
        $base = $this->scopedQuery($request);
        $this->appliquerFiltres($base, $data);

        $dir = ($data['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $sort = $data['sort'] ?? 'niveau';

        $groupes = (clone $base)
            ->selectRaw('alertes.contribuable_id, '
                ."MAX(CASE alertes.niveau WHEN 'eleve' THEN 3 WHEN 'moyen' THEN 2 ELSE 1 END) as rang, "
                .'MAX(alertes.annee_fiscale) as annee_max, MAX(alertes.traite_le) as traite_max, MAX(alertes.type_impot) as impot_max, '
                .'MIN(alertes.created_at) as cree_min')
            ->groupBy('alertes.contribuable_id');

        match ($sort) {
            'annee_fiscale' => $groupes->orderBy('annee_max', $dir),
            'traite_le' => $groupes->orderBy('traite_max', $dir),
            'type_impot' => $groupes->orderBy('impot_max', $dir),
            'cree_le' => $groupes->orderBy('cree_min', $dir),
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
                $query->where('alertes.'.$champ, $data[$champ]);
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
            $term = '%'.mb_strtolower(trim($data['search'])).'%';
            $query->whereHas('contribuable', function ($q) use ($term) {
                // LOWER + LIKE : insensible à la casse, quel que soit le SGBD (Oracle, PostgreSQL, SQLite)
                $q->where(fn ($x) => $x->whereRaw('LOWER(nom) LIKE ?', [$term])->orWhereRaw('LOWER(nif) LIKE ?', [$term]));
            });
        }

        // Ancienneté : dossiers créés depuis moins de N jours, c.-à-d. dont la plus ancienne anomalie
        // (parmi celles affichées, les mêmes que celles comptées dans la colonne Âge) est postérieure à la limite.
        if (! empty($data['age_min'])) {
            $recentes = (clone $query)
                ->reorder()
                ->select('alertes.contribuable_id')
                ->groupBy('alertes.contribuable_id')
                ->havingRaw('MIN(alertes.created_at) >= ?', [now()->subDays((int) $data['age_min'])]);

            $query->whereIn('alertes.contribuable_id', $recentes);
        }

        // Archives : un dossier est clôturé quand TOUTES ses anomalies sont traitées ou régularisées
        // (agrégation MIN + CASE, portable SQLite/PostgreSQL/Oracle).
        $clotures = (clone $query)
            ->reorder()
            ->select('alertes.contribuable_id')
            ->groupBy('alertes.contribuable_id')
            ->havingRaw("MIN(CASE WHEN alertes.statut IN ('".Alerte::TRAITEE."', '".Alerte::REGULARISEE."') THEN 1 ELSE 0 END) = 1");

        if (! empty($data['archive'])) {
            $query->whereIn('alertes.contribuable_id', $clotures);
        } elseif (empty($data['statut'])) {
            // Liste de travail : « Tous les statuts » garde les dossiers ouverts, jamais les clôturés.
            $query->whereNotIn('alertes.contribuable_id', $clotures);
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
     * @param  Collection<int, Alerte>  $alertes
     * @param  array<int, string>  $noms
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
            'annees' => $presentes->pluck('annee_fiscale')->unique()->sortDesc()->values()->all(),
            // Age du dossier : date de la plus ancienne anomalie qu'il contient
            'cree_le' => $presentes->pluck('cree_le')->filter()->sort()->first(),
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

        // Pilotage : délais en jours, calculés en PHP après agrégation par dossier
        // (aucune fonction de date propre au SGBD : tests SQLite, développement PostgreSQL, production Oracle).
        $delais = [];
        $attentes = [];
        $enRetard = 0;
        $seuilRetard = now()->subDays(self::SEUIL_RETARD);

        $dossiers = $this->scopedQuery($request)
            ->selectRaw('alertes.contribuable_id, MIN(alertes.created_at) as ouverture, '
                .'MIN(alertes.pris_le) as prise, MAX(alertes.traite_le) as cloture, '
                ."MAX(CASE WHEN alertes.statut = '".Alerte::A_TRAITER."' THEN 1 ELSE 0 END) as reste")
            ->groupBy('alertes.contribuable_id')
            ->get();

        foreach ($dossiers as $d) {
            $ouverture = $d->ouverture ? Carbon::parse($d->ouverture) : null;

            if ($ouverture === null) {
                continue;
            }

            if ($d->prise) {
                $attentes[] = max(0, $ouverture->diffInMinutes(Carbon::parse($d->prise)) / 1440);
            }

            if ((int) $d->reste === 1) {
                // Dossier encore ouvert (au moins une anomalie à traiter) : compte s'il traîne.
                if ($ouverture->lte($seuilRetard)) {
                    $enRetard++;
                }
            } elseif ($d->cloture) {
                $delais[] = max(0, $ouverture->diffInMinutes(Carbon::parse($d->cloture)) / 1440);
            }
        }

        $moyenne = fn (array $valeurs): ?float => $valeurs === [] ? null : round(array_sum($valeurs) / count($valeurs), 1);

        $summary['delai_moyen_jours'] = $moyenne($delais);
        $summary['attente_moyenne_jours'] = $moyenne($attentes);
        $summary['dossiers_en_retard'] = $enRetard;

        // Exercices proposés dans le filtre : seulement ceux qui existent, du plus récent au plus ancien
        $summary['exercices'] = $this->scopedQuery($request)
            ->distinct()
            ->orderByDesc('alertes.annee_fiscale')
            ->pluck('alertes.annee_fiscale')
            ->map(fn ($annee) => (int) $annee)
            ->values()
            ->all();

        return response()->json($summary + [
            'derniere_analyse' => Cache::get(AnomalieAnalyseur::CACHE_DERNIERE_ANALYSE),
        ]);
    }

    /**
     * Export CSV de la liste filtrée : une ligne par dossier contribuable.
     * Mêmes filtres et même périmètre que la liste (agent/admin = leur centre).
     */
    public function export(Request $request): StreamedResponse
    {
        $data = $request->validate($this->reglesFiltres());

        $query = $this->scopedQuery($request)->with(['contribuable.centre', 'traitePar']);
        $this->appliquerFiltres($query, $data);
        $query->orderByRaw("CASE alertes.niveau WHEN 'eleve' THEN 3 WHEN 'moyen' THEN 2 ELSE 1 END DESC")
            ->orderByDesc('alertes.annee_fiscale')
            ->orderBy('alertes.contribuable_id')
            ->orderBy('alertes.id');

        // Plafond : un export reste un fichier de travail, pas une extraction de toute la base.
        $alertes = $query->limit(10000)->get()->groupBy(fn (Alerte $a) => (int) $a->contribuable_id);
        $noms = $this->nomsPreneurs($alertes->flatten());

        $lignes = [];
        foreach ($alertes as $groupe) {
            $g = $this->presentGroupe($groupe, $noms, $request->user());
            if ($g === null) {
                continue;
            }

            $lignes[] = [
                $g['contribuable']['nif'],
                $g['contribuable']['nom'],
                $g['contribuable']['centre'] ?? '',
                self::LIBELLES_NIVEAU[$g['niveau']] ?? $g['niveau'],
                implode(', ', array_map(fn ($r) => self::LIBELLES_REGLE[$r] ?? mb_strtoupper($r), $g['regles'])),
                implode(' / ', $g['types_impot']),
                implode(', ', $g['annees']),
                $g['nb_alertes'],
                self::LIBELLES_STATUT[$g['statut']] ?? $g['statut'],
                $g['pris_par'] ?? '',
                $g['cree_le'] ? Carbon::parse($g['cree_le'])->format('d/m/Y') : '',
                $g['traite_par'] ?? '',
                $g['traite_le'] ? Carbon::parse($g['traite_le'])->format('d/m/Y') : '',
                $g['commentaire'] ?? '',
            ];
        }

        $entetes = [
            'NIF', 'Contribuable', 'Centre', 'Niveau', 'Anomalies', 'Impôts', 'Exercices', 'Nb anomalies',
            'Statut', 'Pris par', 'Créé le', 'Traité par', 'Traité le', 'Commentaire',
        ];

        return response()->streamDownload(function () use ($lignes, $entetes): void {
            $sortie = fopen('php://output', 'w');
            fwrite($sortie, "\xEF\xBB\xBF"); // BOM : accents corrects à l'ouverture dans Excel
            fwrite($sortie, $this->ligneCsv($entetes));
            foreach ($lignes as $ligne) {
                fwrite($sortie, $this->ligneCsv($ligne));
            }
            fclose($sortie);
        }, 'dossiers-defaillances-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /** Une ligne CSV séparée par des points-virgules (Excel français), champs entre guillemets si nécessaire. @param array<int, mixed> $champs */
    private function ligneCsv(array $champs): string
    {
        $cellules = array_map(function ($valeur): string {
            $texte = (string) $valeur;

            return strpbrk($texte, ";\"\r\n") === false ? $texte : '"'.str_replace('"', '""', $texte).'"';
        }, $champs);

        return implode(';', $cellules)."\r\n";
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
            return response()->json(['message' => 'Ce dossier est en cours de traitement par '.$this->nomPreneur($alerte).'.'], 423);
        }

        if (array_key_exists('statut', $data) && $alerte->statut === Alerte::REGULARISEE) {
            return response()->json([
                'message' => "Cette alerte est régularisée (l'anomalie n'est plus constatée) : son statut ne peut pas être modifié.",
            ], 422);
        }

        // Un dossier clôturé ne se rouvre que par un chef de centre, un central ou un superadmin.
        if (array_key_exists('statut', $data) && $data['statut'] === Alerte::A_TRAITER
            && $alerte->statut === Alerte::TRAITEE && ! $this->peutReprendre($me)) {
            return response()->json(['message' => self::MESSAGE_REOUVERTURE], 403);
        }

        $statutAvant = $alerte->statut;
        $etatAvant = $this->statutPresente($alerte);

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

        if (array_key_exists('statut', $data) && $data['statut'] !== $statutAvant) {
            $this->journaliser(
                $alerte,
                $me,
                $data['statut'] === Alerte::TRAITEE ? DossierHistorique::CLOTURE : DossierHistorique::REOUVERTURE,
                $etatAvant,
                $data['statut'],
            );
        }

        return response()->json($this->present($alerte->load(['contribuable.centre', 'traitePar']), [], $me));
    }

    /**
     * Mise à jour du dossier contribuable : le statut et le commentaire s'appliquent à toutes ses anomalies
     * non régularisées. Un dossier pris par un autre agent est refusé, sauf pour un admin/central/superadmin.
     *
     * @param  array<string, mixed>  $data
     */
    private function updateGroupe(Request $request, Alerte $alerte, array $data, User $me): JsonResponse
    {
        $alertes = $this->requeteGroupe($request, $alerte)->get();

        foreach ($alertes as $a) {
            if (VerrouAlerte::verrouille($a) && (int) $a->getAttribute('pris_par') !== $me->id && ! $this->peutReprendre($me)) {
                return response()->json(['message' => 'Ce dossier est en cours de traitement par '.$this->nomPreneur($a).'.'], 423);
            }
        }

        $modifiables = $alertes->reject(fn (Alerte $a) => $a->statut === Alerte::REGULARISEE);

        if (array_key_exists('statut', $data) && $modifiables->isEmpty()) {
            return response()->json([
                'message' => "Cette alerte est régularisée (l'anomalie n'est plus constatée) : son statut ne peut pas être modifié.",
            ], 422);
        }

        // Rouvrir un dossier clôturé est réservé au chef de centre, au central et au superadmin.
        if (array_key_exists('statut', $data) && $data['statut'] === Alerte::A_TRAITER
            && $modifiables->contains(fn (Alerte $a) => $a->statut === Alerte::TRAITEE)
            && ! $this->peutReprendre($me)) {
            return response()->json(['message' => self::MESSAGE_REOUVERTURE], 403);
        }

        $etatAvant = $this->etatGroupe($modifiables);
        $statutChange = array_key_exists('statut', $data)
            && $modifiables->contains(fn (Alerte $a) => $data['statut'] !== $a->statut);

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

        if ($statutChange) {
            $this->journaliser(
                $alerte,
                $me,
                $data['statut'] === Alerte::TRAITEE ? DossierHistorique::CLOTURE : DossierHistorique::REOUVERTURE,
                $etatAvant,
                $data['statut'],
            );
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

        $etatAvant = $this->statutPresente($alerte);
        $dejaPrisParMoi = $this->prisParMoi($alerte, $me);
        $repris = $forcer && VerrouAlerte::verrouille($alerte) && (int) $alerte->getAttribute('pris_par') !== $me->id;

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
                default => 'Ce dossier est en cours de traitement par '.$this->nomPreneur($alerte).'.',
            };

            return response()->json(['message' => $message, 'alerte' => $this->present($alerte, [], $me)], $alerte->statut === Alerte::A_TRAITER ? 409 : 422);
        }

        if (! $dejaPrisParMoi) {
            $this->journaliser($alerte, $me, $repris ? DossierHistorique::REPRISE : DossierHistorique::PRISE, $etatAvant, 'en_traitement');
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
                    'message' => 'Ce dossier est en cours de traitement par '.$this->nomPreneur($bloquee).'.',
                    'alerte' => $this->present($alerte, [], $me),
                    'groupe' => $this->presentGroupeComplet($request, $alerte),
                ], 409);
            }
        }

        $dejaPrisParMoi = $aTraiter->every(fn (Alerte $a) => $this->prisParMoi($a, $me));
        $repris = $forcer && $aTraiter->contains(fn (Alerte $a) => VerrouAlerte::verrouille($a) && (int) $a->getAttribute('pris_par') !== $me->id);

        Alerte::query()->whereKey($aTraiter->pluck('id')->all())->update(['pris_par' => $me->id, 'pris_le' => now()]);

        if (! $dejaPrisParMoi) {
            $this->journaliser($alerte, $me, $repris ? DossierHistorique::REPRISE : DossierHistorique::PRISE, 'a_traiter', 'en_traitement');
        }

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
            $etaitPris = $alertes->contains(fn (Alerte $a) => VerrouAlerte::verrouille($a));

            foreach ($alertes as $a) {
                if (VerrouAlerte::verrouille($a) && (int) $a->getAttribute('pris_par') !== $me->id && ! $this->peutReprendre($me)) {
                    return response()->json(['message' => 'Ce dossier est en cours de traitement par '.$this->nomPreneur($a).'.'], 423);
                }
            }

            Alerte::query()->whereKey($alertes->pluck('id')->all())->update(['pris_par' => null, 'pris_le' => null]);

            if ($etaitPris) {
                $this->journaliser($alerte, $me, DossierHistorique::LIBERATION, 'en_traitement', 'a_traiter');
            }

            $alerte = $alerte->fresh(['contribuable.centre', 'traitePar']);

            return response()->json($this->present($alerte, [], $me) + ['groupe' => $this->presentGroupeComplet($request, $alerte)]);
        }

        if (VerrouAlerte::verrouille($alerte) && (int) $alerte->getAttribute('pris_par') !== $me->id && ! $this->peutReprendre($me)) {
            return response()->json(['message' => 'Ce dossier est en cours de traitement par '.$this->nomPreneur($alerte).'.'], 423);
        }

        $etaitPris = VerrouAlerte::verrouille($alerte);

        $alerte->forceFill(['pris_par' => null, 'pris_le' => null])->save();

        if ($etaitPris) {
            $this->journaliser($alerte, $me, DossierHistorique::LIBERATION, 'en_traitement', 'a_traiter');
        }

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
            'historique' => $this->historique($alerte),
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
            ).($stats['desactivees'] !== [] ? ' Règle(s) désactivée(s) dans les réglages : '.implode(', ', $stats['desactivees']).'.' : ''),
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

    /** Admin, central et superadmin peuvent reprendre ou libérer un dossier pris par un autre, et rouvrir un dossier clos. */
    private function peutReprendre(User $me): bool
    {
        return in_array($me->role, [Role::Superadmin, Role::Central, Role::Admin], true);
    }

    /** Statut affiché : un dossier pris reste « à traiter » en base mais s'affiche « en traitement ». */
    private function statutPresente(Alerte $alerte): string
    {
        return VerrouAlerte::verrouille($alerte) ? 'en_traitement' : $alerte->statut;
    }

    /** État affiché d'un dossier : « en traitement » dès qu'une de ses anomalies est prise. */
    private function etatGroupe($alertes): string
    {
        if ($alertes->contains(fn (Alerte $a) => $this->statutPresente($a) === 'en_traitement')) {
            return 'en_traitement';
        }

        return $alertes->every(fn (Alerte $a) => in_array($a->statut, [Alerte::TRAITEE, Alerte::REGULARISEE], true))
            ? 'traitee'
            : 'a_traiter';
    }

    private function prisParMoi(Alerte $alerte, User $me): bool
    {
        return VerrouAlerte::verrouille($alerte) && (int) $alerte->getAttribute('pris_par') === $me->id;
    }

    /** Une ligne de journal par action de dossier (pas une par anomalie). */
    private function journaliser(Alerte $alerte, User $me, string $action, string $avant, string $apres): void
    {
        DossierHistorique::create([
            'contribuable_id' => $alerte->contribuable_id,
            'alerte_id' => $alerte->id,
            'user_id' => $me->id,
            'auteur' => $me->nom,
            'action' => $action,
            'statut_avant' => $avant,
            'statut_apres' => $apres,
        ]);
    }

    /** Dernières actions du dossier, la plus récente d'abord. */
    private function historique(Alerte $alerte): array
    {
        return DossierHistorique::query()
            ->where('contribuable_id', $alerte->contribuable_id)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (DossierHistorique $h) => [
                'id' => $h->id,
                'action' => $h->action,
                'auteur' => $h->auteur,
                'statut_avant' => $h->statut_avant,
                'statut_apres' => $h->statut_apres,
                'le' => $h->created_at?->toIso8601String(),
            ])
            ->all();
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
     * @param  array<int, string>  $noms  id => nom des preneurs (évite une requête par alerte)
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
            'cree_le' => $a->created_at?->toIso8601String(),
            'pris_par' => $pris ? ($noms[$prisPar] ?? $this->nomPreneur($a)) : null,
            'pris_le' => $pris ? Carbon::parse($a->getAttribute('pris_le'))->toIso8601String() : null,
            'pris_par_moi' => $pris && $me !== null && (int) $prisPar === $me->id,
        ];
    }
}
