@php
    $c = $fiche['contribuable'];
    $r = $fiche['resultat'];
    $ch = $fiche['chiffres'];
    $nombre = fn ($v) => $v === null ? '—' : number_format((float) $v, 2, ',', ' ');
    $decimal = fn ($v, $d = 1) => number_format((float) $v, $d, ',', ' ');
    $pourcent = fn ($v) => $v === null ? '—' : number_format((float) $v, 1, ',', ' ') . ' %';
    $moisNoms = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $libellePeriode = fn (int $annee, int $mois) => $mois > 0 ? ($moisNoms[$mois - 1] ?? $mois) . ' ' . $annee : 'Exercice ' . $annee;
    $statuts = ['a_traiter' => 'À traiter', 'en_traitement' => 'En traitement', 'traitee' => 'Traitée', 'regularisee' => 'Régularisée'];
    $niveaux = ['faible' => 'Faible', 'moyen' => 'Moyen', 'eleve' => 'Élevé'];
    $rangs = ['faible' => 1, 'moyen' => 2, 'eleve' => 3];
    $derniere = $r['derniere_analyse'];
    $derniereTexte = is_array($derniere) && ! empty($derniere['at'])
        ? \Illuminate\Support\Carbon::parse($derniere['at'])->format('d/m/Y à H:i')
        : null;
    $baisses = collect($fiche['alertes'])->where('regle', 'baisse_ca')
        ->mapWithKeys(fn ($a) => [$a['type_impot'] . '|' . $a['annee_fiscale'] . '|' . $a['mois'] => true])->all();

    // Exercices couverts par les déclarations enregistrées
    $exercices = array_filter([$fiche['stats']['premiere_annee'], $fiche['stats']['derniere_annee']], fn ($a) => $a !== null);
    $exercicesTexte = count($exercices) === 0
        ? '—'
        : (count($exercices) === 2 && $exercices[0] !== $exercices[1]
            ? $exercices[0] . ' – ' . $exercices[1]
            : (string) reset($exercices));

    // Synthèse par critère : nombre d'anomalies, meilleur score, niveau le plus élevé
    $criteres = [];
    foreach ($fiche['alertes'] as $a) {
        $code = $a['regle'];
        $d = is_array($a['details'] ?? null) ? $a['details'] : [];
        $score = null;
        $max = null;
        if (isset($d['score_risque'])) {
            $score = (float) $d['score_risque'];
            $max = (float) ($d['score_max'] ?? 10);
        } elseif (isset($d['score_r4'])) {
            $score = (float) $d['score_r4'];
            $max = 5.0;
        }

        $criteres[$code] ??= ['n' => 0, 'score' => null, 'max' => null, 'niveau' => null];
        $criteres[$code]['n']++;
        if ($score !== null && ($criteres[$code]['score'] === null || $score > $criteres[$code]['score'])) {
            $criteres[$code]['score'] = $score;
            $criteres[$code]['max'] = $max;
        }
        if ($criteres[$code]['niveau'] === null || ($rangs[$a['niveau']] ?? 0) > ($rangs[$criteres[$code]['niveau']] ?? 0)) {
            $criteres[$code]['niveau'] = $a['niveau'];
        }
    }
    $ordonne = [];
    foreach (\App\Services\ReglesDetection::CODES as $code) {
        if (isset($criteres[$code])) {
            $ordonne[$code] = $criteres[$code];
        }
    }
    $criteres = $ordonne + $criteres;
    $codeAffiche = fn (string $code) => match ($code) {
        'defaillance' => 'R4',
        'baisse_ca' => 'BC',
        default => strtoupper($code),
    };
    // Le code est déjà dans sa colonne : on retire le rappel « (critère R4) » du libellé
    $libelleRegle = fn (string $code) => trim(preg_replace('/\s*\(critère\s+R\d+\)\s*$/u', '', \App\Services\ReglesDetection::meta($code)['nom']));

    // Anomalies par impôt (une alerte IR/TVA compte pour les deux)
    $anomaliesPour = fn (array $impots) => collect($fiche['alertes'])->whereIn('type_impot', $impots)->count();

    $recap = [];
    if ($ch['ir'] !== null) {
        $i = $ch['ir'];
        $recap[] = [
            'impot' => 'IR',
            'exercice' => (string) $i['annee'],
            'ca' => $i['ca'],
            'complement' => 'Résultat fiscal : ' . $nombre($i['resultat']) . ' Ar',
            'anomalies' => $anomaliesPour(['IR', 'IR/TVA']),
        ];
    }
    if ($ch['tva'] !== null) {
        $t = $ch['tva'];
        $recap[] = [
            'impot' => 'TVA',
            'exercice' => $t['annee'] . ' (' . $t['nb_mois'] . ' mois)',
            'ca' => $t['ca'],
            'complement' => 'Crédit TVA au ' . $libellePeriode($t['annee'], $t['dernier_mois']) . ' : ' . $nombre($t['credit_tva']) . ' Ar',
            'anomalies' => $anomaliesPour(['TVA', 'IR/TVA']),
        ];
    }
    $totalCa = array_sum(array_column($recap, 'ca'));
    $totalAnomalies = $r['alertes_total']; // une alerte IR/TVA ne doit pas être comptée deux fois

    // Pistes de recherche : uniquement les montants réellement présents dans la base risque
    $pistes = [];
    if ($ch['ir'] !== null || $ch['tva'] !== null) {
        $lignes = [];
        if ($ch['ir'] !== null) {
            $lignes[] = ['cle' => 'CA sur déclaration IR (exercice ' . $ch['ir']['annee'] . ')', 'valeur' => $nombre($ch['ir']['ca']) . ' Ar', 'ecart' => false];
        }
        if ($ch['tva'] !== null) {
            $lignes[] = ['cle' => 'CA sur déclaration TVA (' . $ch['tva']['nb_mois'] . ' mois, ' . $ch['tva']['annee'] . ')', 'valeur' => $nombre($ch['tva']['ca']) . ' Ar', 'ecart' => false];
        }
        if ($ch['ecart_ca_pct'] !== null) {
            $lignes[] = ['cle' => 'Écart CA (IR) / CA (TVA)', 'valeur' => $pourcent($ch['ecart_ca_pct']), 'ecart' => true];
        }
        $pistes[] = ['titre' => 'CA (IR) / CA (TVA)', 'lignes' => $lignes];
    }
    if ($ch['recoupement'] !== null) {
        $rc = $ch['recoupement'];
        $pistes[] = ['titre' => 'RECOUPEMENT / DÉCLARATIONS', 'lignes' => [
            ['cle' => 'Montant total recoupé (' . $rc['annee'] . ')', 'valeur' => $nombre($rc['montant_total']) . ' Ar', 'ecart' => false],
            ['cle' => 'Dont prestations de service (' . $rc['annee'] . ')', 'valeur' => $nombre($rc['prestations']) . ' Ar', 'ecart' => false],
        ]];
    }
    if ($ch['tva'] !== null) {
        $t = $ch['tva'];
        $pistes[] = ['titre' => 'TVA — EXPORTATION ET CRÉDIT', 'lignes' => [
            ['cle' => 'CA taxable à l\'export (' . $t['annee'] . ')', 'valeur' => $nombre($t['export']) . ' Ar', 'ecart' => false],
            ['cle' => 'Crédit de TVA au ' . $libellePeriode($t['annee'], $t['dernier_mois']), 'valeur' => $nombre($t['credit_tva']) . ' Ar', 'ecart' => false],
        ]];
    }
    if ($ch['dau'] !== null) {
        $d = $ch['dau'];
        $pistes[] = ['titre' => 'OPÉRATIONS DOUANIÈRES (DAU)', 'lignes' => [
            ['cle' => 'TVA sur importation — modèle IM4 (' . $d['annee'] . ')', 'valeur' => $nombre($d['tva_im4']) . ' Ar', 'ecart' => false],
            ['cle' => 'Valeur CAF déclarée — modèle EX1 (' . $d['annee'] . ')', 'valeur' => $nombre($d['caf_ex1']) . ' Ar', 'ecart' => false],
        ]];
    }

    $etatTexte = $c['etat_libelle'] ?? 'Non renseigné';
    if ($c['etat'] === 3 && $c['date_cessation']) {
        $etatTexte .= ' — le ' . \Illuminate\Support\Carbon::parse($c['date_cessation'])->format('d/m/Y');
    } elseif ($c['etat'] === 4 && $c['date_radiation']) {
        $etatTexte .= ' — le ' . \Illuminate\Support\Carbon::parse($c['date_radiation'])->format('d/m/Y');
    } elseif ($c['etat'] === 2 && $c['date_blocage']) {
        $etatTexte .= ' — le ' . \Illuminate\Support\Carbon::parse($c['date_blocage'])->format('d/m/Y');
    }

    // Saut de page seulement si la fiche contient de quoi remplir une deuxième page
    $sautPage = count($fiche['alertes']) > 0
        || count($fiche['historique']['mensuel']['impots']) > 0
        || count($fiche['historique']['annuel']['impots']) > 0;
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Fiche {{ $c['nif'] }}</title>
    <style>
        @page { margin: 14mm 14mm 16mm 14mm; }
        * { box-sizing: border-box; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 8.5px; color: #111111; line-height: 1.4; margin: 0; }
        h1, h2, p { margin: 0; }
        table { border-collapse: collapse; width: 100%; }

        .entete td { vertical-align: top; }
        .org { width: 60%; font-size: 7.8px; line-height: 1.55; color: #111111; }
        .org .etat { font-weight: bold; }
        .cachet { width: 40%; }
        .cachet table { border: 1px solid #666666; }
        .cachet td { padding: 4px 7px; font-size: 7.6px; color: #111111; }
        .cachet .numero { font-weight: bold; }
        .filet { border-top: 1px solid #111111; margin: 8px 0 10px; }

        .titre-doc { text-align: center; }
        .titre-doc h1 { font-size: 12.6px; font-weight: bold; text-transform: uppercase; }
        .titre-doc .sous { font-size: 7.8px; color: #444444; margin-top: 2px; font-style: italic; }

        .section { margin-top: 11px; page-break-inside: avoid; }
        .section-libre { margin-top: 11px; }
        .titre-section { font-size: 9.2px; font-weight: bold; text-transform: uppercase; border-bottom: 1px solid #666666; padding-bottom: 1.5px; }

        .grille { margin-top: 4px; }
        .grille td { border: 1px solid #999999; padding: 3px 5px; vertical-align: top; }
        .grille .cle { font-weight: bold; width: 17%; }
        .grille .val { width: 33%; }

        .recap { margin-top: 4px; }
        .recap th { background: #ededed; border: 1px solid #999999; font-size: 7.4px; text-transform: uppercase; text-align: left; padding: 3px 5px; font-weight: bold; }
        .recap td { border: 1px solid #999999; padding: 3px 5px; font-size: 8.2px; vertical-align: top; }
        .recap .num { text-align: right; white-space: nowrap; }
        .recap .centre { text-align: center; }
        .recap tr.total td { font-weight: bold; border-top: 1.5px solid #111111; }
        .note { font-size: 7.6px; color: #333333; margin-top: 4px; }

        .criteres { margin-top: 4px; }
        .criteres th { background: #ededed; border: 1px solid #999999; font-size: 7.4px; text-transform: uppercase; text-align: left; padding: 3px 5px; font-weight: bold; }
        .criteres td { border: 1px solid #999999; padding: 3px 5px; font-size: 8.2px; vertical-align: top; }
        .criteres .code { text-align: center; width: 8%; }
        .criteres .num { text-align: center; white-space: nowrap; }

        .piste { margin-top: 4px; }
        .piste td { border: 1px solid #999999; padding: 3px 5px; font-size: 8.2px; }
        .piste .piste-titre td { background: #ededed; font-weight: bold; font-size: 7.6px; text-transform: uppercase; }
        .piste .cle { width: 68%; }
        .piste .val { width: 32%; text-align: right; font-weight: bold; white-space: nowrap; }

        .liste th { background: #ededed; border: 1px solid #999999; font-size: 7.3px; text-transform: uppercase; text-align: left; padding: 3px 4px; font-weight: bold; }
        .liste td { border: 1px solid #bbbbbb; padding: 3px 4px; vertical-align: top; font-size: 8px; }
        .liste .centre { text-align: center; }

        .observation { border: 1px solid #bbbbbb; padding: 4px 7px; margin-top: 4px; page-break-inside: avoid; }
        .observation .meta { font-size: 7.2px; color: #444444; margin-bottom: 2px; }
        .vide { color: #666666; font-style: italic; font-size: 8px; margin-top: 3px; }

        .matrice { table-layout: fixed; margin-top: 4px; }
        .matrice th, .matrice td { border: 1px solid #bbbbbb; text-align: center; padding: 2px 1px; font-size: 6.8px; }
        .matrice th { background: #ededed; }
        .matrice .impot { text-align: left; font-weight: bold; width: 32px; padding-left: 3px; }
        .legende { font-size: 6.8px; color: #444444; margin-top: 3px; }

        .signature { margin-top: 16px; page-break-inside: avoid; }
        .signature td { vertical-align: top; font-size: 8px; }
        .signature .cadre { border: 1px solid #999999; padding: 5px 7px; }
        .signature .fonction { font-weight: bold; margin-top: 2px; }
        .signature .nom { margin-top: 12px; }
        .signature .mention { color: #444444; font-size: 7px; }

        .pied { margin-top: 10px; border-top: 1px solid #999999; padding-top: 4px; font-size: 7px; color: #444444; }
        .pied strong { color: #111111; }
    </style>
</head>
<body>
    <table class="entete">
        <tr>
            <td class="org">
                <span class="etat">RÉPUBLIQUE DE MADAGASCAR</span><br>
                Ministère de l'Économie et des Finances<br>
                <strong>Direction Générale des Impôts</strong><br>
                {{ $c['centre'] ?? 'Centre non rattaché' }}
            </td>
            <td class="cachet">
                <table>
                    <tr>
                        <td>
                            <span class="numero">Fiche n° {{ str_pad((string) $c['id'], 4, '0', STR_PAD_LEFT) }}</span><br>
                            Date d'édition : {{ $genereLe->format('d/m/Y') }}
                            @if ($derniereTexte)
                                <br>Dernière analyse : {{ $derniereTexte }}
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
    <div class="filet"></div>

    <div class="titre-doc">
        <h1>Fiche de résultat de l'analyse des risques fiscaux</h1>
        <div class="sous">
            Établie à partir de la base d'analyse risque — montants exprimés en ariary (Ar)
            @if ($exercicesTexte !== '—') · exercices {{ $exercicesTexte }} @endif
        </div>
    </div>

    {{-- Identification --}}
    <div class="section">
        <div class="titre-section">Identification du contribuable</div>
        <table class="grille">
            <tr>
                <td class="cle">NIF</td>
                <td class="val">{{ $c['nif'] }}</td>
                <td class="cle">Raison sociale</td>
                <td class="val"><strong>{{ $c['nom'] }}</strong></td>
            </tr>
            <tr>
                <td class="cle">Activité</td>
                <td class="val">{{ $c['activite'] ?: 'Non renseignée' }}</td>
                <td class="cle">État</td>
                <td class="val">{{ $etatTexte }}</td>
            </tr>
            <tr>
                <td class="cle">Téléphone</td>
                <td class="val">{{ $c['telephone'] ?: 'Non renseigné' }}</td>
                <td class="cle">E-mail</td>
                <td class="val">{{ $c['email'] ?: 'Non renseigné' }}</td>
            </tr>
            <tr>
                <td class="cle">Adresse</td>
                <td class="val" colspan="3">{{ $c['adresse'] ?: 'Non renseignée' }}</td>
            </tr>
        </table>
    </div>

    {{-- Récapitulation --}}
    <div class="section">
        <div class="titre-section">Récapitulation des déclarations</div>
        @if (count($recap) === 0)
            <p class="vide">Aucune donnée déclarative dans la base d'analyse risque.</p>
        @else
            <table class="recap">
                <thead>
                    <tr>
                        <th style="width: 9%">Impôt</th>
                        <th style="width: 16%">Exercice</th>
                        <th style="width: 22%; text-align: right">CA déclaré</th>
                        <th style="width: 39%">Indicateur déclaré</th>
                        <th style="width: 14%; text-align: center">Anomalies</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($recap as $ligne)
                        <tr>
                            <td><strong>{{ $ligne['impot'] }}</strong></td>
                            <td>{{ $ligne['exercice'] }}</td>
                            <td class="num">{{ $nombre($ligne['ca']) }} Ar</td>
                            <td>{{ $ligne['complement'] }}</td>
                            <td class="centre">{{ $ligne['anomalies'] }}</td>
                        </tr>
                    @endforeach
                    <tr class="total">
                        <td colspan="2">Total {{ count($recap) > 1 ? '(IR + TVA)' : '' }}</td>
                        <td class="num">{{ $nombre($totalCa) }} Ar</td>
                        <td>—</td>
                        <td class="centre">{{ $totalAnomalies }}</td>
                    </tr>
                </tbody>
            </table>
        @endif
        <p class="note">
            @if ($r['alertes_total'] === 0)
                Aucune anomalie en cours.
            @else
                {{ $r['alertes_total'] }} anomalie(s) : {{ $r['par_statut']['a_traiter'] }} à traiter,
                {{ $r['par_statut']['en_traitement'] }} en traitement, {{ $r['par_statut']['traitee'] }} traitée(s),
                {{ $r['par_statut']['regularisee'] }} régularisée(s).
                @if ($r['niveau_global'])
                    Niveau de risque global : {{ lcfirst($niveaux[$r['niveau_global']] ?? $r['niveau_global']) }}.
                @endif
            @endif
            Dernière analyse : {{ $derniereTexte ?? 'non encore exécutée' }}.
        </p>
    </div>

    {{-- Analyse par critère --}}
    <div class="section">
        <div class="titre-section">Analyse par critère</div>
        @if (count($criteres) === 0)
            <p class="vide">Aucun critère de risque n'a été déclenché pour ce contribuable.</p>
        @else
            <table class="criteres">
                <thead>
                    <tr>
                        <th style="width: 8%; text-align: center">Critère</th>
                        <th style="width: 47%">Intitulé</th>
                        <th style="width: 15%; text-align: center">Score</th>
                        <th style="width: 15%; text-align: center">Risque</th>
                        <th style="width: 15%; text-align: center">Anomalies</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($criteres as $code => $cr)
                        <tr>
                            <td class="code">{{ $codeAffiche($code) }}</td>
                            <td>{{ $libelleRegle($code) }}</td>
                            <td class="num">{{ $cr['score'] === null ? '—' : $decimal($cr['score']) . ' / ' . $decimal($cr['max'], 0) }}</td>
                            <td class="num"><strong>{{ $niveaux[$cr['niveau']] ?? $cr['niveau'] }}</strong></td>
                            <td class="num">{{ $cr['n'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- Pistes de recherche --}}
    <div class="section">
        <div class="titre-section">Pistes de recherche</div>
        @if (count($pistes) === 0)
            <p class="vide">Aucune donnée de recoupement ou de douane disponible pour ce contribuable.</p>
        @else
            @foreach ($pistes as $piste)
                <table class="piste">
                    <tr class="piste-titre"><td colspan="2">{{ $piste['titre'] }}</td></tr>
                    @foreach ($piste['lignes'] as $ligne)
                        <tr>
                            <td class="cle">{{ $ligne['cle'] }}</td>
                            <td class="val">{{ $ligne['valeur'] }}</td>
                        </tr>
                    @endforeach
                </table>
            @endforeach
        @endif
    </div>

    {{-- Anomalies constatées --}}
    <div class="section-libre">
        <div class="titre-section">Anomalies constatées ({{ count($fiche['alertes']) }})</div>
        @if (count($fiche['alertes']) === 0)
            <p class="vide">Aucune anomalie constatée.</p>
        @else
            <table class="liste">
                <thead>
                    <tr>
                        <th style="width: 17%">Règle</th>
                        <th style="width: 8%">Impôt</th>
                        <th style="width: 13%">Période</th>
                        <th style="width: 10%">Niveau</th>
                        <th style="width: 12%">Statut</th>
                        <th style="width: 40%">Motif</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($fiche['alertes'] as $a)
                        <tr>
                            <td>{{ $libelleRegle($a['regle']) }}</td>
                            <td class="centre">{{ $a['type_impot'] }}</td>
                            <td>{{ $libellePeriode((int) $a['annee_fiscale'], (int) $a['mois']) }}</td>
                            <td><strong>{{ $niveaux[$a['niveau']] ?? $a['niveau'] }}</strong></td>
                            <td>{{ $statuts[$a['statut']] ?? $a['statut'] }}</td>
                            <td>{{ $a['motif'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- Observations (deuxième page : suites données) --}}
    <div class="section" @if ($sautPage) style="page-break-before: always; margin-top: 0" @endif>
        <div class="titre-section">Observations et suites données</div>
        @if (count($fiche['observations']) === 0)
            <p class="vide">Aucune observation enregistrée.</p>
        @else
            @foreach ($fiche['observations'] as $o)
                <div class="observation">
                    <div class="meta">
                        {{ $o['le'] ? \Illuminate\Support\Carbon::parse($o['le'])->format('d/m/Y à H:i') : '' }}
                        · {{ $o['auteur'] ?? 'Auteur supprimé' }}
                    </div>
                    <div>{!! nl2br(e($o['texte'])) !!}</div>
                </div>
            @endforeach
        @endif
    </div>

    {{-- Obligations --}}
    <div class="section">
        <div class="titre-section">Obligations déclaratives</div>
        @if (count($fiche['obligations']) === 0)
            <p class="vide">Aucun assujettissement connu : l'obligation est déduite de la première déclaration de chaque impôt.</p>
        @else
            <table class="liste">
                <thead>
                    <tr>
                        <th style="width: 18%">Impôt</th>
                        <th style="width: 18%">Exercice</th>
                        <th style="width: 32%">Périodicité</th>
                        <th style="width: 32%">Situation</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($fiche['obligations'] as $o)
                        <tr>
                            <td><strong>{{ $o['type_impot'] }}</strong></td>
                            <td>{{ $o['annee_fiscale'] }}</td>
                            <td>{{ $o['periodicite'] }}</td>
                            <td>
                                @if ($o['exonere']) Exonéré
                                @elseif (! $o['actif']) Inactif
                                @else Actif
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- Historique --}}
    <div class="section-libre">
        <div class="titre-section">Historique des déclarations ({{ $fiche['stats']['nb_declarations'] }})</div>
        @php $mensuel = $fiche['historique']['mensuel']; $annuel = $fiche['historique']['annuel']; @endphp
        @if (count($mensuel['impots']) === 0 && count($annuel['impots']) === 0)
            <p class="vide">Aucune déclaration enregistrée.</p>
        @else
            @if (count($mensuel['impots']) > 0)
                <table class="matrice">
                    <thead>
                        <tr>
                            <th class="impot">Impôt</th>
                            @foreach ($mensuel['periodes'] as $p)
                                <th>{{ sprintf('%02d/%02d', $p['mois'], $p['annee'] % 100) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($mensuel['impots'] as $ligne)
                            <tr>
                                <td class="impot">{{ $ligne['type_impot'] }}</td>
                                @foreach ($ligne['cellules'] as $cell)
                                    @php $baisse = isset($baisses[$ligne['type_impot'] . '|' . $cell['annee'] . '|' . $cell['mois']]); @endphp
                                    @if ($cell['declare'])
                                        <td><strong>{{ $baisse ? '▼' : '●' }}</strong></td>
                                    @elseif (! $cell['obligee'])
                                        <td>·</td>
                                    @elseif ($cell['echue'])
                                        <td><strong>×</strong></td>
                                    @else
                                        <td>…</td>
                                    @endif
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            @if (count($annuel['impots']) > 0)
                <table class="matrice" style="margin-top: 6px">
                    <thead>
                        <tr>
                            <th class="impot">Impôt</th>
                            @foreach ($annuel['annees'] as $annee)
                                <th>{{ $annee }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($annuel['impots'] as $ligne)
                            <tr>
                                <td class="impot">{{ $ligne['type_impot'] }}</td>
                                @foreach ($ligne['cellules'] as $cell)
                                    @php $baisse = isset($baisses[$ligne['type_impot'] . '|' . $cell['annee'] . '|' . $cell['mois']]); @endphp
                                    @if ($cell['declare'])
                                        <td><strong>{{ $baisse ? '▼' : '●' }}</strong></td>
                                    @elseif (! $cell['obligee'])
                                        <td>·</td>
                                    @elseif ($cell['echue'])
                                        <td><strong>×</strong></td>
                                    @else
                                        <td>…</td>
                                    @endif
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
            <p class="legende">● déclaré · ▼ baisse du chiffre d'affaires · × non déposé (date limite dépassée) · … pas encore dû · · aucune obligation</p>
            <p class="note">
                Première année déclarée : {{ $fiche['stats']['premiere_annee'] ?? '—' }}
                · dernière année déclarée : {{ $fiche['stats']['derniere_annee'] ?? '—' }}
            </p>
        @endif
    </div>

    <table class="signature">
        <tr>
            <td class="cadre" style="width: 46%">
                Visa du chef de centre
                <div class="mention" style="margin-top: 26px">Nom, signature et cachet</div>
            </td>
            <td style="width: 8%"></td>
            <td class="cadre" style="width: 46%">
                Antananarivo, le {{ $genereLe->format('d/m/Y') }}
                <div class="fonction">{{ $fonction ?: 'Agent rédacteur' }}</div>
                <div class="nom">{{ $auteur }}</div>
                <div class="mention" style="margin-top: 18px">Signature et cachet</div>
            </td>
        </tr>
    </table>

    <div class="pied">
        <strong>Document interne</strong> — fiche générée par {{ $auteur }} le {{ $genereLe->format('d/m/Y à H:i') }}
        · contribuable {{ $c['nif'] }} · {{ $c['nom'] }}.
    </div>
</body>
</html>
