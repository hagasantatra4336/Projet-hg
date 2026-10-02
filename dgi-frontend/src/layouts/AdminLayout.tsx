import { NavLink, Outlet } from "react-router-dom";
import { useAuth } from "../context/AuthContext";
import type { Role } from "../types";

interface Tab {
  to: string;
  label: string;
  roles: Role[];
}

// Une seule section "Administration" regroupant les 3 pages, chacune visible selon le rôle.
const TABS: Tab[] = [
  { to: "/admin/utilisateurs", label: "Utilisateurs", roles: ["superadmin", "central", "admin"] },
  { to: "/admin/centres", label: "Centres", roles: ["superadmin", "central"] },
  { to: "/admin/fonctions", label: "Fonctions & rôles", roles: ["superadmin"] },
];

export default function AdminLayout() {
  const { user } = useAuth();
  const tabs = TABS.filter((t) => user?.role && t.roles.includes(user.role));

  return (
    <div>
      <h1 className="text-2xl font-bold text-brand-blue mb-4">Administration</h1>

      <div className="flex gap-1 border-b border-gray-200 mb-6">
        {tabs.map((t) => (
          <NavLink
            key={t.to}
            to={t.to}
            className={({ isActive }) =>
              `px-4 py-2 text-sm font-medium -mb-px border-b-2 transition ${
                isActive
                  ? "border-brand-blue text-brand-blue"
                  : "border-transparent text-gray-500 hover:text-brand-blue"
              }`
            }
          >
            {t.label}
          </NavLink>
        ))}
      </div>

      <Outlet />
    </div>
  );
}
