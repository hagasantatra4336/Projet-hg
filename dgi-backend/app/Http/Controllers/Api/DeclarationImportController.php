<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Centre;
use App\Models\Contribuable;
use App\Models\Declaration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * EXF-001 : import d'un lot de déclarations depuis un fichier CSV (séparateur « ; » ou « , »).
 *
 * Colonnes obligatoires : nif ; nom ; centre ; type_impot ; periode ; montant
 * Colonnes facultatives   : chiffre_affaires (utilisée pour détecter les baisses de CA) ; date_depot
 *   - type_impot : TVA, IR, IS ou IRSA          - periode : AAAA-MM (ex. 2026-07)
 *   - centre     : nom exact d'un centre existant
 * Une déclaration déjà présente (même NIF + impôt + période) est mise à jour.
 * Un admin ne peut importer que pour son propre centre.
 */
class DeclarationImportController extends Controller
{
    private const REQUIRED = ['nif', 'nom', 'centre', 'type_impot', 'periode', 'montant'];
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
        $cols = array_map(
            fn ($h) => Str::of((string) $h)->replace("\xEF\xBB\xBF", '')->trim()->lower()->ascii()->replace([' ', '-'], '_')->toString(),
            $header
        );

        // Alias acceptés pour la colonne du chiffre d'affaires
        $cols = array_map(
            fn (string $c) => in_array($c, ['ca', "chiffre_d'affaires", 'chiffre_d_affaires', 'chiffre_daffaires'], true) ? 'chiffre_affaires' : $c,
            $cols
        );
        $avecCa = in_array('chiffre_affaires', $cols, true);

        $missing = array_diff(self::REQUIRED, $cols);
        if ($missing !== []) {
            fclose($handle);

            return response()->json([
                'message' => 'Colonnes manquantes : ' . implode(', ', $missing) . '. Attendu : nif;nom;centre;type_impot;periode;montant;chiffre_affaires;date_depot',
            ], 422);
        }

        $centres = Centre::pluck('id', 'nom')->mapWithKeys(fn ($id, $nom) => [Str::lower($nom) => $id])->all();
        $impots = config('anomalies.defaillance.impots');

        $result = DB::transaction(function () use ($handle, $delimiter, $cols, $centres, $impots, $centreImpose, $avecCa) {
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

                $nif = $l['nif'];
                $impot = strtoupper($l['type_impot']);
                $periode = $l['periode'];
                $montant = str_replace([' ', "\xC2\xA0"], '', $l['montant']);
                $montant = str_replace(',', '.', $montant);

                if ($nif === '' || strlen($nif) > 30) {
                    $rejeter('NIF vide ou trop long.');
                    continue;
                }
                if (! in_array($impot, $impots, true)) {
                    $rejeter('type_impot « ' . $l['type_impot'] . ' » inconnu (attendu : ' . implode(', ', $impots) . ').');
                    continue;
                }
                if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periode)) {
                    $rejeter('periode « ' . $periode . ' » invalide (format AAAA-MM).');
                    continue;
                }
                if (! is_numeric($montant) || (float) $montant < 0) {
                    $rejeter('montant « ' . $l['montant'] . ' » invalide.');
                    continue;
                }

                $chiffreAffaires = null;
                if ($avecCa && ($l['chiffre_affaires'] ?? '') !== '') {
                    $chiffreAffaires = str_replace(',', '.', str_replace([' ', "\xC2\xA0"], '', $l['chiffre_affaires']));
                    if (! is_numeric($chiffreAffaires) || (float) $chiffreAffaires < 0) {
                        $rejeter('chiffre_affaires « ' . $l['chiffre_affaires'] . ' » invalide.');
                        continue;
                    }
                }

                $dateDepot = null;
                if (($l['date_depot'] ?? '') !== '') {
                    $dateDepot = $this->parseDate($l['date_depot']);
                    if ($dateDepot === null) {
                        $rejeter('date_depot « ' . $l['date_depot'] . ' » invalide (AAAA-MM-JJ ou JJ/MM/AAAA).');
                        continue;
                    }
                }

                $centreId = $centres[Str::lower($l['centre'])] ?? null;
                if ($centreId === null) {
                    $rejeter('centre « ' . $l['centre'] . ' » introuvable.');
                    continue;
                }
                if ($centreImpose !== null && $centreId !== $centreImpose) {
                    $rejeter('ce centre n\'est pas le vôtre.');
                    continue;
                }
                if ($l['nom'] === '') {
                    $rejeter('nom vide.');
                    continue;
                }

                if (! isset($ids[$nif])) {
                    $existant = Contribuable::where('nif', $nif)->first();

                    if ($existant) {
                        if ($centreImpose !== null && $existant->centre_id !== $centreImpose) {
                            $rejeter('ce NIF est rattaché à un autre centre.');
                            continue;
                        }
                        $ids[$nif] = $existant->id;
                    } else {
                        $ids[$nif] = Contribuable::create(['nif' => $nif, 'nom' => $l['nom'], 'centre_id' => $centreId])->id;
                    }
                }

                $ligneDeclaration = [
                    'contribuable_id' => $ids[$nif],
                    'type_impot' => $impot,
                    'periode' => $periode,
                    'montant' => $montant,
                    'date_depot' => $dateDepot,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                // Colonne présente dans le fichier seulement : sinon on ne touche pas au CA déjà enregistré
                if ($avecCa) {
                    $ligneDeclaration['chiffre_affaires'] = $chiffreAffaires;
                }
                $lot[$ids[$nif] . '|' . $impot . '|' . $periode] = $ligneDeclaration;
                $importees++;

                if (count($lot) >= 500) {
                    $this->ecrire($lot, $avecCa);
                    $lot = [];
                }
            }

            $this->ecrire($lot, $avecCa);

            return compact('importees', 'rejetees', 'erreurs');
        });

        fclose($handle);

        return response()->json($result + [
            'message' => sprintf('%d ligne(s) importée(s), %d rejetée(s).', $result['importees'], $result['rejetees']),
        ]);
    }

    /** @param array<string, array<string, mixed>> $lot */
    private function ecrire(array $lot, bool $avecCa): void
    {
        if ($lot !== []) {
            $colonnes = ['montant', 'date_depot', 'updated_at'];
            if ($avecCa) {
                $colonnes[] = 'chiffre_affaires';
            }

            Declaration::upsert(array_values($lot), ['contribuable_id', 'type_impot', 'periode'], $colonnes);
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
