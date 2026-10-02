import { NavLink, Outlet, useNavigate } from "react-router-dom";
import { useAuth } from "../context/AuthContext";
import { ROLE_LABELS } from "../types";

const navClass = ({ isActive }: { isActive: boolean }) =>
  `text-sm font-medium transition ${isActive ? "text-brand-green" : "text-white/80 hover:text-white"}`;

export default function AppLayout() {
  const { user, logout } = useAuth();
  const navigate = useNavigate();

  // Les agents n'ont pas accès à l'administration.
  const canAdmin = user?.role === "superadmin" || user?.role === "central" || user?.role === "admin";

  async function handleLogout() {
    await logout();
    navigate("/");
  }

  return (
    <div className="min-h-screen bg-brand-white">
      <header className="bg-brand-blue">
        <div className="mx-auto max-w-6xl px-4 h-14 flex items-center justify-between">
          <nav className="flex items-center gap-6">
            <span className="font-bold tracking-wide text-white">DGI</span>
            <NavLink to="/dashboard" className={navClass}>
              Tableau de bord
            </NavLink>
            {canAdmin && (
              <NavLink to="/admin" className={navClass}>
                Administration
              </NavLink>
            )}
            <NavLink to="/profile" className={navClass}>
              Mon profil
            </NavLink>
          </nav>
          <div className="flex items-center gap-4 text-sm text-white">
            <span className="hidden sm:inline">
              {user?.nom}
              {user?.role && <span className="text-white/60"> · {ROLE_LABELS[user.role]}</span>}
            </span>
            <button type="button" onClick={handleLogout} className="text-white/80 hover:text-white underline">
              Déconnexion
            </button>
          </div>
        </div>
      </header>

      <main className="mx-auto max-w-6xl px-4 py-8">
        <Outlet />
      </main>
    </div>
  );
}
