import { useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import api, { getErrorMessage } from "../services/api";
import { ErrorBox, inputClass, secondaryButtonClass } from "../components/ui";
import type { ComparatifCentre, ComparatifCentresData } from "../types";

type ChampTri =
  | "nom"
  | "dossiers_total"
  | "dossiers_ouverts"
  | "taux_cloture"
  | "delai_moyen_jours"
  | "attente_moyenne_jours"
  | "dossiers_en_retard"
  | "charge_par_agent"
  | "densite"
  | "taux_reouverture";

interface Tri {
  champ: ChampTri;
  dir: "asc" | "desc";
}

const nb = (valeur: number): string => valeur.toLocaleString("fr-FR");

const pct = (valeur: number | null): string =>
  valeur === null ? "—" : `${valeur.toLocaleString("fr-FR", { maximumFractionDigits: 1 })} %`;

const jours = (valeur: number | null): string =>
  valeur === null ? "—" : `${valeur.toLocaleString("fr-FR", { maximumFractionDigits: 1 })} j`;

/** Couleur du taux de clôture : repère visuel, jamais un classement punitif. */
function tonaliteTaux(valeur: number | null): string {
  if (valeur === null) return "bg-slate-200";
  if (valeur >= 70) return "bg-brand-green";
  if (valeur >= 40) return "bg-brand-gold";
  return "bg-red-500";
}

function TauxBar({ valeur }: { valeur: number | null }) {
  if (valeur === null) {
    return <span className="text-sm text-slate-400">—</span>;
  }

  return (
    <div className="flex items-center gap-2">
      <div className="h-1.5 w-16 overflow-hidden rounded-full bg-slate-100">
        <div className={`h-full rounded-full ${tonaliteTaux(valeur)}`} style={{ width: `${Math.max(3, Math.min(100, valeur))}%` }} />
      </div>
      <span className="text-sm font-medium tabular-nums text-slate-700">{pct(valeur)}</span>
    </div>
  );
}

interface KpiProps {
  label: string;
  valeur: string;
  note: string;
  tone: "solid" | "azure" | "green" | "gold";
}

const KPI_TONES: Record<KpiProps["tone"], { card: string; label: string; valeur: string; barre: string }> = {
  solid: {
    card: "border-brand-blue bg-gradient-to-br from-brand-blue via-brand-blue to-brand-blue-dark text-white shadow-md shadow-brand-blue/20",
    label: "text-white/75",
    valeur: "text-white",
    barre: "bg-brand-gold",
  },
  azure: {
    card: "border-brand-azure/20 bg-brand-azure-soft",
    label: "text-slate-600",
    valeur: "text-brand-blue",
    barre: "bg-brand-azure",
  },
  green: {
    card: "border-brand-green/20 bg-brand-green-soft",
    label: "text-slate-600",
    valeur: "text-brand-green",
    barre: "bg-brand-green",
  },
  gold: {
    card: "border-brand-gold/25 bg-brand-gold-soft",
    label: "text-slate-600",
    valeur: "text-brand-gold-dark",
    barre: "bg-brand-gold",
  },
};

function Kpi({ label, valeur, note, tone }: KpiProps) {
  const styles = KPI_TONES[tone];

  return (
    <div className={`relative overflow-hidden rounded-2xl border p-4 ${styles.card}`}>
      <p className={`text-xs font-medium ${styles.label}`}>{label}</p>
      <p className={`mt-2 truncate text-2xl font-semibold tabular-nums ${styles.valeur}`}>{valeur}</p>
      <p className={`mt-1 text-xs ${styles.label}`}>{note}</p>
      <span aria-hidden="true" className={`absolute inset-x-0 bottom-0 h-1 opacity-80 ${styles.barre}`} />
    </div>
  );
}

interface EnteteProps {
  children: string;
  champ: ChampTri;
  tri: Tri;
  onTrier: (champ: ChampTri) => void;
  className?: string;
}

function Entete({ children, champ, tri, onTrier, className = "" }: EnteteProps) {
  const actif = tri.champ === champ;

  return (
    <th
      scope="col"
      aria-sort={actif ? (tri.dir === "asc" ? "ascending" : "descending") : "none"}
      className={`whitespace-nowrap px-4 py-2.5 ${className}`}
    >
      <button
        type="button"
        onClick={() => onTrier(champ)}
        className={`inline-flex items-center gap-1 transition hover:text-brand-blue ${actif ? "text-brand-blue" : ""}`}
      >
        {children}
        <span aria-hidden="true" className={actif ? "text-brand-azure" : "text-slate-300"}>
          {actif ? (tri.dir === "asc" ? "↑" : "↓") : "↕"}
        </span>
      </button>
    </th>
  );
}

function valeurTri(centre: ComparatifCentre, champ: ChampTri): number | string | null {
  return champ === "nom" ? centre.nom : centre[champ];
}

export default function ComparatifCentresPage() {
  const [data, setData] = useState<ComparatifCentresData | null>(null);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string>("");

  const [annee, setAnnee] = useState<string>("");
  const [inclureCentraux, setInclureCentraux] = useState<boolean>(false);
  const [afficherTous, setAfficherTous] = useState<boolean>(false);
  const [recherche, setRecherche] = useState<string>("");
  const [tri, setTri] = useState<Tri>({ champ: "taux_cloture", dir: "asc" });
  const [reloadKey, setReloadKey] = useState<number>(0);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);

    api
      .get<ComparatifCentresData>("/statistiques/centres", {
        params: {
          annee_fiscale: annee || undefined,
          inclure_centraux: inclureCentraux ? 1 : undefined,
        },
      })
      .then((res) => {
        if (cancelled) return;
        setData(res.data);
        setError("");
      })
      .catch((err) => {
        if (!cancelled) setError(getErrorMessage(err, "Impossible de charger le comparatif des centres."));
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [annee, inclureCentraux, reloadKey]);

  const centres = useMemo(() => {
    const terme = recherche.trim().toLowerCase();

    const filtres = (data?.centres ?? []).filter((centre) => {
      if (!afficherTous && centre.dossiers_total === 0) return false;

      if (terme === "") return true;

      return (
        centre.nom.toLowerCase().includes(terme) ||
        (centre.abreviation ?? "").toLowerCase().includes(terme) ||
        (centre.code ?? "").toLowerCase().includes(terme)
      );
    });

    return [...filtres].sort((a, b) => {
      const va = valeurTri(a, tri.champ);
      const vb = valeurTri(b, tri.champ);

      if (va === null && vb === null) return 0;
      if (va === null) return 1; // valeurs inconnues en fin de liste
      if (vb === null) return -1;

      const sens = tri.dir === "asc" ? 1 : -1;

      if (typeof va === "string" || typeof vb === "string") {
        return String(va).localeCompare(String(vb), "fr") * sens;
      }

      return (va - vb) * sens;
    });
  }, [data, recherche, afficherTous, tri]);

  function trier(champ: ChampTri) {
    setTri((courant) =>
      courant.champ === champ
        ? { champ, dir: courant.dir === "asc" ? "desc" : "asc" }
        : { champ, dir: champ === "nom" ? "asc" : "desc" },
    );
  }

  const totaux = data?.totaux;

  return (
    <div className="space-y-5">
      <div>
        <h1 className="text-xl font-semibold tracking-tight text-brand-blue">Comparatif des centres</h1>
      </div>

      <ErrorBox message={error} />

      <section className="rounded-2xl border border-line bg-white p-4">
        <div className="flex flex-wrap items-end gap-3">
          <label className="text-sm">
            <span className="mb-1 block text-[11px] font-semibold uppercase tracking-wider text-slate-500">Exercice</span>
            <select value={annee} onChange={(e) => setAnnee(e.target.value)} className={`${inputClass} min-w-[11rem]`}>
              <option value="">Tous les exercices</option>
              {(data?.annees ?? []).map((a) => (
                <option key={a} value={a}>
                  {a}
                </option>
              ))}
            </select>
          </label>

          <label className="inline-flex items-center gap-2 pb-2 text-sm text-slate-700">
            <input
              type="checkbox"
              checked={inclureCentraux}
              onChange={(e) => setInclureCentraux(e.target.checked)}
              className="h-4 w-4 rounded border-line text-brand-blue focus:ring-brand-azure"
            />
            Inclure les services centraux
          </label>

          <label className="inline-flex items-center gap-2 pb-2 text-sm text-slate-700">
            <input
              type="checkbox"
              checked={afficherTous}
              onChange={(e) => setAfficherTous(e.target.checked)}
              className="h-4 w-4 rounded border-line text-brand-blue focus:ring-brand-azure"
            />
            Afficher les centres sans anomalie
          </label>

          <div className="ml-auto flex flex-wrap items-end gap-2">
            <label className="text-sm">
              <span className="mb-1 block text-[11px] font-semibold uppercase tracking-wider text-slate-500">Rechercher</span>
              <input
                type="search"
                value={recherche}
                onChange={(e) => setRecherche(e.target.value)}
                placeholder="Nom, sigle ou code"
                className={`${inputClass} w-56`}
              />
            </label>
            <button type="button" className={secondaryButtonClass} onClick={() => setReloadKey((k) => k + 1)}>
              Actualiser
            </button>
          </div>
        </div>
      </section>

      {loading && !data ? (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          {Array.from({ length: 4 }, (_, i) => (
            <div key={i} className="h-24 animate-pulse rounded-2xl border border-line bg-white" />
          ))}
        </div>
      ) : (
        totaux && (
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <Kpi
              tone="solid"
              label="Centres actifs"
              valeur={nb(totaux.centres_actifs)}
              note={`${nb(totaux.centres)} centres · ${nb(totaux.centres_sans_activite)} sans anomalie`}
            />
            <Kpi
              tone="azure"
              label="Dossiers ouverts"
              valeur={nb(totaux.dossiers_ouverts)}
              note={`${nb(totaux.anomalies_a_traiter)} anomalies libres`}
            />
            <Kpi
              tone="gold"
              label="Dossiers en retard"
              valeur={nb(totaux.dossiers_en_retard)}
              note="ouverts depuis plus de 30 jours"
            />
            <Kpi
              tone="green"
              label="Taux médian de clôture"
              valeur={pct(data?.medianes.taux_cloture ?? null)}
              note={`${nb(totaux.dossiers_clotures)} dossiers clôturés`}
            />
          </div>
        )
      )}

      <section className="overflow-hidden rounded-2xl border border-line bg-white">
        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-3">
          <div>
            <h2 className="text-base font-semibold text-brand-blue">Détail par centre</h2>
            <p className="text-xs text-slate-500">
              {centres.length} centre{centres.length > 1 ? "s" : ""} affiché{centres.length > 1 ? "s" : ""}
              {data?.filtres.annee_fiscale ? ` — exercice ${data.filtres.annee_fiscale}` : ""}
            </p>
          </div>
          <p className="text-xs text-slate-500">
            Cliquez sur un en-tête pour trier. « — » = donnée absente.
          </p>
        </div>

        <div className="overflow-x-auto">
          <table className="w-full min-w-[1080px] text-left text-sm">
            <caption className="sr-only">Comparatif des centres : volumes, taux de clôture, délais et retards</caption>
            <thead>
              <tr className="bg-slate-50 text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                <Entete champ="nom" tri={tri} onTrier={trier} className="text-left">
                  Centre
                </Entete>
                <Entete champ="dossiers_ouverts" tri={tri} onTrier={trier} className="text-right">
                  Ouverts
                </Entete>
                <Entete champ="dossiers_total" tri={tri} onTrier={trier} className="text-right">
                  Dossiers
                </Entete>
                <Entete champ="taux_cloture" tri={tri} onTrier={trier}>
                  Taux de clôture
                </Entete>
                <Entete champ="delai_moyen_jours" tri={tri} onTrier={trier} className="text-right">
                  Délai moyen
                </Entete>
                <Entete champ="attente_moyenne_jours" tri={tri} onTrier={trier} className="text-right">
                  Attente
                </Entete>
                <Entete champ="dossiers_en_retard" tri={tri} onTrier={trier} className="text-right">
                  En retard
                </Entete>
                <Entete champ="charge_par_agent" tri={tri} onTrier={trier} className="text-right">
                  Charge/agent
                </Entete>
                <Entete champ="densite" tri={tri} onTrier={trier} className="hidden text-right xl:table-cell">
                  Densité
                </Entete>
                <Entete champ="taux_reouverture" tri={tri} onTrier={trier} className="hidden text-right xl:table-cell">
                  Réouverture
                </Entete>
                <th scope="col" className="px-4 py-2.5 text-right">
                  Dossiers
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-line">
              {centres.map((centre) => (
                <tr key={centre.id ?? "sans"} className="transition hover:bg-slate-50">
                  <td className="px-4 py-3">
                    <p className="font-medium text-brand-blue">
                      {centre.nom}
                      {centre.type_centre === "central" && (
                        <span className="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide text-slate-500">
                          service central
                        </span>
                      )}
                    </p>
                    <p className="text-xs text-slate-500">
                      {centre.abreviation ?? centre.code ?? "—"} · {nb(centre.contribuables)} contribuable
                      {centre.contribuables > 1 ? "s" : ""}
                    </p>
                  </td>
                  <td className="px-4 py-3 text-right tabular-nums text-slate-700">{nb(centre.dossiers_ouverts)}</td>
                  <td className="px-4 py-3 text-right tabular-nums text-slate-500">{nb(centre.dossiers_total)}</td>
                  <td className="px-4 py-3">
                    <TauxBar valeur={centre.taux_cloture} />
                  </td>
                  <td className="px-4 py-3 text-right tabular-nums text-slate-700">{jours(centre.delai_moyen_jours)}</td>
                  <td className="px-4 py-3 text-right tabular-nums text-slate-700">{jours(centre.attente_moyenne_jours)}</td>
                  <td
                    className={`px-4 py-3 text-right tabular-nums ${
                      centre.dossiers_en_retard > 0 ? "font-semibold text-red-600" : "text-slate-700"
                    }`}
                  >
                    {nb(centre.dossiers_en_retard)}
                  </td>
                  <td className="px-4 py-3 text-right tabular-nums text-slate-700">
                    {centre.charge_par_agent === null ? "—" : centre.charge_par_agent.toLocaleString("fr-FR", { maximumFractionDigits: 1 })}
                  </td>
                  <td className="hidden px-4 py-3 text-right tabular-nums text-slate-700 xl:table-cell">
                    {centre.densite === null ? "—" : `${centre.densite.toLocaleString("fr-FR", { maximumFractionDigits: 1 })} /100`}
                  </td>
                  <td className="hidden px-4 py-3 text-right tabular-nums text-slate-700 xl:table-cell">
                    {pct(centre.taux_reouverture)}
                  </td>
                  <td className="px-4 py-3 text-right">
                    {centre.id !== null ? (
                      <Link
                        to={`/defaillances?centre_id=${centre.id}`}
                        className="text-sm font-medium text-brand-azure transition hover:text-brand-blue hover:underline"
                      >
                        Voir
                      </Link>
                    ) : (
                      <span className="text-xs text-slate-400">—</span>
                    )}
                  </td>
                </tr>
              ))}

              {!loading && centres.length === 0 && (
                <tr>
                  <td colSpan={11} className="px-4 py-8 text-center text-sm text-slate-500">
                    Aucun centre ne correspond aux filtres.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </section>

      <p className="text-xs text-slate-500">
        Les services centraux sont exclus par défaut. Les contribuables sans centre sont regroupés dans une ligne « Sans centre ».
        Le taux de réouverture est un indicateur de qualité : une clôture hâtive se voit immédiatement.
      </p>
    </div>
  );
}
