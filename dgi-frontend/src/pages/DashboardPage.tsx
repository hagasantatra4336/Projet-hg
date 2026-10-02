import { useEffect, useState, type ReactNode } from "react";
import { Link } from "react-router-dom";
import api, { getErrorMessage } from "../services/api";
import { useAuth } from "../context/AuthContext";
import { ROLE_LABELS, type AppUser, type Centre, type Fonction, type Paginated } from "../types";
import { ErrorBox, RoleBadge, primaryButtonClass } from "../components/ui";
import { IconBriefcase, IconBuilding, IconChevron, IconSearch, IconUsers } from "../components/icons";

interface Stat {
  label: string;
  value: string;
  icon: ReactNode;
}

function StatsCard({ stats }: { stats: Stat[] }) {
  return (
    <div className="flex flex-col divide-y divide-gray-100 rounded-2xl bg-white p-2 shadow-sm sm:flex-row sm:divide-x sm:divide-y-0">
      {stats.map((s) => (
        <div key={s.label} className="flex flex-1 items-center gap-4 px-5 py-4">
          <span className="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-brand-green/30 text-brand-blue">
            {s.icon}
          </span>
          <div className="min-w-0">
            <p className="text-xs text-gray-500">{s.label}</p>
            <p className="truncate text-3xl font-bold text-brand-blue">{s.value}</p>
          </div>
        </div>
      ))}
    </div>
  );
}

/** Numéros de pages affichés autour de la page courante (max 5). */
function pageWindow(current: number, last: number): number[] {
  const size = Math.min(5, last);
  const start = Math.max(1, Math.min(current - 2, last - size + 1));
  return Array.from({ length: size }, (_, i) => start + i);
}

export default function DashboardPage() {
  const { user } = useAuth();
  const role = user?.role ?? null;

  const canUsers = role === "superadmin" || role === "central" || role === "admin";
  const canCentres = role === "superadmin" || role === "central";
  const canFonctions = role === "superadmin";

  const [data, setData] = useState<Paginated<AppUser> | null>(null);
  const [totalUsers, setTotalUsers] = useState<number | null>(null);
  const [centresCount, setCentresCount] = useState<number | null>(null);
  const [fonctionsCount, setFonctionsCount] = useState<number | null>(null);
  const [loading, setLoading] = useState<boolean>(canUsers);
  const [error, setError] = useState<string>("");

  const [page, setPage] = useState<number>(1);
  const [searchInput, setSearchInput] = useState<string>("");
  const [search, setSearch] = useState<string>("");

  // Recherche avec un petit délai
  useEffect(() => {
    const t = setTimeout(() => {
      setSearch(searchInput.trim());
      setPage(1);
    }, 300);
    return () => clearTimeout(t);
  }, [searchInput]);

  // Liste des utilisateurs (même API que la page Administration > Utilisateurs)
  useEffect(() => {
    if (!canUsers) return;
    let cancelled = false;
    setLoading(true);
    api
      .get<Paginated<AppUser>>("/users", { params: { page, search: search || undefined } })
      .then((res) => {
        if (cancelled) return;
        setData(res.data);
        if (!search) setTotalUsers(res.data.total);
        setError("");
      })
      .catch((err) => {
        if (!cancelled) setError(getErrorMessage(err, "Impossible de charger les utilisateurs."));
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [canUsers, page, search]);

  // Compteurs centres / fonctions selon les droits
  useEffect(() => {
    if (canCentres) {
      api.get<Centre[]>("/centres").then((res) => setCentresCount(res.data.length)).catch(() => undefined);
    }
    if (canFonctions) {
      api.get<Fonction[]>("/fonctions").then((res) => setFonctionsCount(res.data.length)).catch(() => undefined);
    }
  }, [canCentres, canFonctions]);

  const fmt = (n: number | null) => (n === null ? "—" : n.toLocaleString("fr-FR"));

  const stats: Stat[] = [];
  if (canUsers) stats.push({ label: "Total utilisateurs", value: fmt(totalUsers), icon: <IconUsers className="h-7 w-7" /> });
  if (canCentres) stats.push({ label: "Centres", value: fmt(centresCount), icon: <IconBuilding className="h-7 w-7" /> });
  if (canFonctions) stats.push({ label: "Fonctions", value: fmt(fonctionsCount), icon: <IconBriefcase className="h-7 w-7" /> });
  if (role === "admin") {
    stats.push({ label: "Mon centre", value: user?.centre?.nom ?? "—", icon: <IconBuilding className="h-7 w-7" /> });
  }

  const from = data && data.total > 0 ? (data.current_page - 1) * 10 + 1 : 0;
  const to = data ? Math.min(data.current_page * 10, data.total) : 0;

  return (
    <div>
      <h1 className="text-2xl font-bold text-brand-blue">Bonjour {user?.nom}</h1>
      <p className="mb-6 text-sm text-gray-500">
        {role && ROLE_LABELS[role]}
        {user?.centre && ` · ${user.centre.nom}`}
      </p>

      {!canUsers ? (
        // Agent : pas d'accès à l'administration → résumé de son compte
        <div className="rounded-2xl bg-white p-6 shadow-sm">
          <h2 className="mb-4 text-lg font-bold text-brand-blue">Mon compte</h2>
          <dl className="grid gap-4 text-sm sm:grid-cols-2">
            {[
              ["E-mail", user?.email],
              ["IM", user?.im],
              ["Centre", user?.centre?.nom],
              ["Fonction", user?.fonction?.nom],
            ].map(([k, v]) => (
              <div key={k}>
                <dt className="text-gray-500">{k}</dt>
                <dd className="font-medium text-brand-blue">{v || "—"}</dd>
              </div>
            ))}
          </dl>
          <Link to="/profile" className={`${primaryButtonClass} mt-6 inline-flex items-center gap-1`}>
            Modifier mon profil <IconChevron className="h-4 w-4" />
          </Link>
        </div>
      ) : (
        <>
          <StatsCard stats={stats} />

          <section className="mt-6 rounded-2xl bg-white p-5 shadow-sm sm:p-6">
            <div className="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <h2 className="text-lg font-bold text-brand-blue">Tous les utilisateurs</h2>
                <p className="text-sm text-gray-500">Comptes actifs de votre périmètre</p>
              </div>
              <div className="flex items-center gap-3">
                <div className="relative flex-1 sm:w-64">
                  <IconSearch className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                  <input
                    value={searchInput}
                    onChange={(e) => setSearchInput(e.target.value)}
                    placeholder="Rechercher..."
                    aria-label="Rechercher un utilisateur"
                    className="w-full rounded-lg border border-gray-200 bg-brand-white py-2 pl-9 pr-3 text-sm placeholder-gray-400 focus:border-transparent focus:outline-none focus:ring-2 focus:ring-brand-green"
                  />
                </div>
                <Link to="/admin/utilisateurs" className={`${primaryButtonClass} whitespace-nowrap text-sm`}>
                  Gérer
                </Link>
              </div>
            </div>

            <ErrorBox message={error} />

            <div className="overflow-x-auto">
              <table className="w-full min-w-[720px] text-left text-sm">
                <thead>
                  <tr className="text-gray-400">
                    {["Nom", "Centre", "Téléphone", "E-mail", "Fonction", "Rôle"].map((h) => (
                      <th key={h} className="px-3 py-3 text-xs font-medium">
                        {h}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody className={loading ? "opacity-50 transition" : "transition"}>
                  {data?.data.map((u) => (
                    <tr key={u.id} className="border-t border-gray-100">
                      <td className="px-3 py-4 font-medium text-brand-blue">{u.nom}</td>
                      <td className="px-3 py-4 text-gray-700">{u.centre?.nom ?? "—"}</td>
                      <td className="px-3 py-4 text-gray-700">{u.telephone ?? "—"}</td>
                      <td className="px-3 py-4 text-gray-700">{u.email}</td>
                      <td className="px-3 py-4 text-gray-700">{u.fonction?.nom ?? "—"}</td>
                      <td className="px-3 py-4">
                        <RoleBadge role={u.role} />
                      </td>
                    </tr>
                  ))}
                  {data && data.data.length === 0 && (
                    <tr>
                      <td colSpan={6} className="px-3 py-10 text-center text-gray-500">
                        Aucun utilisateur trouvé.
                      </td>
                    </tr>
                  )}
                  {!data && loading && (
                    <tr>
                      <td colSpan={6} className="px-3 py-10 text-center text-gray-500">
                        Chargement...
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>

            {data && data.total > 0 && (
              <div className="mt-4 flex flex-col gap-3 border-t border-gray-100 pt-4 sm:flex-row sm:items-center sm:justify-between">
                <p className="text-xs text-gray-500">
                  Affichage de {from} à {to} sur {data.total.toLocaleString("fr-FR")} entrées
                </p>
                <div className="flex items-center gap-1">
                  <button
                    type="button"
                    onClick={() => setPage((p) => Math.max(1, p - 1))}
                    disabled={data.current_page <= 1}
                    aria-label="Page précédente"
                    className="rounded-md px-2 py-1 text-sm text-gray-500 hover:bg-brand-green/20 disabled:opacity-40"
                  >
                    ‹
                  </button>
                  {pageWindow(data.current_page, data.last_page).map((n) => (
                    <button
                      key={n}
                      type="button"
                      onClick={() => setPage(n)}
                      aria-current={n === data.current_page ? "page" : undefined}
                      className={`h-8 min-w-8 rounded-md px-2 text-sm transition ${
                        n === data.current_page
                          ? "bg-brand-blue font-semibold text-white"
                          : "text-gray-600 hover:bg-brand-green/20"
                      }`}
                    >
                      {n}
                    </button>
                  ))}
                  <button
                    type="button"
                    onClick={() => setPage((p) => Math.min(data.last_page, p + 1))}
                    disabled={data.current_page >= data.last_page}
                    aria-label="Page suivante"
                    className="rounded-md px-2 py-1 text-sm text-gray-500 hover:bg-brand-green/20 disabled:opacity-40"
                  >
                    ›
                  </button>
                </div>
              </div>
            )}
          </section>
        </>
      )}
    </div>
  );
}
