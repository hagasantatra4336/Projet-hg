<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Alerte;
use App\Models\Assujettissement;
use App\Models\BaseRisque;
use App\Models\Contribuable;
use App\Models\Declaration;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Construit la fiche d'un contribuable : identité et coordonnées, obligations, alertes, historique des
 * déclarations, synthèse de l'analyse et chiffres clés de la base risque, observations.
 *
 * La même structure sert à l'écran (JSON) et au PDF (vue Blade), pour que les deux restent identiques.
 *   agent / admin        → uniquement les contribuables de LEUR centre ;
 *   central / superadmin → tous.
 */
class FicheContribuable
{
    private const MOIS_AFFICHES = 12;
    private const ANNEES_AFFICHEES = 5;

    public function autoriser(User $me, Contribuable $contribuable): void
    {
        abort_if($me->role === null, 403, "Votre compte n'a pas de fonction attribuée.");

        if (in_array($me->role, [Role::Agent, Role::Admin], true)) {
            abort_if(
                $me->centre_id === null || $contribuable->centre_id !== $me->centre_id,
                403,
                "Ce contribuable n'appartient pas à votre centre."
            );
        }
    }

    /** @return array<string, mixed> */
    public function donnees(Contribuable $contribuable, User $me): array
    {
        $contribuable->load('centre');
        $aujourdhui = AnomalieAnalyseur::aujourdhui();
        $jour = $aujourdhui->format('Y-m-d');

        // Alertes (toutes, de la plus récente à la plus ancienne)
        $requeteAlertes = $contribuable->alertes()->with(['traitePar', 'prisPar']);
        ReglesDetection::limiterAlertes($requeteAlertes); // règles désactivées ou impôts décochés : alertes masquées
        $alertes = $requeteAlertes->orderByDesc('annee_fiscale')->orderByDesc('mois')->orderByDesc('id')->get();

        $alertesPresentees = $alertes->map(fn (Alerte $a) => $this->presentAlerte($a, $me))->values()->all();

        // Chiffres clés
        $agg = $contribuable->declarations()
            ->selectRaw('count(*) as total, min(annee_fiscale) as premiere, max(annee_fiscale) as derniere')
            ->first();
        $premieres = $contribuable->declarations()
            ->selectRaw('type_impot, min(annee_fiscale * 100 + mois) as premiere')
            ->groupBy('type_impot')
            ->pluck('premiere', 'type_impot');

        // Obligations (assujettissements) et fin d'activité
        $assujs = $contribuable->assujettissements()->orderBy('type_impot')->orderByDesc('annee_fiscale')->get();
        $finActivite = collect([$contribuable->date_cessation, $contribuable->date_radiation])->filter()
            ->map(fn ($d) => $d->toDateString())->min();

        // Impôts connus pour ce contribuable, dans l'ordre de la config, séparés mensuels / annuels
        $periodicites = config('anomalies.defaillance.periodicite_defaut');
        $connus = $premieres->keys()->merge($alertes->pluck('type_impot'))->merge($assujs->pluck('type_impot'))->unique();
        $impots = collect(array_merge(array_keys($periodicites), $connus->all()))->unique()->filter(fn ($i) => $connus->contains($i))->values();
        $mensuels = $impots->filter(fn ($i) => ($periodicites[$i] ?? 'mensuelle') === 'mensuelle')->values();
        $annuels = $impots->filter(fn ($i) => ($periodicites[$i] ?? 'mensuelle') === 'annuelle')->values();

        // Fenêtres : 12 derniers mois (jusqu'au mois précédent ou à la dernière déclaration) et 5 derniers exercices
        $dernierMois = max(
            (int) now()->subMonthNoOverflow()->format('Y') * 100 + (int) now()->subMonthNoOverflow()->format('n'),
            (int) ($premieres->isEmpty() ? 0 : $this->derniereMensuelle($contribuable, $mensuels->all()))
        );
        $anneeFinM = intdiv($dernierMois, 100);
        $moisFinM = $dernierMois % 100;
        $periodes = [];
        for ($i = self::MOIS_AFFICHES - 1; $i >= 0; $i--) {
            $d = (new DateTimeImmutable(sprintf('%04d-%02d-01', $anneeFinM, $moisFinM)))->modify("-{$i} months");
            $periodes[] = ['annee' => (int) $d->format('Y'), 'mois' => (int) $d->format('n')];
        }
        $finAnnuelle = max((int) ($agg->derniere ?? 0), (int) $aujourdhui->format('Y') - 1);
        $annees = range($finAnnuelle - self::ANNEES_AFFICHEES + 1, $finAnnuelle);

        $debutM = $periodes[0]['annee'] * 100 + $periodes[0]['mois'];

        $declarations = $contribuable->declarations()
            ->where(fn ($q) => $q
                ->where(fn ($m) => $m->where('mois', '>', 0)->whereRaw('annee_fiscale * 100 + mois >= ?', [$debutM]))
                ->orWhere(fn ($a) => $a->where('mois', 0)->where('annee_fiscale', '>=', $annees[0])))
            ->get();
        $declarationsParCle = [];
        foreach ($declarations as $d) {
            $declarationsParCle[$d->type_impot . '|' . $d->annee_fiscale . '|' . $d->mois] = $d;
        }

        // Dates limites connues (déclarations de tous les contribuables) pour les impôts et périodes affichés
        $echeances = [];
        if ($impots->isNotEmpty()) {
            $rows = Declaration::query()->whereIn('type_impot', $impots->all())->whereNotNull('date_limite')
                ->where('annee_fiscale', '>=', min($annees[0], $periodes[0]['annee']))
                ->selectRaw('type_impot, annee_fiscale, mois, max(date_limite) as echeance')
                ->groupBy('type_impot', 'annee_fiscale', 'mois')->get();

            foreach ($rows as $r) {
                $echeances[$r->type_impot . '|' . $r->annee_fiscale . '|' . $r->mois] = substr((string) $r->echeance, 0, 10);
            }
        }

        // Obligation d'une période : d'après l'assujettissement, à défaut d'après la première déclaration
        $assujParImpotAnnee = [];
        foreach ($assujs as $a) {
            $assujParImpotAnnee[$a->type_impot][$a->annee_fiscale] = $a;
        }
        $obligee = function (string $impot, int $annee, int $mois) use ($assujParImpotAnnee, $premieres, $finActivite, $periodicites): bool {
            if (isset($assujParImpotAnnee[$impot])) {
                $a = $assujParImpotAnnee[$impot][$annee] ?? null;
                if ($a === null || ! $a->actif || $a->exonere) {
                    return false;
                }
                $periodicite = [1 => 'mensuelle', 5 => 'annuelle'][$a->periodicite] ?? null;

                return $periodicite !== null && in_array(
                    $mois,
                    DefaillanceDetector::periodesObligees($annee, $periodicite, $a->date_debut?->toDateString(), $a->date_fin?->toDateString(), $finActivite, null),
                    true
                );
            }

            $periodicite = $periodicites[$impot] ?? 'mensuelle';
            $premiere = $premieres->get($impot);

            return $premiere !== null && in_array(
                $mois,
                DefaillanceDetector::periodesObligees($annee, $periodicite, null, null, $finActivite, (int) $premiere),
                true
            );
        };

        $cellule = function (string $impot, int $annee, int $mois) use ($declarationsParCle, $echeances, $jour, $obligee): array {
            $d = $declarationsParCle[$impot . '|' . $annee . '|' . $mois] ?? null;
            $limite = $d?->date_limite?->toDateString() ?? ($echeances[$impot . '|' . $annee . '|' . $mois] ?? null);

            return [
                'annee' => $annee,
                'mois' => $mois,
                'declare' => $d !== null,
                'chiffre_affaires' => $d && $d->chiffre_affaires !== null ? (float) $d->chiffre_affaires : null,
                'date_limite' => $limite,
                'date_depot' => $d?->date_depot?->toDateString(),
                'echue' => $limite !== null && $limite < $jour,
                'obligee' => $obligee($impot, $annee, $mois),
            ];
        };

        $lignesMensuelles = $mensuels->map(fn (string $impot) => [
            'type_impot' => $impot,
            'cellules' => array_map(fn (array $p) => $cellule($impot, $p['annee'], $p['mois']), $periodes),
        ])->all();
        $lignesAnnuelles = $annuels->map(fn (string $impot) => [
            'type_impot' => $impot,
            'cellules' => array_map(fn (int $a) => $cellule($impot, $a, 0), $annees),
        ])->all();

        $stats = [
            'nb_declarations' => (int) ($agg->total ?? 0),
            'premiere_annee' => $agg->premiere !== null ? (int) $agg->premiere : null,
            'derniere_annee' => $agg->derniere !== null ? (int) $agg->derniere : null,
        ];

        return [
            'contribuable' => [
                'id' => $contribuable->id,
                'nif' => $contribuable->nif,
                'nom' => $contribuable->nom,
                'centre' => $contribuable->centre?->nom,
                'etat' => $contribuable->etat,
                'etat_libelle' => $contribuable->etat !== null ? (Contribuable::ETATS[$contribuable->etat] ?? null) : null,
                'date_cessation' => $contribuable->date_cessation?->toDateString(),
                'date_radiation' => $contribuable->date_radiation?->toDateString(),
                'date_blocage' => $contribuable->date_blocage?->toDateString(),
                'telephone' => $contribuable->telephone,
                'email' => $contribuable->email,
                'adresse' => $contribuable->adresse,
                'activite' => $contribuable->activite,
            ],
            'stats' => $stats,
            'resultat' => $this->resultat($alertesPresentees, $stats),
            'chiffres' => $this->chiffres($contribuable),
            'observations' => $this->observations($contribuable),
            'obligations' => $assujs->filter(fn (Assujettissement $a) => $a->annee_fiscale >= (int) $aujourdhui->format('Y') - 1)->map(fn (Assujettissement $a) => [
                'type_impot' => $a->type_impot,
                'annee_fiscale' => $a->annee_fiscale,
                'periodicite' => Assujettissement::PERIODICITES[$a->periodicite] ?? (string) $a->periodicite,
                'actif' => $a->actif,
                'exonere' => $a->exonere,
            ])->values(),
            'alertes' => $alertesPresentees,
            'historique' => [
                'mensuel' => ['periodes' => $periodes, 'impots' => $lignesMensuelles],
                'annuel' => ['annees' => $annees, 'impots' => $lignesAnnuelles],
            ],
        ];
    }

    /**
     * Synthèse de l'analyse : répartition des anomalies, niveau global, date de la dernière analyse.
     *
     * @param array<int, array<string, mixed>> $alertes
     * @param array<string, mixed> $stats
     * @return array<string, mixed>
     */
    private function resultat(array $alertes, array $stats): array
    {
        $parStatut = array_fill_keys([Alerte::A_TRAITER, 'en_traitement', Alerte::TRAITEE, Alerte::REGULARISEE], 0);
        $parRegle = array_fill_keys(Alerte::REGLES, 0);
        $parNiveau = array_fill_keys(Alerte::NIVEAUX, 0);

        $rangs = ['faible' => 1, 'moyen' => 2, 'eleve' => 3];
        $rangGlobal = 0;

        foreach ($alertes as $a) {
            $parStatut[$a['statut']] = ($parStatut[$a['statut']] ?? 0) + 1;
            $parRegle[$a['regle']] = ($parRegle[$a['regle']] ?? 0) + 1;
            $parNiveau[$a['niveau']] = ($parNiveau[$a['niveau']] ?? 0) + 1;

            if ($a['statut'] !== Alerte::REGULARISEE) {
                $rangGlobal = max($rangGlobal, $rangs[$a['niveau']] ?? 0);
            }
        }

        return [
            'alertes_total' => count($alertes),
            'par_statut' => $parStatut,
            'par_regle' => $parRegle,
            'par_niveau' => $parNiveau,
            'niveau_global' => $rangGlobal > 0 ? array_search($rangGlobal, $rangs, true) : null,
            'derniere_analyse' => Cache::get(AnomalieAnalyseur::CACHE_DERNIERE_ANALYSE),
            'annees' => [
                'premiere' => $stats['premiere_annee'],
                'derniere' => $stats['derniere_annee'],
            ],
        ];
    }

    /**
     * Chiffres clés repris de la base d'analyse risque (dernier exercice disponible par source).
     *
     * @return array<string, mixed>
     */
    private function chiffres(Contribuable $contribuable): array
    {
        $lignes = BaseRisque::query()
            ->where('contribuable_id', $contribuable->id)
            ->orderByDesc('annee_fiscale')
            ->orderByDesc('mois')
            ->get()
            ->groupBy('source');

        $irLigne = $lignes->get('IR')?->first();
        $ir = $irLigne === null ? null : [
            'annee' => (int) $irLigne->annee_fiscale,
            'ca' => (float) ($irLigne->ca ?? 0),
            'resultat' => (float) ($irLigne->resultat ?? 0),
            'ir_a_payer' => (float) ($irLigne->ir_a_payer ?? 0),
        ];

        $tvaLignes = $lignes->get('TVA', collect());
        $anneeTva = $tvaLignes->max('annee_fiscale');
        $tva = null;
        if ($anneeTva !== null) {
            $annee = $tvaLignes->where('annee_fiscale', (int) $anneeTva)->sortBy('mois')->values();
            $dernierMois = $annee->last();
            $tva = [
                'annee' => (int) $anneeTva,
                'nb_mois' => $annee->count(),
                'dernier_mois' => (int) ($dernierMois->mois ?? 0),
                'ca' => (float) $annee->sum('ca'),
                'export' => (float) $annee->sum('ca_taxable_export'),
                'credit_tva' => (float) ($dernierMois->credit_tva ?? 0),
            ];
        }

        $recoupementLigne = $lignes->get('RECOUPEMENT')?->first();
        $recoupement = $recoupementLigne === null ? null : [
            'annee' => (int) $recoupementLigne->annee_fiscale,
            'montant_total' => (float) ($recoupementLigne->montant_total ?? 0),
            'prestations' => (float) ($recoupementLigne->prestations_de_service ?? 0),
        ];

        $dauLigne = $lignes->get('DAU')?->first();
        $dau = $dauLigne === null ? null : [
            'annee' => (int) $dauLigne->annee_fiscale,
            'tva_im4' => (float) ($dauLigne->dau_tva_im4 ?? 0),
            'caf_ex1' => (float) ($dauLigne->dau_caf_ex1 ?? 0),
        ];

        $ecartCa = null;
        if ($ir !== null && $tva !== null && $ir['annee'] === $tva['annee'] && max($ir['ca'], $tva['ca']) > 0) {
            $ecartCa = round(abs($ir['ca'] - $tva['ca']) / max($ir['ca'], $tva['ca']) * 100, 1);
        }

        return [
            'ir' => $ir,
            'tva' => $tva,
            'recoupement' => $recoupement,
            'dau' => $dau,
            'ecart_ca_pct' => $ecartCa,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function observations(Contribuable $contribuable): array
    {
        return $contribuable->observations()
            ->with('user:id,nom')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($o) => [
                'id' => $o->id,
                'texte' => $o->texte,
                'auteur' => $o->user?->nom,
                'le' => $o->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /** Dernier mois déclaré (année × 100 + mois) parmi les impôts mensuels du contribuable. */
    private function derniereMensuelle(Contribuable $contribuable, array $impotsMensuels): int
    {
        if ($impotsMensuels === []) {
            return 0;
        }

        return (int) $contribuable->declarations()->whereIn('type_impot', $impotsMensuels)->where('mois', '>', 0)
            ->selectRaw('max(annee_fiscale * 100 + mois) as dernier')->value('dernier');
    }

    /** Une alerte de la fiche, avec l'état du verrou de traitement (pris_par/pris_le → « en_traitement »). */
    private function presentAlerte(Alerte $a, User $me): array
    {
        $pris = VerrouAlerte::verrouille($a);

        return [
            'id' => $a->id,
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
            'pris_par' => $pris ? $a->prisPar?->nom : null,
            'pris_le' => $pris ? $a->pris_le?->toIso8601String() : null,
            'pris_par_moi' => $pris && (int) $a->getAttribute('pris_par') === $me->id,
        ];
    }
}
