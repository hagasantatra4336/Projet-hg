<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Demande de compte refusée</title>
</head>
<body style="margin:0;padding:24px;background:#F8F8F8;font-family:Arial,Helvetica,sans-serif;">
    <div style="max-width:480px;margin:0 auto;background:#ffffff;border-radius:16px;padding:32px;">
        <h1 style="margin:0 0 16px;font-size:20px;color:#212E53;">Demande de compte refusée</h1>

        <p style="margin:0 0 16px;color:#444444;font-size:14px;">
            Bonjour {{ $nom }},<br>
            votre demande de compte n'a pas été approuvée par le responsable de votre centre.
            Aucun compte n'a été créé et aucun code de vérification ne vous sera envoyé.
        </p>

        @if ($motif)
            <p style="margin:0 0 16px;padding:12px 16px;background:#FDF2F2;border-left:4px solid #C0392B;color:#7B241C;font-size:14px;">
                Motif indiqué : {{ $motif }}
            </p>
        @endif

        <p style="margin:0 0 20px;color:#444444;font-size:14px;">
            Si vous pensez qu'il s'agit d'une erreur, rapprochez-vous du chef de votre centre.
        </p>

        <p style="margin:0;text-align:center;">
            <a href="{{ $url }}" style="display:inline-block;background:#7AA95C;color:#212E53;font-size:14px;font-weight:bold;padding:12px 24px;border-radius:10px;text-decoration:none;">
                Refaire une demande
            </a>
        </p>
    </div>
</body>
</html>
