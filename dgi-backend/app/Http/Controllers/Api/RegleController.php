<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Regle;
use App\Models\RegleHistorique;
use App\Services\ReglesDetection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Règles de détection : activation et réglages modifiables depuis l'application (central et super admin).
 * Chaque modification est enregistrée dans l'historique (qui, quand, avant, après).
 */
class RegleController extends Controller
{
    public function index(): JsonResponse
    {
        $this->verifierInstallation();

        $regles = Regle::with('modifiePar')->orderBy('id')->get()
            ->filter(fn (Regle $r) => in_array($r->code, ReglesDetection::CODES, true))
            ->map(fn (Regle $r) => $this->present($r))
            ->values();

        return response()->json(['regles' => $regles]);
    }

    public function update(Request $request, string $code): JsonResponse
    {
        $regle = $this->trouver($code);
        $schema = ReglesDetection::schema($code);

        $rules = ['actif' => ['required', 'boolean'], 'parametres' => ['required', 'array']];
        $noms = [];

        foreach ($schema as $p) {
            $cle = 'parametres.' . $p['cle'];
            $noms[$cle] = mb_strtolower($p['label']);

            switch ($p['type']) {
                case 'liste_impots':
                    $rules[$cle] = ['required', 'array', 'min:1'];
                    $rules[$cle . '.*'] = ['string', Rule::in($p['options'])];
                    break;
                case 'entier':
                    $rules[$cle] = ['required', 'integer', "between:{$p['min']},{$p['max']}"];
                    break;
                case 'decimal':
                    $rules[$cle] = ['required', 'numeric', "between:{$p['min']},{$p['max']}"];
                    break;
                case 'bareme':
                    $rules[$cle] = ['required', 'array', 'min:1', 'max:6'];
                    $rules[$cle . '.*'] = ['array', 'size:3'];
                    $rules[$cle . '.*.0'] = ['required', 'integer', 'between:1,12'];
                    $rules[$cle . '.*.1'] = ['required', 'integer', 'between:1,12'];
                    $rules[$cle . '.*.2'] = ['required', 'integer', 'between:0,5'];
                    break;
            }
        }

        $data = $request->validate($rules, [], $noms);

        // Types normalisés
        $params = [];
        foreach ($schema as $p) {
            $v = $data['parametres'][$p['cle']];
            $params[$p['cle']] = match ($p['type']) {
                'liste_impots' => array_values(array_unique(array_map('strval', $v))),
                'entier' => (int) $v,
                'decimal' => (float) $v,
                'bareme' => array_map(fn ($t) => [(int) $t[0], (int) $t[1], (int) $t[2]], array_values($v)),
            };
        }

        $erreurs = ReglesDetection::verifier($code, $params);
        if ($erreurs !== []) {
            throw ValidationException::withMessages(['parametres' => $erreurs]);
        }

        $avant = ['actif' => (bool) $regle->actif, 'parametres' => ReglesDetection::parametres($code)];
        $regle->update(['actif' => (bool) $data['actif'], 'parametres' => $params, 'modifie_par' => $request->user()->id]);

        RegleHistorique::create([
            'regle_id' => $regle->id,
            'user_id' => $request->user()->id,
            'action' => 'modification',
            'avant' => $avant,
            'apres' => ['actif' => (bool) $regle->actif, 'parametres' => ReglesDetection::parametres($code)],
        ]);

        return response()->json($this->present($regle->fresh('modifiePar')));
    }

    /** Remet les valeurs par défaut (config/anomalies.php) et réactive la règle. */
    public function reinitialiser(Request $request, string $code): JsonResponse
    {
        $regle = $this->trouver($code);
        $avant = ['actif' => (bool) $regle->actif, 'parametres' => ReglesDetection::parametres($code)];

        $regle->update(['actif' => true, 'parametres' => null, 'modifie_par' => $request->user()->id]);

        RegleHistorique::create([
            'regle_id' => $regle->id,
            'user_id' => $request->user()->id,
            'action' => 'reinitialisation',
            'avant' => $avant,
            'apres' => ['actif' => true, 'parametres' => ReglesDetection::parametres($code)],
        ]);

        return response()->json($this->present($regle->fresh('modifiePar')));
    }

    /** Message clair (au lieu d'une erreur 500) si la migration des règles n'a pas été lancée. */
    private function verifierInstallation(): void
    {
        abort_unless(
            Schema::hasTable('regles') && Schema::hasTable('regles_historique'),
            503,
            'Les règles de détection ne sont pas encore installées : lancez « php artisan migrate » dans le dossier backend, puis « php artisan config:clear ».'
        );
    }

    private function trouver(string $code): Regle
    {
        $this->verifierInstallation();

        abort_unless(in_array($code, ReglesDetection::CODES, true), 404, 'Règle inconnue.');

        return Regle::where('code', $code)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function present(Regle $r): array
    {
        $historique = $r->historique()->with('user')->latest('id')->limit(10)->get()->map(fn (RegleHistorique $h) => [
            'id' => $h->id,
            'date' => $h->created_at?->toIso8601String(),
            'par' => $h->user?->nom,
            'action' => $h->action,
            'avant' => $h->avant,
            'apres' => $h->apres,
        ])->values();

        return [
            'code' => $r->code,
            'nom' => $r->nom,
            'description' => $r->description,
            'actif' => (bool) $r->actif,
            'parametres' => ReglesDetection::parametres($r->code),
            'defauts' => ReglesDetection::defauts($r->code),
            'personnalisee' => is_array($r->parametres),
            'schema' => ReglesDetection::schema($r->code),
            'modifie_par' => $r->modifiePar?->nom,
            'modifie_le' => $r->updated_at?->toIso8601String(),
            'historique' => $historique,
        ];
    }
}
