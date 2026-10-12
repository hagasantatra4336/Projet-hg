<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Centre;
use App\Models\Contribuable;
use App\Models\Declaration;
use App\Support\Nif;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * EXF-001 : import d'un lot de déclarations depuis un fichier CSV (séparateur « ; » ou « , »).
 *
 * Colonnes obligatoires : nif ; type_impot ; annee_fiscale
 * Colonnes facultatives : mois (1 à 12 pour une déclaration mensuelle ; vide ou 0 pour une déclaration annuelle) ;
 *                         chiffre_affaires ; date_limite ; date_depot ; nom ; centre
 *   - type_impot : TVA, IR, IS ou IRSA          - annee_fiscale : 4 chiffres (ex. 2025)
 *   - dates : AAAA-MM-JJ ou JJ/MM/AAAA          - centre : nom exact d'un centre existant
 * Une déclaration déjà présente (même NIF + impôt + année + mois) est mise à jour ; seules les colonnes présentes
 * dans le fichier sont modifiées. Un admin ne peut importer que pour son propre centre.
 * (L'import depuis la base source, lui, charge aussi les assujettissements et l'état des contribuables.)
 */
class DeclarationImportController extends Controller
{
    private const REQUIRED = ['nif', 'type_impot', 'annee_fiscale'];
    private const MAX_ERRORS = 15;

    public function store(Request $request): JsonResponse
    {
        $request->validate(['fichier' => ['required', 'file', 'max:5120']]);

        // Extension contrôlée à la main : Excel envoie parfois un CSV avec un type MIME « ms-excel » refusé par « mimes: ».
        $extension = strtolower($request->file('fichier')->getClientOriginalExtension());
        abort_if(! in_array($extension, ['csv', 'txt'], true), 422, 'Le fichier doit être au format CSV.');

        $me = $request->user();
        $centreImpose = null;

        if ($me->role === Role::Admin) {
            abort_if($me->centre_id === null, 403, "Votre compte n'est rattaché à aucun centre.");
            $centreImpose = $me->centre_id;
        }

        $handle = fopen($request->file('fichier')->getRealPath(), 'r');
        abort_if($handle === false, 422, 'Fichier illisible.');

        $first = (string) fgets($handle);
        rewind($handle);
        $delimiter = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';

        $header = fgetcsv($handle, 0, $delimiter) ?: [];
        $cols = array_map(function ($h) {
            $c = Str::of((string) $h)->replace("\xEF\xBB\xBF", '')->trim()->lower()->ascii()->replace([' ', '-'], '_')->toString();

            // Alias acceptés pour les en-têtes
            return match (true) {
                in_array($c, ['ca', "chiffre_d'affaires", 'chiffre_d_affaires', 'chiffre_daffaires'], true) => 'chiffre_affaires',
                in_array($c, ['annee', 'exercice'], true) => 'annee_fiscale',
                $c === 'impot' => 'type_impot',
                $c === 'date_echeance' => 'date_limite',
                $c === 'date_reception' => 'date_depot',
                default => $c,
            };
        }, $header);

        $missing = array_diff(self::REQUIRED, $cols);
        if ($missing !== []) {
            fclose($handle);

            return response()->json([
                'message' => 'Colonnes manquantes : ' . implode(', ', $missing)
                    . '. Attendu : nif;type_impot;annee_fiscale;mois;chiffre_affaires;date_limite;date_depot (+ nom;centre facultatifs)',
            ], 422);
        }

        $centres = Centre::pluck('id', 'nom')->mapWithKeys(fn ($id, $nom) => [Str::lower($nom) => $id])->all();
        $impots = config('anomalies.defaillance.impots');

        // Colonnes facultatives présentes dans le fichier : seules celles-ci sont écrites
        $presentes = array_values(array_intersect(['chiffre_affaires', 'date_limite', 'date_depot'], $cols));

        $result = DB::transaction(function () use ($handle, $delimiter, $cols, $centres, $impots, $centreImpose, $presentes) {
            $importees = 0;
            $rejetees = 0;
            $erreurs = [];
            $ids = [];   // NIF => id du contribuable (évite de re-chercher à chaque ligne)
            $lot = [];   // clé unique => ligne à insérer (dédoublonne les lignes répétées)
            $now = now()->toDateTimeString();
            $numero = 1; // la ligne 1 est l'en-tête

            $rejeter = function (string $raison) use (&$rejetees, &$erreurs, &$numero) {
                $rejetees++;
                if (count($erreurs) < self::MAX_ERRORS) {
                    $erreurs[] = "Ligne {$numero} : {$raison}";
                }
            };

            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                $numero++;

                if ($row === [null] || trim(implode('', $row)) === '') {
                    continue; // ligne vide
                }

                $cells = array_map(fn ($v) => trim((string) $v), array_pad(array_slice($row, 0, count($cols)), count($cols), ''));
                $l = array_combine($cols, $cells);

                $nif = Nif::normaliser($l['nif']);
                $impot = strtoupper($l['type_impot']);
                $annee = $l['annee_fiscale'];
                $mois = ($l['mois'] ?? '') === '' ? '0' : $l['mois'];

                if ($nif === '' || strlen($nif) > 30) {
                    $rejeter('NIF vide ou trop long.');
                    continue;
                }
                if (! in_array($impot, $impots, true)) {
                    $rejeter('type_impot « ' . $l['type_impot'] . ' » inconnu (attendu : ' . implode(', ', $impots) . ').');
                    continue;
                }
                if (! preg_match('/^\d{4}$/', $annee) || (int) $annee < 1990 || (int) $annee > 2100) {
                    $rejeter('annee_fiscale « ' . $annee . ' » invalide (4 chiffres, ex. 2025).');
                    continue;
                }
                if (! ctype_digit($mois) || (int) $mois > 12) {
                    $rejeter('mois « ' . $mois . ' » invalide (1 à 12, ou vide pour une déclaration annuelle).');
                    continue;
                }

                $chiffreAffaires = null;
                if (in_array('chiffre_affaires', $presentes, true) && ($l['chiffre_affaires'] ?? '') !== '') {
                    $valeur = str_replace(',', '.', str_replace([' ', "\xC2\xA0"], '', $l['chiffre_affaires']));

                    if (! is_numeric($valeur) || (float) $valeur < 0) {
                        $rejeter('chiffre_affaires « ' . $l['chiffre_affaires'] . ' » invalide.');
                        continue;
                    }
                    $chiffreAffaires = $valeur;
                }

                $dates = [];
                foreach (['date_limite', 'date_depot'] as $champ) {
                    $dates[$champ] = null;

                    if (in_array($champ, $presentes, true) && ($l[$champ] ?? '') !== '') {
                        $dates[$champ] = $this->parseDate($l[$champ]);

                        if ($dates[$champ] === null) {
                            $rejeter($champ . ' « ' . $l[$champ] . ' » invalide (AAAA-MM-JJ ou JJ/MM/AAAA).');
                            continue 2;
                        }
                    }
                }

                // Centre (facultatif) : pour un admin, c'est toujours le sien
                $centreId = null;
                $centreNom = $l['centre'] ?? '';
                if ($centreNom !== '') {
                    $centreId = $centres[Str::lower($centreNom)] ?? null;
                    if ($centreId === null) {
                        $rejeter('centre « ' . $centreNom . ' » introuvable.');
                        continue;
                    }
                }
                if ($centreImpose !== null) {
                    if ($centreId !== null && $centreId !== $centreImpose) {
                        $rejeter('ce centre n\'est pas le vôtre.');
                        continue;
                    }
                    $centreId = $centreImpose;
                }

                if (! isset($ids[$nif])) {
                    $existant = Contribuable::where('nif', $nif)->first();

                    if ($existant) {
                        if ($centreImpose !== null && $existant->centre_id !== $centreImpose) {
                            $rejeter('ce NIF est rattaché à un autre centre.');
                            continue;
                        }

                        // Complète le contribuable avec ce que le fichier apporte (centre manquant, vrai nom)
                        $maj = [];
                        if ($existant->centre_id === null && $centreId !== null) {
                            $maj['centre_id'] = $centreId;
                        }
                        if (($l['nom'] ?? '') !== '' && $existant->nom === $existant->nif) {
                            $maj['nom'] = $l['nom'];
                        }
                        if ($maj !== []) {
                            $existant->update($maj);
                        }
                        $ids[$nif] = $existant->id;
                    } else {
                        $ids[$nif] = Contribuable::create([
                            'nif' => $nif,
                            'nom' => ($l['nom'] ?? '') !== '' ? $l['nom'] : $nif,
                            'centre_id' => $centreId,
                        ])->id;
                    }
                }

                $ligneDeclaration = [
                    'contribuable_id' => $ids[$nif],
                    'type_impot' => $impot,
                    'annee_fiscale' => (int) $annee,
                    'mois' => (int) $mois,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                // Colonnes présentes dans le fichier seulement : sinon on ne touche pas aux valeurs déjà enregistrées
                foreach ($presentes as $champ) {
                    $ligneDeclaration[$champ] = $champ === 'chiffre_affaires' ? $chiffreAffaires : $dates[$champ];
                }
                $lot[$ids[$nif] . '|' . $impot . '|' . $annee . '|' . $mois] = $ligneDeclaration;
                $importees++;

                if (count($lot) >= 500) {
                    $this->ecrire($lot, $presentes);
                    $lot = [];
                }
            }

            $this->ecrire($lot, $presentes);

            return compact('importees', 'rejetees', 'erreurs');
        });

        fclose($handle);

        return response()->json($result + [
            'message' => sprintf('%d ligne(s) importée(s), %d rejetée(s).', $result['importees'], $result['rejetees']),
        ]);
    }

    /**
     * @param array<string, array<string, mixed>> $lot
     * @param array<int, string> $presentes
     */
    private function ecrire(array $lot, array $presentes): void
    {
        if ($lot !== []) {
            Declaration::upsert(array_values($lot), ['contribuable_id', 'type_impot', 'annee_fiscale', 'mois'], array_merge($presentes, ['updated_at']));
        }
    }

    private function parseDate(string $value): ?string
    {
        foreach (['Y-m-d', 'd/m/Y'] as $format) {
            $d = \DateTime::createFromFormat('!' . $format, $value);

            if ($d !== false && $d->format($format) === $value) {
                return $d->format('Y-m-d');
            }
        }

        return null;
    }
}
