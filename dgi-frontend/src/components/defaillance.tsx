import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import api, { getErrorMessage } from "../services/api";
import {
  ETAT_LABELS,
  NIVEAU_LABELS,
  REGLE_COURTS,
  REGLE_LABELS,
  SCORE_MAX_R,
  STATUT_LABELS,
  type CelluleDeclaration,
  type ContribuableFiche,
  type Defaillance,
  type LigneHistorique,
  type NiveauRisque,
  type Regle,
  type StatutAlerte,
} from "../types";
import { ErrorBox } from "./ui";

// ---------------------------------------------------------------------
//  Utilitaires partagés (page Anomalies, dashboard agent, fiche contribuable)
// ---------------------------------------------------------------------

const MOIS = [
  "janvier",
  "février",
  "mars",
  "avril",
  "mai",
  "juin",
  "juillet",
  "août",
  "septembre",
  "octobre",
  "novembre",
  "décembre",
];

const MOIS_COURTS = ["janv.", "févr.", "mars", "avr.", "mai", "juin", "juil.", "août", "sept.", "oct.", "nov.", "déc."];

/** (2026, 8) → « août 2026 » ; (2025, 0) → « exercice 2025 ». */
export function libellePeriode(annee: number, mois: number): string {
  return mois > 0 ? `${MOIS[mois - 1] ?? mois} ${annee}` : `exercice ${annee}`;
}

/** Date « AAAA-MM-JJ » → « JJ/MM/AAAA ». */
export function formatJour(jour: string | null): string {
  if (!jour) return "";
  const [a, m, j] = jour.slice(0, 10).split("-");
  return `${j}/${m}/${a}`;
}

export function formatDate(iso: string | null): string {
  return iso ? new Date(iso).toLocaleDateString("fr-FR") : "";
}

export function formatDateHeure(iso: string): string {
  return new Date(iso).toLocaleString("fr-FR", { dateStyle: "short", timeStyle: "short" });
}

/** Date du jour « AAAA-MM-JJ » (fuseau local). */
export function aujourdhui(): string {
  return new Date().toLocaleDateString("en-CA");
}

const NIVEAU_BADGE: Record<NiveauRisque, string> = {
  eleve: "bg-red-100 text-red-700",
  moyen: "bg-amber-100 text-amber-800",
  faible: "bg-leaf-100 text-brand-blue",
};

const STATUT_BADGE: Record<StatutAlerte, string> = {
  a_traiter: "bg-blue-100 text-blue-800",
  en_traitement: "bg-amber-100 text-amber-800",
  traitee: "bg-leaf-100 text-brand-blue",
  regularisee: "bg-slate-100 text-slate-600",
};

export function NiveauBadge({ niveau }: { niveau: NiveauRisque }) {
  return (
    <span className={`inline-block rounded px-2 py-0.5 text-xs font-semibold ${NIVEAU_BADGE[niveau]}`}>
      {NIVEAU_LABELS[niveau]}
    </span>
  );
}

export function StatutBadge({ statut }: { statut: StatutAlerte }) {
  return (
    <span className={`inline-block rounded px-2 py-0.5 text-xs font-semibold ${STATUT_BADGE[statut]}`}>
      {STATUT_LABELS[statut]}
    </span>
  );
}

const REGLE_BADGE: Record<Regle, string> = {
  defaillance: "bg-brand-blue/10 text-brand-blue",
  baisse_ca: "bg-orange-100 text-orange-800",
  r1: "bg-amber-100 text-amber-900",
  r2: "bg-amber-100 text-amber-900",
  r3: "bg-amber-100 text-amber-900",
  r5: "bg-amber-100 text-amber-900",
  r6: "bg-amber-100 text-amber-900",
  r7: "bg-amber-100 text-amber-900",
};

/** Type d'anomalie. `court` : libellé compact pour les dossiers regroupant plusieurs règles. */
export function RegleBadge({ regle, court = false }: { regle: Regle; court?: boolean }) {
  return (
    <span
      className={`inline-block rounded px-2 py-0.5 text-xs font-semibold ${REGLE_BADGE[regle] ?? "bg-slate-100 text-slate-700"}`}
      title={REGLE_LABELS[regle] ?? regle}
    >
      {court ? (REGLE_COURTS[regle] ?? REGLE_LABELS[regle] ?? regle) : (REGLE_LABELS[regle] ?? regle)}
    </span>
  );
}

type PeriodeAlerte = Pick<Defaillance, "regle" | "annee_fiscale" | "mois" | "periodes_manquees" | "details">;

/**
 * Période d'une alerte avec sa précision :
 *   défaillance : « Exercice 2026 » · « 5 mois non déposés · R4 3/5 » ou « non déposée · R4 5/5 » ;
 *   baisse du CA : « août 2026 » ou « exercice 2025 » · « CA -35 % ».
 */
export function PeriodeAnomalie({ a }: { a: PeriodeAlerte }) {
  if (a.regle === "baisse_ca") {
    return (
      <>
        {libellePeriode(a.annee_fiscale, a.mois)}
        <p className="text-xs text-slate-500">
          {a.details?.baisse_pct !== undefined ? `CA -${a.details.baisse_pct.toLocaleString("fr-FR")} %` : "Baisse du CA"}
        </p>
      </>
    );
  }

  if (a.regle in SCORE_MAX_R) {
    const max = a.details?.score_max ?? SCORE_MAX_R[a.regle];
    const score = a.details?.score_risque;
    return (
      <>
        Exercice {a.annee_fiscale}
        <p className="text-xs text-slate-500">
          {score !== undefined ? `${a.regle.toUpperCase()} ${score.toLocaleString("fr-FR")}/${max}` : a.regle.toUpperCase()}
        </p>
      </>
    );
  }

  const annuelle = a.details?.periodicite === "annuelle";
  const score = a.details?.score_r4 !== undefined ? ` · R4 ${a.details.score_r4}/5` : "";

  return (
    <>
      Exercice {a.annee_fiscale}
      <p className="text-xs text-slate-500">
        {annuelle
          ? `non déposée${score}`
          : `${a.periodes_manquees} mois non déposé${a.periodes_manquees > 1 ? "s" : ""}${score}`}
      </p>
    </>
  );
}

// ---------------------------------------------------------------------
//  Fiche contribuable (fenêtre)
// ---------------------------------------------------------------------

export function EtatBadge({ fiche }: { fiche: ContribuableFiche["contribuable"] }) {
  if (fiche.etat === null) return null;

  const style =
    fiche.etat === 1 ? "bg-leaf-100 text-brand-blue" : fiche.etat === 2 ? "bg-amber-100 text-amber-800" : "bg-slate-200 text-slate-700";
  const date = fiche.etat === 3 ? fiche.date_cessation : fiche.etat === 4 ? fiche.date_radiation : fiche.etat === 2 ? fiche.date_blocage : null;

  return (
    <span className={`inline-block rounded px-2 py-0.5 text-xs font-semibold ${style}`}>
      {fiche.etat_libelle ?? ETAT_LABELS[fiche.etat] ?? "État inconnu"}
      {date ? ` · ${formatJour(date)}` : ""}
    </span>
  );
}

/** Une cellule de l'historique : déclaré, baisse du CA, défaut de dépôt, pas encore dû, ou aucune obligation. */
function Cellule({ c, baisse }: { c: CelluleDeclaration; baisse: boolean }) {
  const periode = libellePeriode(c.annee, c.mois);

  if (c.declare) {
    const detail = [
      c.chiffre_affaires !== null ? `CA ${c.chiffre_affaires.toLocaleString("fr-FR")} Ar` : "",
      c.date_limite ? `date limite ${formatJour(c.date_limite)}` : "",
      c.date_depot ? `déposé le ${formatJour(c.date_depot)}` : "",
    ]
      .filter(Boolean)
      .join(" · ");

    return (
      <td
        title={`${periode} : ${detail || "déclaré"}${baisse ? " · baisse du CA" : ""}`}
        className={`rounded py-1.5 font-semibold ${baisse ? "bg-orange-100 text-orange-700" : "bg-leaf-100 text-brand-blue"}`}
      >
        {baisse ? "▼" : "✔"}
      </td>
    );
  }

  if (!c.obligee) {
    return (
      <td title={`${periode} : aucune obligation`} className="rounded bg-slate-50 py-1.5 text-gray-300">
        ·
      </td>
    );
  }

  if (c.echue) {
    return (
      <td
        title={`${periode} : non déposée (date limite ${formatJour(c.date_limite)})`}
        className="rounded bg-red-100 py-1.5 font-semibold text-red-600"
      >
        ✘
      </td>
    );
  }

  return (
    <td title={`${periode} : pas encore due`} className="rounded bg-slate-100 py-1.5 text-slate-400">
      …
    </td>
  );
}

export function Matrice({
  titre,
  entetes,
  lignes,
  baisses,
}: {
  titre: string;
  entetes: string[];
  lignes: LigneHistorique[];
  baisses: Set<string>;
}) {
  if (lignes.length === 0) return null;

  return (
    <div>
      <h4 className="mb-1 text-xs font-semibold text-slate-600">{titre}</h4>
      <div className="overflow-x-auto">
        <table className="w-full min-w-[560px] border-separate border-spacing-1 text-center text-xs">
          <thead>
            <tr className="text-slate-500">
              <th className="px-2 py-1 text-left font-medium">Impôt</th>
              {entetes.map((e) => (
                <th key={e} className="px-1 py-1 font-medium">
                  {e}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {lignes.map((l) => (
              <tr key={l.type_impot}>
                <td className="px-2 py-1 text-left font-semibold text-brand-blue">{l.type_impot}</td>
                {l.cellules.map((c) => (
                  <Cellule key={`${c.annee}-${c.mois}`} c={c} baisse={baisses.has(`${l.type_impot}|${c.annee}|${c.mois}`)} />
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

export function ContribuableModal({ id, onClose }: { id: number; onClose: () => void }) {
  const [fiche, setFiche] = useState<ContribuableFiche | null>(null);
  const [error, setError] = useState<string>("");

  useEffect(() => {
    let cancelled = false;
    setFiche(null);
    setError("");

    api
      .get<ContribuableFiche>(`/contribuables/${id}`)
      .then((res) => {
        if (!cancelled) setFiche(res.data);
      })
      .catch((err) => {
        if (!cancelled) setError(getErrorMessage(err, "Impossible de charger la fiche du contribuable."));
      });

    return () => {
      cancelled = true;
    };
  }, [id]);

  // Périodes (par impôt) signalées pour une baisse du chiffre d'affaires
  const baisses = new Set(
    (fiche?.alertes ?? [])
      .filter((a) => a.regle === "baisse_ca")
      .map((a) => `${a.type_impot}|${a.annee_fiscale}|${a.mois}`)
  );

  const mensuel = fiche?.historique.mensuel;
  const annuel = fiche?.historique.annuel;

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-brand-blue-dark/55 p-4"
      onMouseDown={(e) => {
        if (e.target === e.currentTarget) onClose();
      }}
    >
      <div className="max-h-[90vh] w-full max-w-4xl overflow-y-auto rounded-xl border border-line bg-white p-6 shadow-xl">
        <div className="mb-4 flex items-start justify-between gap-4">
          <div>
            <h2 className="text-lg font-semibold text-brand-blue">{fiche ? fiche.contribuable.nom : "Fiche contribuable"}</h2>
            {fiche && (
              <p className="mt-0.5 flex flex-wrap items-center gap-2 text-sm text-slate-500">
                <span>
                  NIF {fiche.contribuable.nif} · {fiche.contribuable.centre ?? "Aucun centre"}
                </span>
                <EtatBadge fiche={fiche.contribuable} />
              </p>
            )}
          </div>
          <button
            type="button"
            onClick={onClose}
            className="text-2xl leading-none text-slate-400 hover:text-slate-600"
            aria-label="Fermer"
          >
            ×
          </button>
        </div>

        {fiche && (
          <p className="-mt-2 mb-4">
            <Link
              to={`/contribuables/${id}`}
              onClick={onClose}
              className="text-xs font-medium text-brand-blue underline"
            >
              Ouvrir la fiche complète (résultat de l'analyse, observations, export PDF) →
            </Link>
          </p>
        )}

        <ErrorBox message={error} />
        {!fiche && !error && <p className="py-10 text-center text-sm text-slate-500">Chargement...</p>}

        {fiche && mensuel && annuel && (
          <div className="space-y-6">
            {/* Chiffres clés */}
            <div className="grid gap-3 sm:grid-cols-3">
              <div className="rounded-xl bg-slate-50 p-3">
                <p className="text-xs text-slate-500">Déclarations enregistrées</p>
                <p className="text-xl font-semibold text-brand-blue">{fiche.stats.nb_declarations}</p>
              </div>
              <div className="rounded-xl bg-slate-50 p-3">
                <p className="text-xs text-slate-500">Première année déclarée</p>
                <p className="text-xl font-semibold text-brand-blue">{fiche.stats.premiere_annee ?? "—"}</p>
              </div>
              <div className="rounded-xl bg-slate-50 p-3">
                <p className="text-xs text-slate-500">Dernière année déclarée</p>
                <p className="text-xl font-semibold text-brand-blue">{fiche.stats.derniere_annee ?? "—"}</p>
              </div>
            </div>

            {/* Obligations */}
            <section>
              <h3 className="mb-2 text-sm font-semibold text-brand-blue">Obligations déclaratives</h3>
              {fiche.obligations.length === 0 ? (
                <p className="rounded-xl bg-slate-50 p-3 text-sm text-slate-500">
                  Aucun assujettissement connu : l'obligation est déduite de la première déclaration de chaque impôt.
                </p>
              ) : (
                <div className="flex flex-wrap gap-2">
                  {fiche.obligations.map((o) => (
                    <span
                      key={`${o.type_impot}-${o.annee_fiscale}`}
                      className={`rounded-lg px-2.5 py-1 text-xs ${
                        o.exonere || !o.actif ? "bg-slate-100 text-slate-500 line-through" : "bg-slate-50 text-brand-blue"
                      }`}
                      title={o.exonere ? "Exonéré" : o.actif ? "Actif" : "Inactif"}
                    >
                      {o.type_impot} {o.annee_fiscale} · {o.periodicite}
                      {o.exonere ? " · exonéré" : ""}
                    </span>
                  ))}
                </div>
              )}
            </section>

            {/* Alertes du contribuable */}
            <section>
              <h3 className="mb-2 text-sm font-semibold text-brand-blue">Alertes ({fiche.alertes.length})</h3>
              {fiche.alertes.length === 0 ? (
                <p className="rounded-xl bg-slate-50 p-3 text-sm text-slate-500">Aucune alerte pour ce contribuable.</p>
              ) : (
                <ul className="space-y-2">
                  {fiche.alertes.map((a) => (
                    <li key={a.id} className="rounded-xl border border-line p-3 text-sm">
                      <div className="flex flex-wrap items-center gap-2">
                        <RegleBadge regle={a.regle} />
                        <NiveauBadge niveau={a.niveau} />
                        <StatutBadge statut={a.statut} />
                        <span className="font-medium text-slate-700">{a.type_impot}</span>
                        <span className="text-slate-500">
                          · {a.regle === "baisse_ca" ? libellePeriode(a.annee_fiscale, a.mois) : `exercice ${a.annee_fiscale}`}
                        </span>
                      </div>
                      <p className="mt-2 text-xs leading-relaxed text-slate-700">{a.motif}</p>
                      {a.commentaire && <p className="mt-1 text-xs italic text-slate-500">Commentaire : {a.commentaire}</p>}
                      {a.statut === "traitee" && a.traite_par && (
                        <p className="mt-1 text-xs text-slate-500">
                          Traitée par {a.traite_par}
                          {a.traite_le ? `, le ${formatDate(a.traite_le)}` : ""}
                        </p>
                      )}
                    </li>
                  ))}
                </ul>
              )}
            </section>

            {/* Historique des déclarations */}
            <section className="space-y-4">
              <div>
                <h3 className="text-sm font-semibold text-brand-blue">Historique des déclarations</h3>
                <p className="text-xs text-slate-500">
                  ✔ déclaré (survolez pour le CA et les dates) · ▼ baisse du CA · ✘ non déposé, date limite dépassée · … pas encore
                  dû · · aucune obligation
                </p>
              </div>

              {mensuel.impots.length === 0 && annuel.impots.length === 0 ? (
                <p className="rounded-xl bg-slate-50 p-3 text-sm text-slate-500">Aucune déclaration enregistrée.</p>
              ) : (
                <>
                  <Matrice
                    titre="Déclarations mensuelles (12 derniers mois)"
                    entetes={mensuel.periodes.map((p) => `${MOIS_COURTS[p.mois - 1]} ${String(p.annee).slice(2)}`)}
                    lignes={mensuel.impots}
                    baisses={baisses}
                  />
                  <Matrice
                    titre="Déclarations annuelles (5 derniers exercices)"
                    entetes={annuel.annees.map(String)}
                    lignes={annuel.impots}
                    baisses={baisses}
                  />
                </>
              )}
            </section>
          </div>
        )}
      </div>
    </div>
  );
}
