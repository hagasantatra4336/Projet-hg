import { Link } from "react-router-dom";
import { useAuth } from "../context/AuthContext";

/**
 * Affichée quand l'URL saisie correspond à une page dont le rôle de l'utilisateur ne fait pas partie.
 * Le contrôle est aussi fait côté API : cette page évite seulement d'afficher une coquille vide.
 */
export default function AccessDeniedPage() {
  const { user } = useAuth();

  return (
    <div className="mx-auto max-w-xl rounded-xl border border-line bg-white p-8 text-center">
      <p className="text-sm font-semibold tracking-wide text-brand-gold">Erreur 403</p>
      <h1 className="mt-2 text-2xl font-semibold tracking-tight text-brand-blue">Accès refusé</h1>
      <p className="mt-3 text-sm text-slate-600">
        Cette page n'est pas accessible avec votre fonction
        {user?.fonction ? ` (${user.fonction.nom})` : ""}. Si vous pensez qu'il s'agit d'une erreur,
        contactez un responsable central.
      </p>
      <Link
        to="/dashboard"
        className="mt-6 inline-flex items-center justify-center rounded-lg bg-brand-blue px-4 py-2 text-sm font-semibold text-white transition hover:brightness-110"
      >
        Retour au tableau de bord
      </Link>
    </div>
  );
}
