<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Nouvelle demande de compte</title>
</head>
<body style="margin:0;padding:24px;background:#F8F8F8;font-family:Arial,Helvetica,sans-serif;">
    <div style="max-width:520px;margin:0 auto;background:#ffffff;border-radius:16px;padding:32px;">
        <h1 style="margin:0 0 16px;font-size:20px;color:#212E53;">Nouvelle demande de compte</h1>

        <p style="margin:0 0 16px;color:#444444;font-size:14px;">
            Un agent demande la création d'un compte dans votre centre. Merci de vérifier son identité
            avant d'approuver ou de refuser la demande.
        </p>

        <table style="width:100%;border-collapse:collapse;font-size:14px;color:#333333;margin:0 0 20px;">
            <tr><td style="padding:6px 0;color:#666666;">Nom</td><td style="padding:6px 0;font-weight:bold;">{{ $nom }}</td></tr>
            <tr><td style="padding:6px 0;color:#666666;">E-mail</td><td style="padding:6px 0;">{{ $email }}</td></tr>
            <tr><td style="padding:6px 0;color:#666666;">IM (matricule)</td><td style="padding:6px 0;">{{ $im ?: '—' }}</td></tr>
            <tr><td style="padding:6px 0;color:#666666;">Téléphone</td><td style="padding:6px 0;">{{ $telephone ?: '—' }}</td></tr>
            <tr><td style="padding:6px 0;color:#666666;">Centre</td><td style="padding:6px 0;">{{ $centre ?: '—' }}</td></tr>
            <tr><td style="padding:6px 0;color:#666666;">Fonction</td><td style="padding:6px 0;">{{ $fonction ?: '—' }}</td></tr>
            <tr><td style="padding:6px 0;color:#666666;">Déposée le</td><td style="padding:6px 0;">{{ $deposee_le }}</td></tr>
        </table>

        <p style="margin:0 0 20px;text-align:center;">
            <a href="{{ $url }}" style="display:inline-block;background:#212E53;color:#ffffff;font-size:14px;font-weight:bold;padding:12px 24px;border-radius:10px;text-decoration:none;">
                Voir la demande
            </a>
        </p>

        <p style="margin:0;color:#666666;font-size:13px;">
            Si vous approuvez, un code de vérification valable <strong>5 minutes</strong> est envoyé
            immédiatement à l'agent. En cas de refus, aucun code n'est envoyé et le compte n'est pas créé.
            La demande reste valable 7 jours.
        </p>
    </div>
</body>
</html>
