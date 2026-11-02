import { Link } from "react-router-dom";
import { useAuth } from "../context/AuthContext";

/**
 * Affichée quand l'URL saisie correspond à une page dont le rôle de l'utilisateur ne fait pas partie.
 * Le contrôle est aussi fait côté API : cette page évite seulement d'afficher une coquille vide.
 */
export default function AccessDeniedPage() {
  const { user } = useAuth();

  return (
    <div className="mx-auto max-w-xl overflow-hidden rounded-2xl border border-line bg-white text-center shadow-md">
      <div className="h-1 bg-gradient-to-r from-brand-gold via-brand-gold to-brand-blue" />
      <div className="p-8">
        <span className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-brand-gold-soft text-lg font-bold text-brand-gold-dark ring-1 ring-inset ring-brand-gold/30">
          !
        </span>
        <p className="mt-4 text-xs font-semibold uppercase tracking-wider text-brand-gold-dark">Erreur 403</p>
        <h1 className="mt-2 text-2xl font-semibold tracking-tight text-brand-blue">Accès refusé</h1>
        <p className="mt-3 text-sm text-slate-600">
          Cette page n'est pas accessible avec votre fonction
          {user?.fonction ? ` (${user.fonction.nom})` : ""}. Si vous pensez qu'il s'agit d'une erreur,
          contactez un responsable central.
        </p>
        <Link
          to="/dashboard"
          className="mt-6 inline-flex items-center justify-center rounded-lg bg-gradient-to-r from-brand-blue to-brand-blue-dark px-4 py-2 text-sm font-semibold text-white shadow-sm shadow-brand-blue/25 transition hover:from-brand-blue-light hover:to-brand-blue focus:outline-none focus:ring-4 focus:ring-brand-azure/20"
        >
          Retour au tableau de bord
        </Link>
      </div>
    </div>
  );
}
