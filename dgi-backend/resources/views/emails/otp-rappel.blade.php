<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Votre code expire bientôt</title>
</head>
<body style="margin:0;padding:24px;background:#F8F8F8;font-family:Arial,Helvetica,sans-serif;">
    <div style="max-width:480px;margin:0 auto;background:#ffffff;border-radius:16px;padding:32px;">
        @include('emails.partials.dgi-header')

        <h1 style="margin:0 0 16px;font-size:20px;color:#212E53;">
            Votre code expire bientôt
        </h1>

        <p style="margin:0 0 16px;color:#444444;font-size:14px;">
            Bonjour {{ $nom }},<br>
            votre demande de compte a bien été approuvée, mais le code de vérification qui vous a été
            envoyé n'a pas encore été saisi.
        </p>

        <p style="margin:0 0 16px;padding:12px 16px;background:#FBF3E1;border-left:4px solid #C4952B;color:#5B4410;font-size:14px;">
            Ce code ne sera <strong>plus valable dans {{ \App\Services\OtpService::dureeLisible($minutes) }}</strong>.
        </p>

        <p style="margin:0 0 16px;color:#444444;font-size:14px;">
            Passé ce délai, la demande d'inscription est clôturée : votre inscription n'aboutira pas
            et vous devrez <strong>déposer une nouvelle demande</strong> depuis la page d'inscription.
        </p>

        <p style="margin:0 0 24px;text-align:center;">
            <a href="{{ $url }}"
               style="display:inline-block;background:#1E3A8A;color:#ffffff;font-size:14px;font-weight:bold;text-decoration:none;padding:12px 24px;border-radius:8px;">
                Reprendre mon inscription
            </a>
        </p>

        <p style="margin:0;color:#666666;font-size:13px;">
            Si vous avez déjà saisi votre code, ignorez simplement cet e-mail et terminez la
            création de votre compte.
        </p>
    </div>
</body>
</html>
