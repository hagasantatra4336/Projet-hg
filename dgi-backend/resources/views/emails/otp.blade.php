<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Code de vérification</title>
</head>
<body style="margin:0;padding:24px;background:#F8F8F8;font-family:Arial,Helvetica,sans-serif;">
    <div style="max-width:480px;margin:0 auto;background:#ffffff;border-radius:16px;padding:32px;">
        <h1 style="margin:0 0 16px;font-size:20px;color:#212E53;">
            {{ ($contexte ?? 'inscription') === 'connexion' ? 'Code de connexion' : 'Vérification de votre e-mail' }}
        </h1>

        <p style="margin:0 0 16px;color:#444444;font-size:14px;">
            Bonjour {{ $nom }},<br>
            Voici votre code de vérification :
        </p>

        <p style="margin:0 0 16px;text-align:center;">
            <span style="display:inline-block;background:#7AA95C;color:#212E53;font-size:30px;font-weight:bold;letter-spacing:8px;padding:12px 24px;border-radius:12px;">
                {{ $code }}
            </span>
        </p>

        <p style="margin:0;color:#666666;font-size:13px;">
            Ce code est valable <strong>{{ $minutes }} minutes</strong>.
            @if (($contexte ?? 'inscription') === 'connexion')
                Si vous n'êtes pas à l'origine de cette connexion, ne communiquez ce code à personne et changez votre mot de passe.
            @else
                Si vous n'êtes pas à l'origine de cette demande, ignorez simplement cet e-mail.
            @endif
        </p>
    </div>
</body>
</html>
