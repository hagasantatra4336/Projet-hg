<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CentreController;
use App\Http\Controllers\Api\ContribuableController;
use App\Http\Controllers\Api\DeclarationImportController;
use App\Http\Controllers\Api\DefaillanceController;
use App\Http\Controllers\Api\FonctionController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SourceImportController;
use App\Http\Controllers\Api\UserController;
use App\Http\Middleware\EnsureRole;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AnalyseAutoController;

// Listes du formulaire d'inscription : centres + fonctions de rôle "agent"
Route::get('/signup-options', [AuthController::class, 'signupOptions'])->middleware('throttle:30,1');

// Étape 1 : nom, email, IM, téléphone, adresse, centre, fonction → envoie l'OTP par e-mail
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');

// Étape 2 : vérification du code OTP (valable 10 minutes) → renvoie un setup_token
Route::post('/register/verify-otp', [AuthController::class, 'verifyOtp'])->middleware('throttle:6,1');
Route::post('/register/resend-otp', [AuthController::class, 'resendOtp'])->middleware('throttle:3,1');

// Étape 3 : définition du mot de passe (+ confirmation), uniquement avec le setup_token
Route::post('/register/set-password', [AuthController::class, 'setPassword'])->middleware('throttle:10,1');


Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/login/verify-otp', [AuthController::class, 'verifyLoginOtp'])->middleware('throttle:6,1');
Route::post('/login/resend-otp', [AuthController::class, 'resendLoginOtp'])->middleware('throttle:3,1');


Route::post('/profile/confirm', [ProfileController::class, 'confirm'])->middleware('throttle:10,1');


Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

     
    Route::post('/profile/request-change', [ProfileController::class, 'requestChange'])->middleware('throttle:5,1');

    
    Route::post('/defaillances/auto', AnalyseAutoController::class)->middleware('throttle:100,1');
    
    Route::get('/defaillances', [DefaillanceController::class, 'index']);
    Route::get('/defaillances/summary', [DefaillanceController::class, 'summary']);
    Route::patch('/defaillances/{alerte}', [DefaillanceController::class, 'update']);

    Route::get('/contribuables/{contribuable}', [ContribuableController::class, 'show']);

    // Lancer l'analyse et importer des déclarations (CSV) : superadmin, central, admin (admin = son centre)
    Route::middleware(EnsureRole::class . ':superadmin,central,admin')->group(function () {
        Route::post('/defaillances/analyser', [DefaillanceController::class, 'analyser'])->middleware('throttle:10,1');
        Route::post('/declarations/import', [DeclarationImportController::class, 'store'])->middleware('throttle:10,1');

        // Récupérer les déclarations depuis la base PostgreSQL source (lecture seule) ; l'admin = son centre
        Route::get('/declarations/source/test', [SourceImportController::class, 'tester'])->middleware('throttle:10,1');
        Route::post('/declarations/import-source', [SourceImportController::class, 'store'])->middleware('throttle:10,1');
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
