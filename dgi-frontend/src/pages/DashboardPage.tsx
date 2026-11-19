import { useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import api, { getErrorMessage } from "../services/api";
import { useAuth } from "../context/AuthContext";
import {
  ROLE_LABELS,
  STATUT_LABELS,
  type ActionDossierType,
  type DashboardClotureur,
  type DashboardData,
  type DefaillanceSummary,
  type StatutAlerte,
} from "../types";
import { ErrorBox, primaryButtonClass } from "../components/ui";
import AgentAlertes from "../components/AgentAlertes";

/** Animation d'entrée unique (désactivée si l'utilisateur réduit les animations). */
const ANIMATIONS = `
@keyframes dash-rise { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }
.dash-rise { animation: dash-rise .5s cubic-bezier(.2,.7,.2,1) both; }
@media (prefers-reduced-motion: reduce) { .dash-rise { animation: none; } }
`;

const MOIS_COURTS = ["Jan", "Fév", "Mar", "Avr", "Mai", "Juin", "Juil", "Août", "Sep", "Oct", "Nov", "Déc"];
const MOIS_LONGS = [
  "Janvier",
  "Février",
  "Mars",
  "Avril",
  "Mai",
  "Juin",
  "Juillet",
  "Août",
  "Septembre",
  "Octobre",
  "Novembre",
  "Décembre",
];

/** Compte de 0 jusqu'à la valeur cible. */
function useCountUp(target: number | null, duration = 700): number | null {
  const [display, setDisplay] = useState<number | null>(target);

  useEffect(() => {
    if (target === null) {
      setDisplay(null);
      return;
    }

    const reduire = window.matchMedia?.("(prefers-reduced-motion: reduce)").matches;
    // Onglet en arrière-plan : les animations sont suspendues par le navigateur, on affiche
    // directement la valeur finale pour ne jamais laisser un compteur bloqué à zéro.
    if (reduire || document.hidden) {
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

    const terminerSiMasque = () => {
      if (document.hidden) {
        cancelAnimationFrame(frame);
        setDisplay(target);
      }
    };
    document.addEventListener("visibilitychange", terminerSiMasque);

    return () => {
      cancelAnimationFrame(frame);
      document.removeEventListener("visibilitychange", terminerSiMasque);
    };
  }, [target, duration]);

  return display;
}

function StatValue({ value }: { value: number | string | null }) {
  const animated = useCountUp(typeof value === "number" ? value : null);
  if (value === null) return <span className="inline-block h-6 w-14 animate-pulse rounded-md bg-slate-100 align-middle" />;
  if (typeof value === "string") return <>{value}</>;
  return <>{(animated ?? 0).toLocaleString("fr-FR")}</>;
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

/** Délai moyen en jours, une décimale (« 3,4 j ») ; « — » tant qu'aucun dossier n'alimente la moyenne. */
function libelleJours(valeur: number | null | undefined): string {
  if (valeur === null || valeur === undefined) return "—";
  return `${valeur.toLocaleString("fr-FR", { maximumFractionDigits: 1 })} j`;
}

/** Date courte (« 14 nov. 2026 »). */
function formatDate(iso: string | null): string {
  if (!iso) return "—";
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return "—";
  return date.toLocaleDateString("fr-FR", { day: "2-digit", month: "short", year: "numeric" });
}

/** Échéance relative (« il y a 2 h », « hier », « 14 nov. »). */
function formatRelative(iso: string | null): string {
  if (!iso) return "—";
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return "—";

  const diff = Date.now() - date.getTime();
  const jours = Math.floor(diff / 86_400_000);

  if (jours <= 0) {
    const heures = Math.floor(diff / 3_600_000);
    return heures <= 0 ? "à l'instant" : `il y a ${heures} h`;
  }
  if (jours === 1) return "hier";
  if (jours < 7) return `il y a ${jours} j`;
  return date.toLocaleDateString("fr-FR", { day: "2-digit", month: "short" });
}

function Shimmer({ className = "" }: { className?: string }) {
  return <span className={`block animate-pulse rounded bg-slate-100 ${className}`} />;
}

type KpiTone = "solid" | "azure" | "green" | "gold";

const KPI_TONES: Record<KpiTone, { card: string; label: string; valeur: string; note: string; barre: string }> = {
  solid: {
    card: "border-brand-blue bg-gradient-to-br from-brand-blue via-brand-blue to-brand-blue-dark text-white shadow-lg shadow-brand-blue/25",
    label: "text-white/75",
    valeur: "text-white",
    note: "text-white/70",
    barre: "bg-brand-gold",
  },
  azure: {
    card: "border-brand-azure/20 bg-brand-azure-soft",
    label: "text-slate-600",
    valeur: "text-brand-blue",
    note: "text-slate-500",
    barre: "bg-brand-azure",
  },
  green: {
    card: "border-brand-green/20 bg-brand-green-soft",
    label: "text-slate-600",
    valeur: "text-brand-green",
    note: "text-slate-500",
    barre: "bg-brand-green",
  },
  gold: {
    card: "border-brand-gold/25 bg-brand-gold-soft",
    label: "text-slate-600",
    valeur: "text-brand-gold-dark",
    note: "text-slate-500",
    barre: "bg-brand-gold",
  },
};

interface Kpi {
  label: string;
  value: number | string | null;
  note: string;
  tone: KpiTone;
  compactValue?: boolean;
}

function KpiCard({ kpi, delay }: { kpi: Kpi; delay: number }) {
  const tone = KPI_TONES[kpi.tone];

  return (
    <div
      style={{ animationDelay: `${delay}ms` }}
      className={`dash-rise relative overflow-hidden rounded-2xl border p-4 transition duration-300 hover:-translate-y-1 hover:shadow-lg ${tone.card}`}
    >
      <p className={`text-xs font-medium ${tone.label}`}>{kpi.label}</p>
      <p
        className={`mt-2 font-semibold ${
          kpi.compactValue ? "break-words text-sm leading-snug sm:text-base" : "truncate text-2xl tabular-nums sm:text-3xl"
        } ${tone.valeur}`}
      >
        <StatValue value={kpi.value} />
      </p>
      <p className={`mt-1 text-xs ${tone.note}`}>{kpi.note}</p>
      <span aria-hidden="true" className={`absolute inset-x-0 bottom-0 h-1 opacity-80 ${tone.barre}`} />
    </div>
  );
}

/** Graphique en barres des anomalies par mois, avec sélection de l'exercice. */
function AnomaliesChart({ data }: { data: DashboardData["anomalies_par_mois"] | undefined }) {
  const [annee, setAnnee] = useState<number | null>(null);

  useEffect(() => {
    if (!data || data.annees.length === 0) {
      setAnnee(null);
      return;
    }
    setAnnee((precedent) => (precedent !== null && data.annees.includes(precedent) ? precedent : data.annees[0]));
  }, [data]);

  const points = useMemo(() => {
    if (!data || annee === null) return [];
    return Array.from(
      { length: 12 },
      (_, i) => data.series.find((s) => s.annee === annee && s.mois === i + 1)?.total ?? 0
    );
  }, [data, annee]);

  const maximum = Math.max(1, ...points);
  const total = points.reduce((somme, n) => somme + n, 0);

  return (
    <section
      className="dash-rise overflow-hidden rounded-2xl border border-line bg-white"
      style={{ animationDelay: "160ms" }}
    >
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-3">
        <div>
          <h2 className="text-base font-semibold text-brand-blue">Anomalies par mois</h2>
          <p className="text-xs text-slate-500">
            Déclarations contrôlées{annee !== null ? ` — exercice ${annee}` : ""}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-1.5">
          {data?.annees.map((a) => (
            <button
              key={a}
              type="button"
              onClick={() => setAnnee(a)}
              aria-pressed={a === annee}
              className={`rounded-full px-3 py-1 text-xs font-semibold tabular-nums transition ${
                a === annee
                  ? "bg-brand-blue text-white shadow-sm shadow-brand-blue/25"
                  : "border border-line bg-white text-slate-600 hover:border-brand-azure hover:text-brand-azure"
              }`}
            >
              {a}
            </button>
          ))}
          <Link
            to="/defaillances"
            className="ml-1 text-sm font-medium text-brand-azure transition hover:text-brand-blue hover:underline"
          >
            Voir
          </Link>
        </div>
      </div>

      <div className="p-4">
        {data && data.annees.length > 0 ? (
          <>
            <div className="flex items-baseline gap-2">
              <p className="text-xl font-semibold tabular-nums text-brand-blue">{total.toLocaleString("fr-FR")}</p>
              <p className="text-xs text-slate-500">anomalies détectées en {annee}</p>
            </div>

            <div className="mt-4 flex h-28 items-end gap-1 sm:gap-1.5" role="img" aria-label={`Anomalies par mois pour l'exercice ${annee}`}>
              {points.map((n, i) => (
                <div key={i} className="group flex h-full flex-1 flex-col items-center justify-end gap-1">
                  <div className="relative flex w-full flex-1 items-end">
                    <div
                      style={{ height: `${Math.max(3, (n / maximum) * 100)}%` }}
                      title={`${MOIS_LONGS[i]} : ${n.toLocaleString("fr-FR")} anomalie${n > 1 ? "s" : ""}`}
                      className={`w-full rounded-t-md transition duration-300 ${
                        n === 0
                          ? "bg-slate-200"
                          : "bg-gradient-to-t from-brand-blue to-brand-azure group-hover:from-brand-gold group-hover:to-brand-gold/70"
                      }`}
                    />
                    <span className="pointer-events-none absolute inset-x-0 -top-6 hidden justify-center group-hover:flex">
                      <span className="rounded bg-brand-blue-dark px-1.5 py-0.5 text-[10px] font-semibold tabular-nums text-white">
                        {n}
                      </span>
                    </span>
                  </div>
                  <span className="text-[10px] font-medium uppercase tracking-wide text-slate-500">{MOIS_COURTS[i]}</span>
                </div>
              ))}
            </div>
          </>
        ) : (
          <div className="flex flex-col items-center justify-center py-8 text-center">
            <p className="font-medium text-brand-blue">Aucune anomalie à afficher</p>
            <p className="mt-1 text-sm text-slate-500">Lancez une analyse pour alimenter le graphique.</p>
          </div>
        )}
      </div>
    </section>
  );
}

const ACTION_META: Record<ActionDossierType, { label: string; point: string }> = {
  prise: { label: "Prise en charge", point: "bg-brand-azure" },
  reprise: { label: "Reprise", point: "bg-brand-gold" },
  affectation: { label: "Affectation", point: "bg-brand-teal" },
  liberation: { label: "Libération", point: "bg-slate-400" },
  cloture: { label: "Clôture", point: "bg-brand-green" },
  reouverture: { label: "Réouverture", point: "bg-red-500" },
};

/** Utilisateurs ayant clôturé des dossiers le plus récemment. */
function Clotureurs({ items, canManageUsers }: { items: DashboardClotureur[]; canManageUsers: boolean }) {
  return (
    <section
      className="dash-rise overflow-hidden rounded-2xl border border-line bg-white"
      style={{ animationDelay: "220ms" }}
    >
      <div className="flex items-center justify-between gap-3 border-b border-line px-4 py-3">
        <div>
          <h2 className="text-base font-semibold text-brand-blue">Utilisateurs récents</h2>
          <p className="text-xs text-slate-500">Dossiers clôturés récemment</p>
        </div>
        {canManageUsers && (
          <Link
            to="/admin/utilisateurs"
            className="text-sm font-medium text-brand-azure transition hover:text-brand-blue hover:underline"
          >
            Équipe
          </Link>
        )}
      </div>

      <ul className="divide-y divide-line">
        {items.map((u) => (
          <li key={u.id} className="group flex items-center gap-3 px-4 py-3 transition hover:bg-slate-50">
            <Avatar name={u.nom} />
            <div className="min-w-0 flex-1">
              <p className="truncate text-sm font-medium text-brand-blue">{u.nom}</p>
              <p className="truncate text-xs text-slate-500">
                {u.centre ?? "Sans centre"}
                {u.role ? ` · ${ROLE_LABELS[u.role]}` : ""}
              </p>
            </div>
            <div className="text-right">
              <p className="text-sm font-semibold tabular-nums text-brand-blue">{u.dossiers}</p>
              <p className="text-[11px] text-slate-500">dossier{u.dossiers > 1 ? "s" : ""}</p>
            </div>
            <span className="hidden w-20 text-right text-[11px] text-slate-400 sm:block">
              {formatRelative(u.derniere_cloture)}
            </span>
          </li>
        ))}
        {items.length === 0 && (
          <li className="px-4 py-6 text-center text-sm text-slate-500">Aucune clôture enregistrée.</li>
        )}
      </ul>
    </section>
  );
}

/** Journal des opérations faites par l'utilisateur connecté. */
function MonHistorique({ actions }: { actions: DashboardData["historique"] }) {
  return (
    <section
      className="dash-rise overflow-hidden rounded-2xl border border-line bg-white"
      style={{ animationDelay: "280ms" }}
    >
      <div className="flex items-center justify-between gap-3 border-b border-line px-4 py-3">
        <div>
          <h2 className="text-base font-semibold text-brand-blue">Mon historique</h2>
          <p className="text-xs text-slate-500">Vos dernières opérations</p>
        </div>
      </div>

      <ul className="divide-y divide-line">
        {actions.map((h) => {
          const meta = ACTION_META[h.action] ?? ACTION_META.prise;
          return (
            <li key={h.id} className="flex gap-3 px-4 py-3">
              <span aria-hidden="true" className={`mt-1.5 h-2 w-2 shrink-0 rounded-full ${meta.point}`} />
              <div className="min-w-0 flex-1">
                <p className="text-sm font-medium text-brand-blue">
                  {meta.label}
                  <span className="font-normal text-slate-600"> — {h.contribuable.nom ?? "Dossier"}</span>
                </p>
                <p className="truncate text-xs text-slate-500">
                  {h.contribuable.nif ?? "—"}
                  {h.detail ? ` · ${h.detail}` : ""} · {formatRelative(h.le)}
                </p>
                <p className="text-[11px] text-slate-400">
                  {STATUT_LABELS[h.statut_avant as StatutAlerte] ?? h.statut_avant} →{" "}
                  {STATUT_LABELS[h.statut_apres as StatutAlerte] ?? h.statut_apres}
                </p>
              </div>
            </li>
          );
        })}
        {actions.length === 0 && (
          <li className="px-4 py-6 text-center text-sm text-slate-500">Aucune opération pour le moment.</li>
        )}
      </ul>
    </section>
  );
}

/** Derniers dossiers clôturés (journal des dossiers). */
function DerniersDossiers({ items }: { items: DashboardData["derniers_dossiers"] }) {
  return (
    <section
      className="dash-rise overflow-hidden rounded-2xl border border-line bg-white"
      style={{ animationDelay: "200ms" }}
    >
      <div className="flex items-center justify-between gap-3 border-b border-line px-4 py-3">
        <div>
          <h2 className="text-base font-semibold text-brand-blue">Derniers dossiers clôturés</h2>
          <p className="text-xs text-slate-500">Journal des clôtures</p>
        </div>
        <Link
          to="/traitements?vue=traites"
          className="text-sm font-medium text-brand-azure transition hover:text-brand-blue hover:underline"
        >
          Tout voir
        </Link>
      </div>

      <div className="overflow-x-auto">
        <table className="w-full min-w-[560px] text-left text-sm">
          <thead>
            <tr className="bg-slate-50 text-[11px] font-semibold uppercase tracking-wider text-slate-500">
              {["Dossier", "Centre", "Clôturé par", "Date"].map((h) => (
                <th key={h} className="whitespace-nowrap px-4 py-2.5">
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {items.map((d) => (
              <tr key={d.id} className="group border-t border-line transition-colors even:bg-slate-50/60 hover:bg-slate-100">
                <td className="px-4 py-3">
                  <div className="flex items-center gap-2.5">
                    <Avatar name={d.contribuable.nom} />
                    <div className="min-w-0">
                      {d.contribuable.id !== null ? (
                        <Link
                          to={`/contribuables/${d.contribuable.id}`}
                          className="block truncate font-medium text-brand-blue hover:underline"
                        >
                          {d.contribuable.nom ?? "—"}
                        </Link>
                      ) : (
                        <span className="block truncate font-medium text-slate-700">{d.contribuable.nom ?? "—"}</span>
                      )}
                      <p className="text-xs text-slate-500">{d.contribuable.nif ?? "—"}</p>
                    </div>
                  </div>
                </td>
                <td className="px-4 py-3 text-slate-700">{d.contribuable.centre ?? "—"}</td>
                <td className="px-4 py-3 font-medium text-slate-700">{d.auteur}</td>
                <td className="whitespace-nowrap px-4 py-3 text-slate-500">{formatDate(d.le)}</td>
              </tr>
            ))}
            {items.length === 0 && (
              <tr>
                <td colSpan={4} className="px-4 py-6 text-center">
                  <p className="font-medium text-brand-blue">Aucun dossier clôturé</p>
                  <p className="mt-1 text-sm text-slate-500">Les dossiers traités apparaîtront ici.</p>
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </section>
  );
}

/** Indicateurs de décision : délais réels des dossiers du périmètre de l'utilisateur. */
function PilotageDossiers({ summary }: { summary: DefaillanceSummary | null }) {
  const enRetard = summary?.dossiers_en_retard ?? 0;

  const lignes = [
    {
      label: "Délai moyen de traitement",
      valeur: libelleJours(summary?.delai_moyen_jours),
      note: "de l'ouverture à la clôture",
      point: "bg-brand-azure",
      alerte: false,
    },
    {
      label: "Attente avant prise",
      valeur: libelleJours(summary?.attente_moyenne_jours),
      note: "avant qu'un agent s'en saisisse",
      point: "bg-brand-gold",
      alerte: false,
    },
    {
      label: "Dossiers en retard",
      valeur: summary ? enRetard.toLocaleString("fr-FR") : "—",
      note: "ouverts depuis plus de 30 j",
      point: "bg-red-500",
      alerte: enRetard > 0,
    },
  ];

  return (
    <section
      className="dash-rise overflow-hidden rounded-2xl border border-line bg-white"
      style={{ animationDelay: "320ms" }}
    >
      <div className="flex items-center justify-between gap-3 border-b border-line px-4 py-3">
        <div>
          <h2 className="text-base font-semibold text-brand-blue">Pilotage des dossiers</h2>
          <p className="text-xs text-slate-500">Délais observés dans votre périmètre</p>
        </div>
        <Link
          to="/traitements"
          className="text-sm font-medium text-brand-azure transition hover:text-brand-blue hover:underline"
        >
          Dossiers
        </Link>
      </div>

      <ul className="divide-y divide-line">
        {lignes.map((l) => (
          <li key={l.label} className="flex items-center gap-3 px-4 py-3">
            <span aria-hidden="true" className={`h-2 w-2 shrink-0 rounded-full ${l.point}`} />
            <div className="min-w-0 flex-1">
              <p className="text-sm font-medium text-slate-700">{l.label}</p>
              <p className="text-xs text-slate-500">{l.note}</p>
            </div>
            <span className={`text-lg font-semibold tabular-nums ${l.alerte ? "text-red-600" : "text-brand-blue"}`}>
              {l.valeur}
            </span>
          </li>
        ))}
      </ul>
    </section>
  );
}

export default function DashboardPage() {
  const { user } = useAuth();
  const role = user?.role ?? null;

  const canUsers = role === "superadmin" || role === "central" || role === "admin";
  const isAgent = role === "agent";

  const [dash, setDash] = useState<DashboardData | null>(null);
  const [summary, setSummary] = useState<DefaillanceSummary | null>(null);
  const [error, setError] = useState<string>("");
  const [tick, setTick] = useState<number>(0);

  // Rafraîchissement automatique : l'analyse serveur tourne toutes les 5 minutes.
  useEffect(() => {
    const timer = setInterval(() => setTick((n) => n + 1), 60_000);
    return () => clearInterval(timer);
  }, []);

  useEffect(() => {
    let cancelled = false;

    Promise.all([
      api.get<DashboardData>("/dashboard"),
      api.get<DefaillanceSummary>("/defaillances/summary").catch(() => null),
    ])
      .then(([d, s]) => {
        if (cancelled) return;
        setDash(d.data);
        if (s) setSummary(s.data);
        setError("");
      })
      .catch((err) => {
        if (!cancelled) setError(getErrorMessage(err, "Impossible de charger le tableau de bord."));
      });

    return () => {
      cancelled = true;
    };
  }, [tick]);

  const totaux = dash?.totaux;
  const kpis: Kpi[] = [];

  if (totaux) {
    kpis.push({
      label: "Anomalies à traiter",
      value: totaux.anomalies_a_traiter,
      note: `${totaux.dossiers_a_traiter.toLocaleString("fr-FR")} dossier${totaux.dossiers_a_traiter > 1 ? "s" : ""} concerné${totaux.dossiers_a_traiter > 1 ? "s" : ""}`,
      tone: "solid",
    });

    if (role === "admin") {
      kpis.push({
        label: "Mon centre",
        value: user?.centre?.nom ?? "—",
        note: "votre périmètre de traitement",
        tone: "azure",
        compactValue: true,
      });
    } else if (totaux.centres !== null) {
      kpis.push({
        label: "Centres",
        value: totaux.centres,
        note: "périmètre couvert",
        tone: "azure",
      });
    }

    if (totaux.utilisateurs !== null) {
      kpis.push({
        label: role === "admin" ? "Agents du centre" : "Utilisateurs",
        value: totaux.utilisateurs,
        note:
          role === "superadmin" && totaux.fonctions !== null
            ? `${totaux.fonctions.toLocaleString("fr-FR")} fonctions`
            : role === "admin"
              ? "comptes actifs"
              : "comptes enregistrés",
        tone: "green",
      });
    }

    kpis.push({
      label: "Anomalies traitées",
      value: totaux.anomalies_traitees,
      note: `${totaux.anomalies_en_traitement.toLocaleString("fr-FR")} en traitement`,
      tone: "gold",
    });
  }

  const aujourdhui = useMemo(
    () =>
      new Date().toLocaleDateString("fr-FR", {
        weekday: "long",
        day: "numeric",
        month: "long",
        year: "numeric",
      }),
    []
  );

  return (
    <div className="space-y-5">
      <style>{ANIMATIONS}</style>

      {/* En-tête de bienvenue */}
      <header className="dash-rise relative overflow-hidden rounded-2xl bg-gradient-to-br from-brand-blue via-brand-blue to-brand-blue-dark px-4 py-4 text-white shadow-md shadow-brand-blue/20 sm:px-5">
        <span aria-hidden="true" className="pointer-events-none absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-brand-gold via-brand-gold/50 to-transparent" />
        <span aria-hidden="true" className="pointer-events-none absolute -right-8 -top-12 h-36 w-36 rounded-full bg-white/10" />
        <span aria-hidden="true" className="pointer-events-none absolute -bottom-14 right-20 h-28 w-28 rounded-full bg-white/5" />
        <div className="relative flex flex-wrap items-center justify-between gap-3">
          <div className="flex items-center gap-3">
            <span
              aria-hidden="true"
              className="hidden h-10 w-10 shrink-0 items-center justify-center rounded-full bg-white text-sm font-semibold text-brand-blue shadow-sm sm:flex"
            >
              {initials(user?.nom)}
            </span>
            <div className="min-w-0">
              <h1 className="truncate text-base font-semibold tracking-tight sm:text-lg">Bonjour {user?.nom}</h1>
              <div className="mt-1 flex flex-wrap items-center gap-1.5 text-xs">
                {role && <span className="rounded-full bg-white/15 px-2.5 py-0.5 font-medium">{ROLE_LABELS[role]}</span>}
                {user?.centre && (
                  <span className="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-2.5 py-0.5">
                    {user.centre.nom}
                  </span>
                )}
              </div>
            </div>
          </div>
          <span className="hidden rounded-full bg-white/10 px-3 py-1 text-xs capitalize sm:inline-flex">{aujourdhui}</span>
        </div>
      </header>

      <ErrorBox message={error} />

    {/* {(role === "central" || role === "superadmin") && (
        <Link
          to="/statistiques/centres"
          className="dash-rise flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-line bg-white px-4 py-3 transition hover:border-brand-azure"
        >
          <div>
            <p className="text-sm font-semibold text-brand-blue">Comparatif des centres</p>
            <p className="text-xs text-slate-500">Taux de clôture, délais et retards, centre par centre.</p>
          </div>
          <span className="text-sm font-medium text-brand-azure">Ouvrir</span>
        </Link>
      )}*/}

      {!canUsers ? (
        // Agent : ses dossiers assignés + le suivi personnel du tableau de bord.
        <AgentAlertes />
      ) : (
        <>
          {dash ? (
            <div
              className={`grid gap-4 sm:grid-cols-2 ${
                kpis.length >= 4 ? "xl:grid-cols-4" : kpis.length === 3 ? "xl:grid-cols-3" : ""
              }`}
            >
              {kpis.map((kpi, i) => (
                <KpiCard key={kpi.label} kpi={kpi} delay={i * 70} />
              ))}
            </div>
          ) : (
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
              {Array.from({ length: 4 }, (_, i) => (
                <div key={i} className="rounded-2xl border border-line bg-white p-4">
                  <Shimmer className="h-3 w-24" />
                  <Shimmer className="mt-3 h-8 w-20" />
                  <Shimmer className="mt-2 h-3 w-32" />
                </div>
              ))}
            </div>
          )}
        </>
      )}

      {dash ? (
        <div className="grid gap-5 xl:grid-cols-3">
          <div className="space-y-5 xl:col-span-2">
            <AnomaliesChart data={dash.anomalies_par_mois} />
            <DerniersDossiers items={dash.derniers_dossiers} />
          </div>

          <div className="space-y-5">
            {!isAgent && <Clotureurs items={dash.clotureurs} canManageUsers={canUsers} />}
            <MonHistorique actions={dash.historique} />
            <PilotageDossiers summary={summary} />
          </div>
        </div>
      ) : (
        !error && (
          <div className="grid gap-5 xl:grid-cols-3">
            <div className="space-y-5 xl:col-span-2">
              <div className="rounded-2xl border border-line bg-white p-5">
                <Shimmer className="h-4 w-40" />
                <Shimmer className="mt-6 h-28 w-full" />
              </div>
              <div className="rounded-2xl border border-line bg-white p-5">
                <Shimmer className="h-4 w-48" />
                <div className="mt-4 space-y-3">
                  {Array.from({ length: 5 }, (_, i) => (
                    <Shimmer key={i} className="h-8 w-full" />
                  ))}
                </div>
              </div>
            </div>
            <div className="space-y-5">
              {Array.from({ length: 2 }, (_, i) => (
                <div key={i} className="rounded-2xl border border-line bg-white p-5">
                  <Shimmer className="h-4 w-36" />
                  <div className="mt-4 space-y-3">
                    {Array.from({ length: 4 }, (_, j) => (
                      <Shimmer key={j} className="h-8 w-full" />
                    ))}
                  </div>
                </div>
              ))}
            </div>
          </div>
        )
      )}

      {!canUsers && (
        // Agent : résumé de son compte, sous les blocs de suivi.
        <section className="dash-rise overflow-hidden rounded-2xl border border-line bg-white" style={{ animationDelay: "360ms" }}>
          <div className="flex items-center gap-3 border-b border-line bg-slate-50 px-4 py-3">
            <Avatar name={user?.nom} size="lg" />
            <div className="min-w-0">
              <h2 className="text-base font-semibold text-brand-blue">Mon compte</h2>
              <p className="truncate text-sm text-slate-500">{user?.email}</p>
            </div>
          </div>
          <dl className="grid gap-3 p-4 text-sm sm:grid-cols-2">
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
                <dd
                  className={`mt-1 break-words font-medium text-brand-blue ${
                    k === "Centre" ? "text-sm leading-snug sm:text-base" : "text-base"
                  }`}
                >
                  {v || "—"}
                </dd>
              </div>
            ))}
          </dl>
          <div className="px-4 pb-4">
            <Link to="/profile" className={primaryButtonClass}>
              Modifier mon profil
            </Link>
          </div>
        </section>
      )}
    </div>
  );
}
