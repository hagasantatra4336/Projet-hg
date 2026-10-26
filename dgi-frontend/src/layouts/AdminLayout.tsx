import { Outlet, useLocation } from "react-router-dom";

// La navigation entre les 3 pages est dans la sidebar (AppLayout) : ici on affiche seulement le titre de la page.
const TITLES: Record<string, string> = {
  "/admin/utilisateurs": "Utilisateurs",
  "/admin/demandes": "Demandes d'inscription",
  "/admin/centres": "Centres",
  "/admin/fonctions": "Fonctions & rôles",
  "/admin/regles": "Règles de détection",
  "/admin/regles/historique": "Historique des règles",
  "/admin/regles-personnalisees": "Règles personnalisées",
};

export default function AdminLayout() {
  const { pathname } = useLocation();
  const title = TITLES[pathname.replace(/\/$/, "")] ?? "Administration";

  return (
    <div>
      <p className="text-sm text-slate-500">Administration</p>
      <h1 className="mb-6 text-2xl font-semibold tracking-tight text-brand-blue">{title}</h1>
      <Outlet />
    </div>
  );
}
