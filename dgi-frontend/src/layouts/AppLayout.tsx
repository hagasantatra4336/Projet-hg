import { useEffect, useRef, useState, type ReactNode } from "react";
import { Link, NavLink, Outlet, useLocation, useNavigate } from "react-router-dom";
import { useAuth } from "../context/AuthContext";
import { useAnalyseAuto } from "../hooks/useAnalyseAuto";
import { useNotifications } from "../hooks/useNotifications";
import ThemeToggle from "../components/ThemeToggle";
import { ConfirmDialog } from "../components/ui";
import { ROLE_LABELS, type Role } from "../types";
import {
  IconAlert,
  IconBriefcase,
  IconBuilding,
  IconChevron,
  IconChevronDown,
  IconChevronUp,
  IconClose,
  IconDashboard,
  IconLogout,
  IconMenu,
  IconShield,
  IconSliders,
  IconUser,
  IconUserPlus,
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
  { to: "/admin/demandes", label: "Demandes d'inscription", icon: <IconUserPlus className="h-4 w-4" />, roles: ["superadmin", "central", "admin"] },
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

/** Pastille rouge avec le nombre d'éléments en attente. */
function Pastille({ n, titre, className = "" }: { n: number; titre: string; className?: string }) {
  if (n <= 0) return null;
  return (
    <span
      title={titre}
      className={`inline-flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-semibold leading-none text-white ${className}`}
    >
      {n > 99 ? "99+" : n}
    </span>
  );
}

const pluriel = (n: number, mot: string) => `${n} ${mot}${n > 1 ? "s" : ""}`;

export default function AppLayout() {
  const { user, logout } = useAuth();

  // Analyse automatique des défaillances : active tant que cette session est ouverte (stoppée à la déconnexion).
  useAnalyseAuto(user?.role);
  const notifications = useNotifications(user?.role);
  const navigate = useNavigate();
  const { pathname } = useLocation();

  const [mobileOpen, setMobileOpen] = useState<boolean>(false);
  const inAdmin = pathname.startsWith("/admin");
  const [adminOpen, setAdminOpen] = useState<boolean>(inAdmin);
  const [deconnexion, setDeconnexion] = useState<boolean>(false);
  const [deconnexionEnCours, setDeconnexionEnCours] = useState<boolean>(false);
  const [menuCompte, setMenuCompte] = useState<boolean>(false);
  const refCompte = useRef<HTMLDivElement>(null);

  // Les agents n'ont pas accès à l'administration.
  const adminItems = ADMIN_ITEMS.filter((i) => user?.role && i.roles.includes(user.role));

  // Ferme le menu mobile à chaque changement de page ; ouvre le groupe Administration en y entrant.
  useEffect(() => {
    setMobileOpen(false);
    setMenuCompte(false);
    if (pathname.startsWith("/admin")) setAdminOpen(true);
  }, [pathname]);

  // Ferme le menu du compte au clic extérieur ou avec Échap
  useEffect(() => {
    if (!menuCompte) return;
    const surClic = (e: MouseEvent) => {
      if (refCompte.current && !refCompte.current.contains(e.target as Node)) setMenuCompte(false);
    };
    const surTouche = (e: KeyboardEvent) => {
      if (e.key === "Escape") setMenuCompte(false);
    };
    document.addEventListener("mousedown", surClic);
    document.addEventListener("keydown", surTouche);
    return () => {
      document.removeEventListener("mousedown", surClic);
      document.removeEventListener("keydown", surTouche);
    };
  }, [menuCompte]);

  // Déconnexion confirmée par l'agent : ferme la session puis renvoie à l'accueil
  async function confirmerDeconnexion() {
    setDeconnexionEnCours(true);
    try {
      await logout();
      navigate("/");
    } finally {
      setDeconnexionEnCours(false);
      setDeconnexion(false);
    }
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
        <div className="flex items-center gap-1">
          <ThemeToggle variant="sidebar" />
          <button
            type="button"
            onClick={() => setMobileOpen(true)}
            aria-label="Ouvrir le menu"
            className="relative rounded-lg p-2 text-white hover:bg-white/10"
          >
            <IconMenu />
            <Pastille
              n={notifications.defaillances}
              titre={`${pluriel(notifications.defaillances, "dossier")} à traiter`}
              className="absolute -right-1.5 -top-1.5"
            />
          </button>
        </div>
      </div>

      {mobileOpen && (
        <div className="fixed inset-0 z-40 bg-brand-blue-dark/60 lg:hidden" onClick={() => setMobileOpen(false)} aria-hidden="true" />
      )}

      <aside
        className={`fixed inset-y-0 left-0 z-50 flex w-60 flex-col bg-brand-blue px-2.5 py-4 shadow-lg transition-transform lg:sticky lg:bottom-auto lg:top-0 lg:h-screen lg:shrink-0 lg:translate-x-0 lg:shadow-none ${
          mobileOpen ? "translate-x-0" : "-translate-x-full"
        }`}
      >
        <div className="mb-4 flex items-start justify-between border-b border-white/15 px-2 pb-4">
          <div className="flex display-flex space-x-5">
            <div className="flex items-center gap-3">
              <img src="/logo-dgi.png" alt="Direction Générale des Impôts" className="h-9 w-auto" />
              <img src="/logo-mef.png" alt="Ministère de l'Économie et des Finances" className="h-9 w-auto" />
              
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
            <Pastille
              n={notifications.defaillances}
              titre={`${pluriel(notifications.defaillances, "dossier")} à traiter`}
              className="ml-auto"
            />
          </NavLink>

          <NavLink to="/traitements" className={linkClass}>
            <IconBriefcase />
            <span>Traitements</span>
            <Pastille
              n={notifications.traitements}
              titre={`${pluriel(notifications.traitements, "dossier")} en cours de traitement`}
              className="ml-auto"
            />
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
                {!adminOpen && (
                  <Pastille
                    n={notifications.demandes}
                    titre={`${pluriel(notifications.demandes, "demande")} à approuver`}
                  />
                )}
                <IconChevron className={`h-4 w-4 transition-transform ${adminOpen ? "rotate-90" : ""}`} />
              </button>

              {adminOpen && (
                <div className="ml-5 mt-1 space-y-0.5 border-l border-white/20 pl-3">
                  {adminItems.map((i) => (
                    <NavLink key={i.to} to={i.to} className={subLinkClass}>
                      {i.icon}
                      <span>{i.label}</span>
                      {i.to === "/admin/demandes" && (
                        <Pastille
                          n={notifications.demandes}
                          titre={`${pluriel(notifications.demandes, "demande")} à approuver`}
                          className="ml-auto"
                        />
                      )}
                    </NavLink>
                  ))}
                </div>
              )}
            </div>
          )}

          {/* Le profil n'est plus ici : il reste accessible par le nom et le menu du compte en bas. */}
        </nav>

        {/* Compte : le nom mène au profil, le chevron ouvre le menu (profil / déconnexion) */}
        <div ref={refCompte} className="relative mt-4 border-t border-white/15 pt-3">
          <div className="flex items-center gap-1">
            <Link
              to="/profile"
              title={`${user?.nom ?? ""} — voir mon profil`}
              className="flex min-w-0 flex-1 items-center gap-2 rounded-lg py-1 pl-1 pr-1 transition hover:bg-white/10"
            >
              <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-white text-[13px] font-semibold text-brand-blue">
                {initial}
              </span>
              <span className="min-w-0 leading-tight">
                <span className="block truncate text-[13px] font-semibold text-white">{user?.nom}</span>
                <span className="block truncate text-[11px] text-white/70">{user?.role ? ROLE_LABELS[user.role] : ""}</span>
              </span>
            </Link>
            <ThemeToggle variant="sidebar" />
            <button
              type="button"
              onClick={() => setMenuCompte((ouvert) => !ouvert)}
              title="Menu du compte"
              aria-label="Menu du compte"
              aria-haspopup="menu"
              aria-expanded={menuCompte}
              className="rounded-lg p-1.5 text-white/70 transition hover:bg-white/10 hover:text-white"
            >
              {menuCompte ? <IconChevronUp className="h-4 w-4" /> : <IconChevronDown className="h-4 w-4" />}
            </button>
          </div>

          {menuCompte && (
            <div
              role="menu"
              className="absolute bottom-full right-0 mb-2 w-48 overflow-hidden rounded-lg border border-white/15 bg-brand-blue shadow-xl"
            >
              <Link
                to="/profile"
                role="menuitem"
                className="flex items-center gap-2.5 px-3 py-2.5 text-sm text-white/85 transition hover:bg-white/10 hover:text-white"
              >
                <IconUser className="h-4 w-4" />
                Mon profil
              </Link>
              <button
                type="button"
                role="menuitem"
                onClick={() => {
                  setMenuCompte(false);
                  setDeconnexion(true);
                }}
                className="flex w-full items-center gap-2.5 border-t border-white/10 px-3 py-2.5 text-left text-sm text-red-200 transition hover:bg-red-500/20 hover:text-white"
              >
                <IconLogout className="h-4 w-4" />
                Se déconnecter
              </button>
            </div>
          )}
        </div>
      </aside>

      <main className="min-w-0 flex-1 px-3 py-4 sm:px-5 lg:py-5">
        <div className="mx-auto max-w-7xl">
          <Outlet />
        </div>
      </main>

      {deconnexion && (
        <ConfirmDialog
          title="Déconnexion"
          message="Voulez-vous vraiment vous déconnecter ?"
          detail="Vous devrez saisir à nouveau votre mot de passe et le code reçu par e-mail pour revenir."
          confirmLabel="Se déconnecter"
          loadingLabel="Déconnexion..."
          loading={deconnexionEnCours}
          onConfirm={() => void confirmerDeconnexion()}
          onCancel={() => setDeconnexion(false)}
        />
      )}
    </div>
  );
}
