<?php

use App\Http\Controllers\Api\AnalyseAutoController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CentreController;
use App\Http\Controllers\Api\ContribuableController;
use App\Http\Controllers\Api\DeclarationImportController;
use App\Http\Controllers\Api\DefaillanceController;
use App\Http\Controllers\Api\FonctionController;
use App\Http\Controllers\Api\IndicateurController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\RegleController;
use App\Http\Controllers\Api\ReglePersonnaliseeController;
use App\Http\Controllers\Api\UserController;
use App\Http\Middleware\EnsureRole;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------------
//  Inscription publique (3 étapes) et connexion
// ---------------------------------------------------------------------

// Listes du formulaire d'inscription : centres + fonctions de rôle "agent"
Route::get('/signup-options', [AuthController::class, 'signupOptions'])->middleware('throttle:30,1');

// Étape 1 : nom, email, IM, téléphone, adresse, centre, fonction → envoie l'OTP par e-mail
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');

// Étape 2 : vérification du code OTP (valable 10 minutes) → renvoie un setup_token
Route::post('/register/verify-otp', [AuthController::class, 'verifyOtp'])->middleware('throttle:6,1');
Route::post('/register/resend-otp', [AuthController::class, 'resendOtp'])->middleware('throttle:3,1');

// Étape 3 : définition du mot de passe (+ confirmation), uniquement avec le setup_token
Route::post('/register/set-password', [AuthController::class, 'setPassword'])->middleware('throttle:10,1');

// Connexion en 2 étapes : 1) e-mail + mot de passe → envoie un OTP ; 2) OTP → renvoie le jeton
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/login/verify-otp', [AuthController::class, 'verifyLoginOtp'])->middleware('throttle:6,1');
Route::post('/login/resend-otp', [AuthController::class, 'resendLoginOtp'])->middleware('throttle:3,1');

// Espace utilisateur : confirmation par le lien reçu par e-mail
// (publique : le lien peut s'ouvrir sur un autre appareil ; jeton à usage unique)
Route::post('/profile/confirm', [ProfileController::class, 'confirm'])->middleware('throttle:10,1,profile-confirm');

// Page d'attente de validation : suit l'état de la demande (l'identifiant aléatoire de 48 caractères fait office de secret)
Route::get('/profile/change-status/{requestId}', [ProfileController::class, 'changeStatus'])
    ->where('requestId', '[A-Za-z0-9]{48}')
    ->middleware('throttle:300,1,profile-status');

// ---------------------------------------------------------------------
//  Routes protégées (jeton Sanctum)
// ---------------------------------------------------------------------
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Espace utilisateur : demande de modification de ses infos / mot de passe (→ e-mail de confirmation)
    Route::post('/profile/request-change', [ProfileController::class, 'requestChange'])->middleware('throttle:5,1');
    // Annuler la demande en attente (bouton « Annuler » de la page d'attente)
    Route::delete('/profile/request-change', [ProfileController::class, 'cancelChange']);

    // Défaillances de déclaration : consultation et traitement des alertes par tous les rôles
    // (agent et admin ne voient que leur centre : voir DefaillanceController)
    Route::get('/defaillances', [DefaillanceController::class, 'index']);
    Route::get('/defaillances/summary', [DefaillanceController::class, 'summary']);
    Route::patch('/defaillances/{alerte}', [DefaillanceController::class, 'update']);

    // Fiche d'un contribuable (informations, alertes, historique des déclarations) : même filtrage par centre
    Route::get('/contribuables/{contribuable}', [ContribuableController::class, 'show']);

    // Analyse automatique : appelée par le front toutes les 5 min tant qu'une session est ouverte, quel que soit le rôle
    Route::post('/defaillances/auto', AnalyseAutoController::class)->middleware('throttle:100,1');

    // Lancer l'analyse et importer des déclarations (CSV) : superadmin, central, admin (admin = son centre)
    Route::middleware(EnsureRole::class . ':superadmin,central,admin')->group(function () {
        Route::post('/defaillances/analyser', [DefaillanceController::class, 'analyser'])->middleware('throttle:10,1');
        Route::post('/declarations/import', [DeclarationImportController::class, 'store'])->middleware('throttle:10,1');
    });

    // Règles de détection : réglages modifiables depuis l'application (central, superadmin)
    Route::middleware(EnsureRole::class . ':superadmin,central')->group(function () {
        Route::get('/regles', [RegleController::class, 'index']);
        Route::put('/regles/{code}', [RegleController::class, 'update']);
        Route::post('/regles/{code}/reinitialiser', [RegleController::class, 'reinitialiser']);

        // Indicateurs (valeurs lues dans les déclarations) et règles personnalisées : brouillon → simulation → activation
        Route::get('/indicateurs', [IndicateurController::class, 'index']);
        Route::post('/indicateurs', [IndicateurController::class, 'store']);
        Route::put('/indicateurs/{indicateur}', [IndicateurController::class, 'update']);
        Route::delete('/indicateurs/{indicateur}', [IndicateurController::class, 'destroy']);

        Route::get('/regles-personnalisees/options', [ReglePersonnaliseeController::class, 'options']);
        Route::get('/regles-personnalisees', [ReglePersonnaliseeController::class, 'index']);
        Route::post('/regles-personnalisees', [ReglePersonnaliseeController::class, 'store']);
        Route::get('/regles-personnalisees/{regle}', [ReglePersonnaliseeController::class, 'show']);
        Route::put('/regles-personnalisees/{regle}', [ReglePersonnaliseeController::class, 'update']);
        Route::delete('/regles-personnalisees/{regle}', [ReglePersonnaliseeController::class, 'destroy']);
        Route::post('/regles-personnalisees/{regle}/simuler', [ReglePersonnaliseeController::class, 'simuler'])->middleware('throttle:20,1');
        Route::post('/regles-personnalisees/{regle}/activer', [ReglePersonnaliseeController::class, 'activer']);
        Route::post('/regles-personnalisees/{regle}/desactiver', [ReglePersonnaliseeController::class, 'desactiver']);
        Route::get('/regles/historique', [RegleController::class, 'historique']);
        });

    // Section Administration — gestion des utilisateurs : superadmin, central, admin
    // (l'admin est limité à son propre centre : voir UserController)
    Route::middleware(EnsureRole::class . ':superadmin,central,admin')->group(function () {
        Route::get('/users/options', [UserController::class, 'options']);
        Route::apiResource('users', UserController::class)->except('show');
    });

    // Gestion des centres : superadmin, central
    Route::middleware(EnsureRole::class . ':superadmin,central')->group(function () {
        Route::apiResource('centres', CentreController::class)->except('show');
    });

    // Gestion des fonctions et de leur rôle : superadmin uniquement
    Route::middleware(EnsureRole::class . ':superadmin')->group(function () {
        Route::apiResource('fonctions', FonctionController::class)->except('show');
    });
});
