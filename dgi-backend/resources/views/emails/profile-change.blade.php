<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Confirmation des modifications</title>
</head>
<body style="margin:0;padding:24px;background:#F8F8F8;font-family:Arial,Helvetica,sans-serif;">
    <div style="max-width:480px;margin:0 auto;background:#ffffff;border-radius:16px;padding:32px;">
        <h1 style="margin:0 0 16px;font-size:20px;color:#212E53;">Confirmez les modifications de votre compte</h1>

        <p style="margin:0 0 16px;color:#444444;font-size:14px;">
            Bonjour {{ $nom }},<br>
            Une demande de modification de votre compte a été enregistrée.<br>
            Éléments concernés : <strong>{{ implode(', ', $champs) }}</strong>.
        </p>

        <p style="margin:0 0 20px;text-align:center;">
            <a href="{{ $url }}" style="display:inline-block;background:#212E53;color:#ffffff;text-decoration:none;font-size:15px;font-weight:bold;padding:12px 24px;border-radius:10px;">
                Confirmer les modifications
            </a>
        </p>

        <p style="margin:0 0 12px;color:#666666;font-size:13px;">
            Ce lien est valable <strong>{{ $minutes }} minutes</strong> et ne peut être utilisé qu'une seule fois.
            Tant que vous ne cliquez pas, rien n'est modifié.
        </p>

        <p style="margin:0 0 12px;color:#666666;font-size:13px;">
            Si vous n'êtes pas à l'origine de cette demande, ignorez cet e-mail et, par précaution, changez votre mot de passe.
        </p>

        <p style="margin:0;color:#999999;font-size:12px;word-break:break-all;">
            Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :<br>
            {{ $url }}
        </p>
    </div>
</body>
</html>
