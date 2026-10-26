import { useEffect, useState, type ReactNode } from "react";
import { Link } from "react-router-dom";
import api, { getErrorMessage } from "../services/api";
import { useAuth } from "../context/AuthContext";
import { ROLE_LABELS, type AppUser, type Centre, type Fonction, type Paginated } from "../types";
import { ErrorBox, RoleBadge, primaryButtonClass } from "../components/ui";
import AgentAlertes from "../components/AgentAlertes";
import { IconBriefcase, IconBuilding, IconChevron, IconSearch, IconUsers } from "../components/icons";

interface Stat {
  label: string;
  /** nombre (animé) ou texte, null = en cours de chargement */
  value: number | string | null;
  icon: ReactNode;
}

/** Animation d'entrée unique (désactivée si l'utilisateur réduit les animations). */
const ANIMATIONS = `
@keyframes dash-rise { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }
.dash-rise { animation: dash-rise .5s cubic-bezier(.2,.7,.2,1) both; }
@media (prefers-reduced-motion: reduce) { .dash-rise { animation: none; } }
`;

/** Compte de 0 jusqu'à la valeur cible. */
function useCountUp(target: number | null, duration = 700): number | null {
  const [display, setDisplay] = useState<number | null>(target === null ? null : 0);

  useEffect(() => {
    if (target === null) {
      setDisplay(null);
      return;
    }
    if (window.matchMedia?.("(prefers-reduced-motion: reduce)").matches) {
      setDisplay(target);
      return;
    }
    let frame = 0;
    const start = performance.now();
    const tick = (now: number) => {
      const p = Math.min(1, (now - start) / duration);
      const eased = 1 - Math.pow(1 - p, 3);
      setDisplay(Math.round(target * eased));
      if (p < 1) frame = requestAnimationFrame(tick);
    };
    frame = requestAnimationFrame(tick);
    return () => cancelAnimationFrame(frame);
  }, [target, duration]);

  return display;
}

function StatValue({ value }: { value: number | string | null }) {
  const animated = useCountUp(typeof value === "number" ? value : null);
  if (value === null) return <span className="inline-block h-6 w-14 animate-pulse rounded-md bg-slate-100 align-middle" />;
  if (typeof value === "string") return <>{value}</>;
  return <>{(animated ?? 0).toLocaleString("fr-FR")}</>;
}

function StatsCard({ stats }: { stats: Stat[] }) {
  return (
    <div
      className={`grid gap-3 grid-cols-1 sm:grid-cols-2 ${
        stats.length >= 3 ? "lg:grid-cols-3" : ""
      } ${stats.length >= 4 ? "xl:grid-cols-4" : ""}`}
    >
      {stats.map((s, i) => (
        <div
          key={s.label}
          style={{ animationDelay: `${i * 80}ms` }}
          className="dash-rise group relative flex items-center gap-3 overflow-hidden rounded-xl border border-line bg-white p-3 transition duration-300 hover:-translate-y-1 hover:shadow-lg"
        >
          <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-blue text-white transition duration-300 group-hover:scale-110 group-hover:rotate-3">
            {s.icon}
          </span>
          <div className="min-w-0">
            <p className="text-xs text-slate-500">{s.label}</p>
            <p className="truncate text-xl font-semibold tabular-nums text-brand-blue">
              <StatValue value={s.value} />
            </p>
          </div>
          {/* Barre d'accent qui se déploie au survol */}
          <span className="absolute inset-x-0 bottom-0 h-1 origin-left scale-x-0 bg-brand-blue transition-transform duration-500 group-hover:scale-x-100" />
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

/** Initiales pour l'avatar (2 lettres max). */
function initials(name?: string | null): string {
  if (!name) return "?";
  const parts = name.trim().split(/\s+/).filter(Boolean);
  return ((parts[0]?.[0] ?? "") + (parts.length > 1 ? parts[parts.length - 1][0] : "")).toUpperCase() || "?";
}

function Avatar({ name, size = "md" }: { name?: string | null; size?: "md" | "lg" }) {
  const dims = size === "lg" ? "h-12 w-12 text-base" : "h-8 w-8 text-xs";
  return (
    <span
      aria-hidden="true"
      className={`flex ${dims} shrink-0 items-center justify-center rounded-full border border-line bg-slate-50 font-semibold text-brand-blue transition group-hover:bg-brand-blue group-hover:text-white`}
    >
      {initials(name)}
    </span>
  );
}

function SkeletonRows() {
  return (
    <>
      {Array.from({ length: 6 }, (_, i) => (
        <tr key={i} className="border-t border-line">
          {Array.from({ length: 6 }, (_, j) => (
            <td key={j} className="px-3 py-2.5">
              <span
                className="block h-4 animate-pulse rounded bg-slate-100"
                style={{ width: `${55 + ((i + j) % 4) * 12}%` }}
              />
            </td>
          ))}
        </tr>
      ))}
    </>
  );
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

  const stats: Stat[] = [];
  if (canUsers) stats.push({ label: "Total utilisateurs", value: totalUsers, icon: <IconUsers className="h-5 w-5" /> });
  if (canCentres) stats.push({ label: "Centres", value: centresCount, icon: <IconBuilding className="h-5 w-5" /> });
  if (canFonctions) stats.push({ label: "Fonctions", value: fonctionsCount, icon: <IconBriefcase className="h-5 w-5" /> });
  if (role === "admin") {
    stats.push({ label: "Mon centre", value: user?.centre?.nom ?? "—", icon: <IconBuilding className="h-5 w-5" /> });
  }

  return (
    <div className="space-y-5">
      <style>{ANIMATIONS}</style>

      {/* En-tête de bienvenue */}
      <header className="dash-rise relative overflow-hidden rounded-xl bg-brand-blue px-4 py-3 text-white sm:px-5">
        <span aria-hidden="true" className="pointer-events-none absolute -right-8 -top-12 h-36 w-36 rounded-full bg-white/10" />
        <span aria-hidden="true" className="pointer-events-none absolute -bottom-14 right-20 h-28 w-28 rounded-full bg-white/5" />
        <div className="relative flex items-center gap-3">
          <span
            aria-hidden="true"
            className="hidden h-10 w-10 shrink-0 items-center justify-center rounded-full bg-white text-sm font-semibold text-brand-blue sm:flex"
          >
            {initials(user?.nom)}
          </span>
          <div className="min-w-0">
            <h1 className="truncate text-base font-semibold tracking-tight sm:text-lg">Bonjour {user?.nom}</h1>
            <div className="mt-1 flex flex-wrap items-center gap-1.5 text-xs">
              {role && (
                <span className="rounded-full bg-white/15 px-2.5 py-0.5 font-medium">{ROLE_LABELS[role]}</span>
              )}
              {user?.centre && (
                <span className="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-2.5 py-0.5">
                  <IconBuilding className="h-3 w-3" />
                  {user.centre.nom}
                </span>
              )}
            </div>
          </div>
        </div>
      </header>

      {!canUsers ? (
        // Agent : alertes de son centre + résumé de son compte
        <>
          <AgentAlertes />
          <section className="dash-rise overflow-hidden rounded-2xl border border-line bg-white" style={{ animationDelay: "120ms" }}>
            <div className="flex items-center gap-3 border-b border-line bg-slate-50 px-5 py-4">
              <Avatar name={user?.nom} size="lg" />
              <div className="min-w-0">
                <h2 className="text-base font-semibold text-brand-blue">Mon compte</h2>
                <p className="truncate text-sm text-slate-500">{user?.email}</p>
              </div>
            </div>
            <dl className="grid gap-3 p-5 text-sm sm:grid-cols-2">
              {[
                ["E-mail", user?.email],
                ["IM", user?.im],
                ["Centre", user?.centre?.nom],
                ["Fonction", user?.fonction?.nom],
              ].map(([k, v]) => (
                <div
                  key={k}
                  className="rounded-xl border border-line p-3 transition duration-200 hover:-translate-y-0.5 hover:border-brand-blue hover:shadow-md"
                >
                  <dt className="text-slate-500">{k}</dt>
                  <dd className="mt-1 break-words text-base font-medium text-brand-blue">{v || "—"}</dd>
                </div>
              ))}
            </dl>
            <div className="px-5 pb-5">
              <Link
                to="/profile"
                className={`${primaryButtonClass} group inline-flex items-center gap-1`}
              >
                Modifier mon profil
                <IconChevron className="h-4 w-4 transition-transform group-hover:translate-x-1" />
              </Link>
            </div>
          </section>
        </>
      ) : (
        <>
          <StatsCard stats={stats} />

          <section
            className="dash-rise overflow-hidden rounded-2xl border border-line bg-white"
            style={{ animationDelay: "200ms" }}
          >
            {/* Barre de titre + recherche */}
            <div className="flex flex-col gap-3 border-b border-line p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
              <div className="flex items-center gap-3">
                <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-brand-blue text-white">
                  <IconUsers className="h-5 w-5" />
                </span>
                <div>
                  <h2 className="text-base font-semibold text-brand-blue">Tous les utilisateurs</h2>
                  <p className="text-sm text-slate-500">Comptes actifs de votre périmètre</p>
                </div>
              </div>
              <div className="flex items-center gap-3">
                <div className="relative flex-1 sm:w-72">
                  <IconSearch className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                  <input
                    value={searchInput}
                    onChange={(e) => setSearchInput(e.target.value)}
                    placeholder="Rechercher..."
                    aria-label="Rechercher un utilisateur"
                    className="w-full rounded-full border border-slate-300 bg-slate-50 py-2 pl-9 pr-9 text-sm placeholder-slate-400 transition focus:border-brand-blue focus:bg-white focus:outline-none focus:ring-2 focus:ring-brand-blue/30"
                  />
                  {searchInput && (
                    <button
                      type="button"
                      onClick={() => setSearchInput("")}
                      aria-label="Effacer la recherche"
                      className="absolute right-2.5 top-1/2 flex h-5 w-5 -translate-y-1/2 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-200 hover:text-slate-600"
                    >
                      ×
                    </button>
                  )}
                </div>
                <Link
                  to="/admin/utilisateurs"
                  className={`${primaryButtonClass} group inline-flex items-center gap-1 whitespace-nowrap text-sm`}
                >
                  Gérer
                  <IconChevron className="h-4 w-4 transition-transform group-hover:translate-x-1" />
                </Link>
              </div>
            </div>

            <div className="px-5 sm:px-6">
              <ErrorBox message={error} />
            </div>

            {/* Tableau */}
            <div className="max-h-[640px] overflow-auto">
              <table className="w-full min-w-[680px] text-left text-sm">
                <thead className="sticky top-0 z-10">
                  <tr className="border-b-2 border-brand-blue bg-slate-50 text-slate-600">
                    {["Nom", "Centre", "Téléphone", "E-mail", "Fonction", "Rôle"].map((h) => (
                      <th key={h} className="whitespace-nowrap px-3 py-2 text-xs font-semibold tracking-wide">
                        {h}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody className={loading && data ? "opacity-50 transition" : "transition"}>
                  {data?.data.map((u) => (
                    <tr
                      key={u.id}
                      className="group border-t border-line transition-colors even:bg-slate-50/60 hover:bg-slate-100"
                    >
                      <td className="relative px-3 py-2">
                        {/* Repère d'accent visible au survol */}
                        <span className="absolute inset-y-0 left-0 w-1 scale-y-0 bg-brand-blue transition-transform duration-200 group-hover:scale-y-100" />
                        <div className="flex items-center gap-3">
                          <Avatar name={u.nom} />
                          <span className="font-medium text-brand-blue">{u.nom}</span>
                        </div>
                      </td>
                      <td className="px-3 py-2 text-slate-700">{u.centre?.nom ?? "—"}</td>
                      <td className="whitespace-nowrap px-3 py-2 tabular-nums text-slate-700">{u.telephone ?? "—"}</td>
                      <td className="px-3 py-2 text-slate-700">{u.email}</td>
                      <td className="px-3 py-2 text-slate-700">{u.fonction?.nom ?? "—"}</td>
                      <td className="px-3 py-2">
                        <RoleBadge role={u.role} />
                      </td>
                    </tr>
                  ))}
                  {data && data.data.length === 0 && (
                    <tr>
                      <td colSpan={6} className="px-3 py-10 text-center">
                        <span className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400">
                          <IconSearch className="h-5 w-5" />
                        </span>
                        <p className="font-medium text-brand-blue">Aucun utilisateur trouvé</p>
                        <p className="mt-1 text-slate-500">Essayez un autre nom ou effacez la recherche.</p>
                      </td>
                    </tr>
                  )}
                  {!data && loading && <SkeletonRows />}
                </tbody>
              </table>
            </div>

            {/* Pagination */}
            {data && data.total > 0 && (
              <div className="flex justify-end border-t border-line bg-slate-50 px-5 py-3 sm:px-6">
                <div className="flex items-center gap-1.5">
                  <button
                    type="button"
                    onClick={() => setPage((p) => Math.max(1, p - 1))}
                    disabled={data.current_page <= 1}
                    aria-label="Page précédente"
                    className="flex h-8 w-8 items-center justify-center rounded-full border border-line bg-white text-slate-500 transition hover:border-brand-blue hover:text-brand-blue disabled:opacity-40 disabled:hover:border-line disabled:hover:text-slate-500"
                  >
                    ‹
                  </button>
                  {pageWindow(data.current_page, data.last_page).map((n) => (
                    <button
                      key={n}
                      type="button"
                      onClick={() => setPage(n)}
                      aria-current={n === data.current_page ? "page" : undefined}
                      className={`h-8 min-w-8 rounded-full px-2.5 text-sm tabular-nums transition ${
                        n === data.current_page
                          ? "bg-brand-blue font-semibold text-white shadow-sm"
                          : "border border-transparent text-slate-600 hover:border-line hover:bg-white"
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
                    className="flex h-8 w-8 items-center justify-center rounded-full border border-line bg-white text-slate-500 transition hover:border-brand-blue hover:text-brand-blue disabled:opacity-40 disabled:hover:border-line disabled:hover:text-slate-500"
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