<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Indicateur;
use App\Models\Regle;
use App\Services\EvaluateurConditions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Indicateurs : valeurs lues dans les colonnes de la table « declarations » (central, superadmin).
 * Seules les colonnes numériques existantes peuvent être choisies ; aucun nom de colonne saisi librement n'est utilisé.
 */
class IndicateurController extends Controller
{
    private const COLONNES_EXCLUES = ['id', 'contribuable_id', 'annee_fiscale', 'mois', 'created_at', 'updated_at'];

    public function index(): JsonResponse
    {
        $this->verifierInstallation();

        $utilises = $this->codesUtilises();

        return response()->json([
            'indicateurs' => Indicateur::orderBy('libelle')->get()->map(fn (Indicateur $i) => [
                'id' => $i->id,
                'code' => $i->code,
                'libelle' => $i->libelle,
                'description' => $i->description,
                'colonne' => $i->colonne,
                'unite' => $i->unite,
                'impots' => $i->impots,
                'actif' => $i->actif,
                'systeme' => $i->systeme,
                'utilise' => in_array($i->code, $utilises, true),
            ])->values(),
            'colonnes_disponibles' => $this->colonnesNumeriques(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->verifierInstallation();
        $data = $this->valider($request, null);

        return response()->json(Indicateur::create($data + ['systeme' => false, 'actif' => true]), 201);
    }

    public function update(Request $request, Indicateur $indicateur): JsonResponse
    {
        $this->verifierInstallation();
        $data = $this->valider($request, $indicateur);

        if ($indicateur->systeme) {
            unset($data['colonne'], $data['code']); // indicateur fourni : colonne et code figés
        }
        if (array_key_exists('actif', $data) && ! $data['actif'] && in_array($indicateur->code, $this->codesUtilises(), true)) {
            throw ValidationException::withMessages(['actif' => 'Cet indicateur est utilisé par une règle personnalisée : modifiez ou supprimez la règle d\'abord.']);
        }

        $indicateur->update($data);

        return response()->json($indicateur->fresh());
    }

    public function destroy(Indicateur $indicateur): JsonResponse
    {
        abort_if($indicateur->systeme, 422, 'Un indicateur fourni avec l\'application ne peut pas être supprimé.');

        if (in_array($indicateur->code, $this->codesUtilises(), true)) {
            throw ValidationException::withMessages(['code' => 'Cet indicateur est utilisé par une règle personnalisée.']);
        }

        $indicateur->delete();

        return response()->json(['message' => 'Indicateur supprimé.']);
    }

    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function valider(Request $request, ?Indicateur $existant): array
    {
        $creation = $existant === null;
        $impots = array_values((array) config('anomalies.defaillance.impots'));

        return $request->validate([
            'code' => [$creation ? 'required' : 'sometimes', 'regex:/^[a-z][a-z0-9_]{1,39}$/', Rule::unique('indicateurs', 'code')->ignore($existant?->id)],
            'libelle' => [$creation ? 'required' : 'sometimes', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'colonne' => [$creation ? 'required' : 'sometimes', Rule::in(array_column($this->colonnesNumeriques(), 'colonne'))],
            'unite' => ['nullable', 'string', 'max:20'],
            'impots' => ['nullable', 'array'],
            'impots.*' => [Rule::in($impots)],
            'actif' => ['sometimes', 'boolean'],
        ], [
            'code.regex' => 'Code : lettres minuscules, chiffres et _, commençant par une lettre (2 à 40 caractères).',
            'colonne.in' => 'Cette colonne n\'existe pas dans la table des déclarations, ou n\'est pas numérique.',
        ]);
    }

    /** @return array<int, array{colonne: string, type: string}> colonnes numériques de « declarations » utilisables comme indicateur */
    private function colonnesNumeriques(): array
    {
        if (! Schema::hasTable('declarations')) {
            return [];
        }

        try {
            $infos = Schema::getColumns('declarations'); // Laravel 11+
        } catch (\Throwable) {
            // Laravel plus ancien : noms seulement (le type n'est pas vérifié ici, le détecteur contrôle la colonne)
            $infos = array_map(fn ($n) => ['name' => $n, 'type_name' => 'numeric'], Schema::getColumnListing('declarations'));
        }

        $colonnes = [];
        foreach ($infos as $c) {
            $nom = (string) $c['name'];
            $type = strtolower((string) ($c['type_name'] ?? $c['type'] ?? ''));

            if (in_array($nom, self::COLONNES_EXCLUES, true) || ! preg_match('/^[a-z_][a-z0-9_]*$/i', $nom)) {
                continue;
            }
            if (preg_match('/int|numeric|decimal|float|double|real|money/', $type)) {
                $colonnes[] = ['colonne' => $nom, 'type' => $type];
            }
        }

        return $colonnes;
    }

    /** @return array<int, string> codes d'indicateurs utilisés par au moins une règle personnalisée */
    private function codesUtilises(): array
    {
        $codes = [];
        foreach (Regle::personnalisees()->get(['conditions']) as $r) {
            $codes = array_merge($codes, EvaluateurConditions::indicateursUtilises((array) $r->conditions));
        }

        return array_values(array_unique($codes));
    }

    private function verifierInstallation(): void
    {
        abort_unless(Schema::hasTable('indicateurs'), 503, 'Lancez « php artisan migrate » dans le dossier backend, puis « php artisan config:clear ».');
    }
}
