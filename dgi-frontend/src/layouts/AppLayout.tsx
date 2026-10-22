import { useEffect, useState, type ReactNode } from "react";
import { NavLink, Outlet, useLocation, useNavigate } from "react-router-dom";
import { useAuth } from "../context/AuthContext";
import { useAnalyseAuto } from "../hooks/useAnalyseAuto";
import { ROLE_LABELS, type Role } from "../types";
import {
  IconAlert,
  IconBriefcase,
  IconBuilding,
  IconChevron,
  IconClose,
  IconDashboard,
  IconLogout,
  IconMenu,
  IconShield,
  IconSliders,
  IconUser,
  IconUsers,
} from "../components/icons";

interface SubItem {
  to: string;
  label: string;
  icon: ReactNode;
  roles: Role[];
}

// Sous-menu "Administration" : chaque entrée est visible selon le rôle (mêmes règles que les routes).
const ADMIN_ITEMS: SubItem[] = [
  { to: "/admin/utilisateurs", label: "Utilisateurs", icon: <IconUsers className="h-4 w-4" />, roles: ["superadmin", "central", "admin"] },
  { to: "/admin/centres", label: "Centres", icon: <IconBuilding className="h-4 w-4" />, roles: ["superadmin", "central"] },
  { to: "/admin/regles", label: "Règles de détection", icon: <IconSliders className="h-4 w-4" />, roles: ["superadmin", "central"] },
  { to: "/admin/fonctions", label: "Fonctions & rôles", icon: <IconBriefcase className="h-4 w-4" />, roles: ["superadmin"] },
];

const itemBase = "flex w-full items-center gap-3 rounded-md px-3 py-2.5 text-sm font-medium transition";
const itemActive = "bg-white/10 text-white shadow-[inset_3px_0_0_#7AA95C]";
const itemIdle = "text-white/70 hover:bg-white/5 hover:text-white";

const linkClass = ({ isActive }: { isActive: boolean }) => `${itemBase} ${isActive ? itemActive : itemIdle}`;

const subLinkClass = ({ isActive }: { isActive: boolean }) =>
  `flex items-center gap-2.5 rounded-md px-3 py-2 text-sm transition ${
    isActive ? "bg-white/10 font-semibold text-white" : "text-white/60 hover:text-white"
  }`;

export default function AppLayout() {
  const { user, logout } = useAuth();

  // Analyse automatique des défaillances : active tant que cette session est ouverte (stoppée à la déconnexion).
  useAnalyseAuto(user?.role);
  const navigate = useNavigate();
  const { pathname } = useLocation();

  const [mobileOpen, setMobileOpen] = useState<boolean>(false);
  const inAdmin = pathname.startsWith("/admin");
  const [adminOpen, setAdminOpen] = useState<boolean>(inAdmin);

  // Les agents n'ont pas accès à l'administration.
  const adminItems = ADMIN_ITEMS.filter((i) => user?.role && i.roles.includes(user.role));

  // Ferme le menu mobile à chaque changement de page ; ouvre le groupe Administration en y entrant.
  useEffect(() => {
    setMobileOpen(false);
    if (pathname.startsWith("/admin")) setAdminOpen(true);
  }, [pathname]);

  async function handleLogout() {
    await logout();
    navigate("/");
  }

  const initial = (user?.nom ?? "?").trim().charAt(0).toUpperCase();

  return (
    <div className="min-h-screen bg-canvas lg:flex">
      {/* Barre du haut (mobile uniquement) */}
      <div className="sticky top-0 z-30 flex h-14 items-center justify-between bg-brand-blue px-4 lg:hidden">
        <div className="flex items-center gap-3">
          <img src="/logo-dgi.png" alt="Direction Générale des Impôts" className="h-8 w-auto" />
          <img src="/logo-mef.png" alt="Ministère de l'Économie et des Finances" className="h-8 w-auto" />
        </div>
        <button
          type="button"
          onClick={() => setMobileOpen(true)}
          aria-label="Ouvrir le menu"
          className="rounded-lg p-2 text-white hover:bg-white/10"
        >
          <IconMenu />
        </button>
      </div>

      {mobileOpen && (
        <div className="fixed inset-0 z-40 bg-brand-blue-dark/60 lg:hidden" onClick={() => setMobileOpen(false)} aria-hidden="true" />
      )}

      <aside
        className={`fixed inset-y-0 left-0 z-50 flex w-64 flex-col bg-brand-blue px-3 py-5 shadow-lg transition-transform lg:sticky lg:bottom-auto lg:top-0 lg:h-screen lg:shrink-0 lg:translate-x-0 lg:shadow-none ${
          mobileOpen ? "translate-x-0" : "-translate-x-full"
        }`}
      >
        <div className="mb-6 flex items-start justify-between border-b border-white/15 px-2 pb-5">
          <div className="flex display-flex space-x-5">
            <div className="flex items-center gap-3">
              <img src="/logo-dgi.png" alt="Direction Générale des Impôts" className="h-10 w-auto" />
              <img src="/logo-mef.png" alt="Ministère de l'Économie et des Finances" className="h-10 w-auto" />
              
            </div>
            <p className="mt-3 text-sm font-semibold tracking-tight text-white">E-Kajy</p>
          </div>
          <button
            type="button"
            onClick={() => setMobileOpen(false)}
            aria-label="Fermer le menu"
            className="rounded-lg p-1.5 text-white/60 hover:text-white lg:hidden"
          >
            <IconClose />
          </button>
        </div>

        <nav className="flex-1 space-y-1 overflow-y-auto">
          <NavLink to="/dashboard" className={linkClass}>
            <IconDashboard />
            <span>Tableau de bord</span>
          </NavLink>

          <NavLink to="/defaillances" className={linkClass}>
            <IconAlert />
            <span>Défaillances</span>
          </NavLink>

          <NavLink to="/traitements" className={linkClass}>
            <IconBriefcase />
            <span>Traitements</span>
          </NavLink>

          {adminItems.length > 0 && (
            <div>
              <button
                type="button"
                onClick={() => setAdminOpen((o) => !o)}
                aria-expanded={adminOpen}
                className={`${itemBase} ${inAdmin ? "text-white" : itemIdle}`}
              >
                <IconShield />
                <span className="flex-1 text-left">Administration</span>
                <IconChevron className={`h-4 w-4 transition-transform ${adminOpen ? "rotate-90" : ""}`} />
              </button>

              {adminOpen && (
                <div className="ml-5 mt-1 space-y-0.5 border-l border-white/20 pl-3">
                  {adminItems.map((i) => (
                    <NavLink key={i.to} to={i.to} className={subLinkClass}>
                      {i.icon}
                      <span>{i.label}</span>
                    </NavLink>
                  ))}
                </div>
              )}
            </div>
          )}

          <NavLink to="/profile" className={linkClass}>
            <IconUser />
            <span>Mon profil</span>
          </NavLink>
        </nav>

        {/* Utilisateur connecté + déconnexion */}
        <div className="mt-4 flex items-center gap-3 rounded-lg border border-white/10 bg-white/5 p-3">
          <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-brand-white font-semibold text-brand-blue">
            {initial}
          </span>
          <div className="min-w-0 flex-1">
            <p className="truncate text-sm font-semibold text-white">{user?.nom}</p>
            <p className="truncate text-xs text-white/60">{user?.role ? ROLE_LABELS[user.role] : ""}</p>
          </div>
          <button
            type="button"
            onClick={handleLogout}
            title="Déconnexion"
            aria-label="Déconnexion"
            className="rounded-lg p-2 text-white/60 transition hover:bg-white/10 hover:text-white"
          >
            <IconLogout />
          </button>
        </div>
      </aside>

      <main className="min-w-0 flex-1 px-4 py-6 sm:px-8 lg:py-8">
        <div className="mx-auto max-w-6xl">
          <Outlet />
        </div>
      </main>
    </div>
  );
}
