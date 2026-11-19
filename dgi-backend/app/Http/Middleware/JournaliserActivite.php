<?php

namespace App\Http\Middleware;

use App\Models\Activite;
use App\Services\JournalActivite;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Journalise les actions qui modifient le système (POST, PUT, PATCH, DELETE).
 *
 * Le middleware tourne après « auth:sanctum » : l'auteur est donc connu. Les réponses en erreur
 * ne sont pas journalisées (rien n'a été modifié), et les routes non répertoriées sont ignorées
 * pour ne pas polluer le journal.
 */
class JournaliserActivite
{
    public function __construct(private readonly JournalActivite $journal) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() >= 400) {
            return $response;
        }

        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $response;
        }

        $entree = $this->decrire($request);
        $user = $request->user();

        if ($entree === null || $user === null) {
            return $response;
        }

        $this->journal->enregistrer(
            $request,
            $entree['action'],
            $entree['categorie'],
            $entree['description'],
            $this->cible($request),
            $user,
        );

        return $response;
    }

    /** @return array{action: string, categorie: string, description: string}|null */
    private function decrire(Request $request): ?array
    {
        $chemin = trim((string) preg_replace('#^api/#', '', $request->path()), '/');
        $methode = $request->method();

        // Connexion, déconnexion et création de compte sont journalisées dans AuthController :
        // le jeton n'existe pas encore (ou plus) au moment du passage dans ce middleware.
        // L'analyse automatique est une tâche de fond déclenchée par le front, pas une action utilisateur.
        if (in_array($chemin, ['logout', 'defaillances/auto', 'register/set-password', 'login/verify-otp'], true)) {
            return null;
        }

        foreach ($this->regles() as $regle) {
            if (! in_array($methode, $regle['methodes'], true)) {
                continue;
            }

            if (preg_match($regle['motif'], $chemin) === 1) {
                return [
                    'action' => $regle['action'],
                    'categorie' => $regle['categorie'],
                    'description' => $regle['description'],
                ];
            }
        }

        return null;
    }

    /** Élément concerné par l'action : nom du compte, du centre, de la règle... */
    private function cible(Request $request): ?string
    {
        foreach ($request->route()?->parameters() ?? [] as $valeur) {
            if ($valeur instanceof Model) {
                foreach (['nom', 'libelle', 'code', 'titre', 'email'] as $attribut) {
                    $texte = $valeur->getAttribute($attribut);

                    if (is_string($texte) && $texte !== '') {
                        return $texte;
                    }
                }

                continue;
            }

            if (is_string($valeur) && $valeur !== '') {
                return $valeur;
            }
        }

        return null;
    }

    /**
     * @return array<int, array{methodes: array<int, string>, motif: string, categorie: string, action: string, description: string}>
     */
    private function regles(): array
    {
        $admin = Activite::CATEGORIE_ADMINISTRATION;
        $metier = Activite::CATEGORIE_METIER;
        $regles = Activite::CATEGORIE_REGLES;
        $profil = Activite::CATEGORIE_PROFIL;

        return [
            // -- Utilisateurs -------------------------------------------------
            ['methodes' => ['POST'], 'motif' => '#^users$#', 'categorie' => $admin, 'action' => 'creation_utilisateur', 'description' => 'Création d’un compte utilisateur'],
            ['methodes' => ['PUT', 'PATCH'], 'motif' => '#^users/[^/]+$#', 'categorie' => $admin, 'action' => 'modification_utilisateur', 'description' => 'Modification d’un compte utilisateur'],
            ['methodes' => ['DELETE'], 'motif' => '#^users/[^/]+$#', 'categorie' => $admin, 'action' => 'suppression_utilisateur', 'description' => 'Suppression d’un compte utilisateur'],
            ['methodes' => ['POST'], 'motif' => '#^users/[^/]+/desactiver$#', 'categorie' => $admin, 'action' => 'desactivation_compte', 'description' => 'Désactivation d’un compte utilisateur'],
            ['methodes' => ['POST'], 'motif' => '#^users/[^/]+/reactiver$#', 'categorie' => $admin, 'action' => 'reactivation_compte', 'description' => 'Réactivation d’un compte utilisateur'],

            // -- Centres et fonctions -----------------------------------------
            ['methodes' => ['PATCH'], 'motif' => '#^centres/[^/]+/affichage$#', 'categorie' => $admin, 'action' => 'affichage_centre', 'description' => 'Affichage ou masquage d’un centre'],
            ['methodes' => ['POST'], 'motif' => '#^centres$#', 'categorie' => $admin, 'action' => 'creation_centre', 'description' => 'Création d’un centre'],
            ['methodes' => ['PUT', 'PATCH'], 'motif' => '#^centres/[^/]+$#', 'categorie' => $admin, 'action' => 'modification_centre', 'description' => 'Modification d’un centre'],
            ['methodes' => ['DELETE'], 'motif' => '#^centres/[^/]+$#', 'categorie' => $admin, 'action' => 'suppression_centre', 'description' => 'Suppression d’un centre'],
            ['methodes' => ['POST'], 'motif' => '#^fonctions$#', 'categorie' => $admin, 'action' => 'creation_fonction', 'description' => 'Création d’une fonction'],
            ['methodes' => ['PUT', 'PATCH'], 'motif' => '#^fonctions/[^/]+$#', 'categorie' => $admin, 'action' => 'modification_fonction', 'description' => 'Modification d’une fonction'],
            ['methodes' => ['DELETE'], 'motif' => '#^fonctions/[^/]+$#', 'categorie' => $admin, 'action' => 'suppression_fonction', 'description' => 'Suppression d’une fonction'],

            // -- Demandes d'inscription --------------------------------------
            ['methodes' => ['POST'], 'motif' => '#^demandes-inscription/[^/]+/approuver$#', 'categorie' => $admin, 'action' => 'approbation_inscription', 'description' => 'Approbation d’une demande d’inscription'],
            ['methodes' => ['POST'], 'motif' => '#^demandes-inscription/[^/]+/refuser$#', 'categorie' => $admin, 'action' => 'refus_inscription', 'description' => 'Refus d’une demande d’inscription'],

            // -- Règles de détection et indicateurs ---------------------------
            ['methodes' => ['POST'], 'motif' => '#^regles/[^/]+/reinitialiser$#', 'categorie' => $regles, 'action' => 'reinitialisation_regle', 'description' => 'Réinitialisation d’un réglage de détection'],
            ['methodes' => ['PUT', 'PATCH'], 'motif' => '#^regles/[^/]+$#', 'categorie' => $regles, 'action' => 'modification_regle', 'description' => 'Modification d’un réglage de détection'],
            ['methodes' => ['POST'], 'motif' => '#^indicateurs$#', 'categorie' => $regles, 'action' => 'creation_indicateur', 'description' => 'Création d’un indicateur'],
            ['methodes' => ['PUT', 'PATCH'], 'motif' => '#^indicateurs/[^/]+$#', 'categorie' => $regles, 'action' => 'modification_indicateur', 'description' => 'Modification d’un indicateur'],
            ['methodes' => ['DELETE'], 'motif' => '#^indicateurs/[^/]+$#', 'categorie' => $regles, 'action' => 'suppression_indicateur', 'description' => 'Suppression d’un indicateur'],
            ['methodes' => ['POST'], 'motif' => '#^regles-personnalisees$#', 'categorie' => $regles, 'action' => 'creation_regle_personnalisee', 'description' => 'Création d’une règle personnalisée'],
            ['methodes' => ['PUT', 'PATCH'], 'motif' => '#^regles-personnalisees/[^/]+$#', 'categorie' => $regles, 'action' => 'modification_regle_personnalisee', 'description' => 'Modification d’une règle personnalisée'],
            ['methodes' => ['DELETE'], 'motif' => '#^regles-personnalisees/[^/]+$#', 'categorie' => $regles, 'action' => 'suppression_regle_personnalisee', 'description' => 'Suppression d’une règle personnalisée'],
            ['methodes' => ['POST'], 'motif' => '#^regles-personnalisees/[^/]+/simuler$#', 'categorie' => $regles, 'action' => 'simulation_regle_personnalisee', 'description' => 'Simulation d’une règle personnalisée'],
            ['methodes' => ['POST'], 'motif' => '#^regles-personnalisees/[^/]+/activer$#', 'categorie' => $regles, 'action' => 'activation_regle_personnalisee', 'description' => 'Activation d’une règle personnalisée'],
            ['methodes' => ['POST'], 'motif' => '#^regles-personnalisees/[^/]+/desactiver$#', 'categorie' => $regles, 'action' => 'desactivation_regle_personnalisee', 'description' => 'Désactivation d’une règle personnalisée'],

            // -- Traitement métier -------------------------------------------
            ['methodes' => ['POST'], 'motif' => '#^defaillances/analyser$#', 'categorie' => $metier, 'action' => 'lancement_analyse', 'description' => 'Lancement de l’analyse des défaillances'],
            ['methodes' => ['POST'], 'motif' => '#^defaillances/[^/]+/prendre$#', 'categorie' => $metier, 'action' => 'prise_dossier', 'description' => 'Prise en charge d’un dossier'],
            ['methodes' => ['POST'], 'motif' => '#^defaillances/[^/]+/liberer$#', 'categorie' => $metier, 'action' => 'liberation_dossier', 'description' => 'Libération d’un dossier'],
            ['methodes' => ['POST'], 'motif' => '#^defaillances/[^/]+/assigner$#', 'categorie' => $metier, 'action' => 'affectation_dossier', 'description' => 'Affectation d’un dossier à un agent'],
            ['methodes' => ['PATCH'], 'motif' => '#^defaillances/[^/]+$#', 'categorie' => $metier, 'action' => 'traitement_alerte', 'description' => 'Traitement d’une alerte de défaillance'],
            ['methodes' => ['POST'], 'motif' => '#^source/centres/import$#', 'categorie' => $metier, 'action' => 'import_centres_source', 'description' => 'Import des centres depuis la source Oracle'],
            ['methodes' => ['POST'], 'motif' => '#^source/import$#', 'categorie' => $metier, 'action' => 'import_source', 'description' => 'Import depuis la source Oracle'],
            ['methodes' => ['PATCH'], 'motif' => '#^contribuables/[^/]+/contact$#', 'categorie' => $metier, 'action' => 'modification_contact', 'description' => 'Mise à jour des coordonnées d’un contribuable'],
            ['methodes' => ['POST'], 'motif' => '#^contribuables/[^/]+/observations$#', 'categorie' => $metier, 'action' => 'ajout_observation', 'description' => 'Ajout d’une observation sur une fiche'],

            // -- Profil personnel --------------------------------------------
            ['methodes' => ['POST'], 'motif' => '#^profile/request-change$#', 'categorie' => $profil, 'action' => 'demande_modification_profil', 'description' => 'Demande de modification du profil'],
            ['methodes' => ['DELETE'], 'motif' => '#^profile/request-change$#', 'categorie' => $profil, 'action' => 'annulation_modification_profil', 'description' => 'Annulation d’une demande de modification du profil'],
        ];
    }
}
