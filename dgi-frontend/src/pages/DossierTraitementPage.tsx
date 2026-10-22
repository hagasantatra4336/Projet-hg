import { useCallback, useEffect, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import api, { getErrorMessage } from "../services/api";
import { useAuth } from "../context/AuthContext";
import type { ContribuableFiche, Defaillance, DossierAlerte, LigneBaseRisque } from "../types";
import { ErrorBox, inputClass, primaryButtonClass, secondaryButtonClass } from "../components/ui";
import {
  EtatBadge,
  Matrice,
  NiveauBadge,
  PeriodeAnomalie,
  RegleBadge,
  StatutBadge,
  formatDate,
  formatDateHeure,
  libellePeriode,
} from "../components/defaillance";

const MOIS_COURTS = ["janv.", "févr.", "mars", "avr.", "mai", "juin", "juil.", "août", "sept.", "oct.", "nov.", "déc."];
const HEARTBEAT_MS = 5 * 60_000; // tant que la page est ouverte, le verrou est prolongé

const LIBELLES: Record<string, string> = {
  ca: "Chiffre d'affaires",
  resultat: "Résultat",
  reduction: "Réduction",
  ir_a_payer: "IR à payer",
  ca_taxable: "CA taxable",
  ca_exonere: "CA exonéré",
  tva_deduc_bl: "TVA déd. biens locaux",
  tva_deduc_bi: "TVA déd. biens importés",
  tva_deduc_il: "TVA déd. invest. locaux",
  tva_deduc_ii: "TVA déd. invest. importés",
  tva_deduc_abl: "TVA déd. autres biens locaux",
  tva_deduc_abi: "TVA déd. autres biens importés",
  credit_tva: "Crédit de TVA",
  ca_taxable_export: "CA taxable export",
  prestations_de_service: "Prestations de service",
  achats_effectues: "Achats effectués",
  achats_consommes: "Achats consommés",
  produits_locaux_vendus: "Produits locaux vendus",
  exportations: "Exportations",
  importations: "Importations",
  montant_total: "Montant total",
  tva_ded_achat: "TVA déd. achats",
  tva_ded_imp: "TVA déd. importations",
  marche_public: "Marchés publics",
  dau_tva_im4: "TVA des DAU IM4",
  dau_caf_ex1: "Valeur CAF des DAU EX1",
};

const SOURCES: { cle: "IR" | "TVA" | "RECOUPEMENT" | "DAU"; titre: string; parMois?: boolean }[] = [
  { cle: "IR", titre: "Déclarations IR (par exercice)" },
  { cle: "TVA", titre: "Déclarations TVA (par mois)", parMois: true },
  { cle: "RECOUPEMENT", titre: "Recoupement (par exercice)" },
  { cle: "DAU", titre: "Douane : DAU (par exercice)" },
];

const nombre = (v: number | null | undefined) => (v === null || v === undefined ? "—" : v.toLocaleString("fr-FR", { maximumFractionDigits: 2 }));

/** Tableau d'une source : une ligne par année (ou par mois), une colonne par valeur renseignée. */
function TableauSource({ titre, lignes, parMois }: { titre: string; lignes: LigneBaseRisque[]; parMois?: boolean }) {
  if (lignes.length === 0) return null;

  const colonnes = Object.keys(lignes[0].valeurs).filter((c) => lignes.some((l) => l.valeurs[c] !== null));
  if (colonnes.length === 0) return null;

  return (
    <div>
      <h4 className="mb-1 text-xs font-semibold text-slate-600">{titre}</h4>
      <div className="overflow-x-auto rounded-lg border border-line">
        <table className="w-full min-w-[520px] text-xs">
          <thead className="bg-slate-50 text-left text-slate-500">
            <tr>
              <th className="whitespace-nowrap px-3 py-2 font-medium">Période</th>
              {colonnes.map((c) => (
                <th key={c} className="px-3 py-2 text-right font-medium">
                  {LIBELLES[c] ?? c}
                </th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-line">
            {lignes.map((l) => (
              <tr key={`${l.annee}-${l.mois}`}>
                <td className="whitespace-nowrap px-3 py-1.5 font-medium text-slate-700">
                  {parMois && l.mois > 0 ? `${MOIS_COURTS[l.mois - 1]} ${l.annee}` : l.annee}
                </td>
                {colonnes.map((c) => (
                  <td key={c} className={`whitespace-nowrap px-3 py-1.5 text-right ${(l.valeurs[c] ?? 0) < 0 ? "text-red-700" : "text-slate-800"}`}>
                    {nombre(l.valeurs[c])}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function Bandeau({ dossier, moi, canManage, onReprendre, busy }: { dossier: DossierAlerte; moi: boolean; canManage: boolean; onReprendre: () => void; busy: boolean }) {
  const a = dossier.alerte;

  if (a.statut === "en_traitement" && moi) {
    return (
      <p className="rounded-xl border border-brand-green/60 bg-leaf-50 px-4 py-3 text-sm text-brand-blue">
        Ce dossier est <strong>réservé pour vous</strong>{a.pris_le ? ` depuis ${formatDateHeure(a.pris_le)}` : ""}. Les autres utilisateurs le voient « En
        traitement » et ne peuvent pas le prendre. Pensez à le marquer comme traité, ou à le libérer ; sans activité, il est libéré
        automatiquement après {Math.round(dossier.verrou_minutes / 60)} h.
      </p>
    );
  }

  if (a.statut === "en_traitement") {
    return (
      <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        <p>
          Dossier <strong>en cours de traitement par {a.pris_par ?? "un autre utilisateur"}</strong>
          {a.pris_le ? ` depuis ${formatDateHeure(a.pris_le)}` : ""}. Vous pouvez le consulter, pas le traiter.
        </p>
        {canManage && (
          <button type="button" className={secondaryButtonClass} disabled={busy} onClick={onReprendre}>
            Reprendre le dossier
          </button>
        )}
      </div>
    );
  }

  if (a.statut === "traitee") {
    return (
      <p className="rounded-xl border border-line bg-slate-50 px-4 py-3 text-sm text-slate-700">
        Dossier traité{a.traite_par ? ` par ${a.traite_par}` : ""}
        {a.traite_le ? `, le ${formatDate(a.traite_le)}` : ""}.
      </p>
    );
  }

  if (a.statut === "regularisee") {
    return (
      <p className="rounded-xl border border-line bg-slate-50 px-4 py-3 text-sm text-slate-700">
        L'anomalie n'est plus constatée (déclaration déposée ou corrigée) : l'alerte est régularisée.
      </p>
    );
  }

  return (
    <p className="rounded-xl border border-line bg-slate-50 px-4 py-3 text-sm text-slate-700">
      Ce dossier n'est pris par personne. Cliquez sur « Prendre le dossier » pour le traiter : il passera « En traitement » pour les autres.
    </p>
  );
}

/** Page de traitement d'un dossier : tout ce qui concerne l'alerte (valeurs, formule, autres alertes, historique) et les actions. */
export default function DossierTraitementPage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { user } = useAuth();
  const canManage = user?.role === "superadmin" || user?.role === "central" || user?.role === "admin";

  const [dossier, setDossier] = useState<DossierAlerte | null>(null);
  const [fiche, setFiche] = useState<ContribuableFiche | null>(null);
  const [commentaire, setCommentaire] = useState<string>("");
  const [error, setError] = useState<string>("");
  const [message, setMessage] = useState<string>("");
  const [busy, setBusy] = useState<boolean>(false);

  const charger = useCallback(async () => {
    try {
      const res = await api.get<DossierAlerte>(`/defaillances/${id}/dossier`);
      setDossier(res.data);
      setCommentaire((c) => (c === "" ? (res.data.alerte.commentaire ?? "") : c));
      setError("");

      const f = await api.get<ContribuableFiche>(`/contribuables/${res.data.alerte.contribuable.id}`);
      setFiche(f.data);
    } catch (err) {
      setError(getErrorMessage(err, "Impossible de charger le dossier."));
    }
  }, [id]);

  useEffect(() => {
    void charger();
  }, [charger]);

  const moi = dossier?.alerte.pris_par_moi === true;
  const enTraitementParMoi = dossier?.alerte.statut === "en_traitement" && moi;

  // Tant que la page est ouverte, le verrou est prolongé
  useEffect(() => {
    if (!enTraitementParMoi) return;
    const t = setInterval(() => {
      api.post(`/defaillances/${id}/prendre`).catch(() => undefined);
    }, HEARTBEAT_MS);
    return () => clearInterval(t);
  }, [enTraitementParMoi, id]);

  async function action(fn: () => Promise<void>, ok?: string) {
    setBusy(true);
    setError("");
    setMessage("");
    try {
      await fn();
      if (ok) setMessage(ok);
    } catch (err) {
      setError(getErrorMessage(err, "Action impossible."));
      void charger(); // l'état du dossier a peut-être changé (pris par un autre, traité...)
    } finally {
      setBusy(false);
    }
  }

  const prendre = (forcer = false) =>
    action(async () => {
      await api.post(`/defaillances/${id}/prendre`, forcer ? { forcer: true } : {});
      await charger();
    }, "Dossier pris : il est « En traitement » pour les autres utilisateurs.");

  const enregistrer = () =>
    action(async () => {
      await api.patch(`/defaillances/${id}`, { commentaire });
      await charger();
    }, "Commentaire enregistré.");

  const marquerTraite = () =>
    action(async () => {
      await api.patch(`/defaillances/${id}`, { statut: "traitee", commentaire });
      navigate("/defaillances");
    });

  const liberer = () =>
    action(async () => {
      await api.post(`/defaillances/${id}/liberer`);
      navigate("/defaillances");
    });

  const rouvrir = () =>
    action(async () => {
      await api.patch(`/defaillances/${id}`, { statut: "a_traiter" });
      await charger();
    }, "Dossier rouvert : il est de nouveau à traiter.");

  if (!dossier) {
    return (
      <div className="space-y-4">
        <Link to="/defaillances" className="text-sm font-medium text-brand-blue underline">
          ← Retour aux alertes
        </Link>
        <ErrorBox message={error} />
        {!error && <p className="text-sm text-slate-500">Chargement du dossier...</p>}
      </div>
    );
  }

  const a: Defaillance = dossier.alerte;
  const autres = (fiche?.alertes ?? []).filter((x) => x.id !== a.id);
  const baisses = new Set((fiche?.alertes ?? []).filter((x) => x.regle === "baisse_ca").map((x) => `${x.type_impot}|${x.annee_fiscale}|${x.mois}`));
  const indicateursAlerte = Object.entries(a.details?.indicateurs ?? {});
  const aDesDonnees = SOURCES.some((s) => dossier.indicateurs[s.cle].length > 0);
  const lectureSeule = !(a.statut === "en_traitement" && moi);

  return (
    <div className="space-y-5">
      <Link to="/defaillances" className="text-sm font-medium text-brand-blue underline">
        ← Retour aux alertes
      </Link>

      {/* En-tête */}
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-brand-blue">{a.contribuable.nom}</h1>
          <p className="mt-0.5 flex flex-wrap items-center gap-2 text-sm text-slate-500">
            <span>
              NIF {a.contribuable.nif} · {a.contribuable.centre ?? "Aucun centre"}
            </span>
            {fiche && <EtatBadge fiche={fiche.contribuable} />}
          </p>
        </div>
        <StatutBadge statut={a.statut} />
      </div>

      <Bandeau dossier={dossier} moi={moi} canManage={canManage} busy={busy} onReprendre={() => void prendre(true)} />
      <ErrorBox message={error} />
      {message && <p className="rounded-lg border border-brand-green/60 bg-leaf-50 px-3 py-2 text-sm text-brand-blue">{message}</p>}

      {/* L'alerte */}
      <section className="rounded-xl border border-line bg-white p-5">
        <h2 className="text-sm font-semibold text-brand-blue">L'alerte</h2>
        <div className="mt-3 flex flex-wrap items-center gap-2">
          <RegleBadge regle={a.regle} />
          <NiveauBadge niveau={a.niveau} />
          <span className="text-sm font-medium text-slate-700">{a.type_impot}</span>
          <span className="text-sm text-slate-500">
            · {a.regle === "baisse_ca" ? libellePeriode(a.annee_fiscale, a.mois) : `exercice ${a.annee_fiscale}`}
          </span>
        </div>
        <p className="mt-3 text-sm leading-relaxed text-slate-800">{a.motif}</p>
        <div className="mt-1 text-xs text-slate-500">
          <PeriodeAnomalie a={a} />
        </div>

        {indicateursAlerte.length > 0 && (
          <dl className="mt-4 grid gap-x-6 gap-y-2 sm:grid-cols-2">
            {indicateursAlerte.map(([k, v]) => (
              <div key={k} className="flex justify-between gap-3 border-b border-line pb-1 text-sm">
                <dt className="text-slate-500">{LIBELLES[k] ?? k.replace(/_/g, " ")}</dt>
                <dd className="font-medium text-slate-900">{typeof v === "number" ? nombre(v) : String(v ?? "—")}</dd>
              </div>
            ))}
          </dl>
        )}

        {dossier.formule && dossier.formule.formules.length > 0 && (
          <details className="mt-4 rounded-lg border border-line bg-slate-50/70 p-3">
            <summary className="cursor-pointer text-sm font-medium text-brand-blue">Comment cette alerte est calculée</summary>
            <dl className="mt-3 space-y-2">
              {dossier.formule.formules.map((f) => (
                <div key={f.label} className="grid gap-x-4 gap-y-0.5 sm:grid-cols-[11rem_1fr]">
                  <dt className="text-xs font-medium text-slate-500">{f.label}</dt>
                  <dd className="text-sm text-slate-800">{f.expr}</dd>
                </div>
              ))}
            </dl>
          </details>
        )}
      </section>

      {/* Données du dossier */}
      <section className="space-y-4 rounded-xl border border-line bg-white p-5">
        <div>
          <h2 className="text-sm font-semibold text-brand-blue">Données du dossier</h2>
          <p className="text-xs text-slate-500">Valeurs lues dans les déclarations, le recoupement et la douane, sur l'exercice de l'alerte et les deux précédents.</p>
        </div>
        {aDesDonnees ? (
          SOURCES.map((s) => <TableauSource key={s.cle} titre={s.titre} lignes={dossier.indicateurs[s.cle]} parMois={s.parMois} />)
        ) : (
          <p className="rounded-lg bg-slate-50 p-3 text-sm text-slate-500">
            Aucune donnée dans la base d'analyse risque pour ce contribuable (elle se remplit avec « php artisan dgi:importer-risque »).
          </p>
        )}
      </section>

      {/* Autres alertes du contribuable */}
      <section className="rounded-xl border border-line bg-white p-5">
        <h2 className="text-sm font-semibold text-brand-blue">Autres alertes de ce contribuable ({autres.length})</h2>
        {!fiche ? (
          <p className="mt-2 text-sm text-slate-500">Chargement...</p>
        ) : autres.length === 0 ? (
          <p className="mt-2 text-sm text-slate-500">Aucune autre alerte.</p>
        ) : (
          <ul className="mt-3 space-y-2">
            {autres.map((x) => (
              <li key={x.id} className="rounded-lg border border-line p-3 text-sm">
                <div className="flex flex-wrap items-center gap-2">
                  <RegleBadge regle={x.regle} />
                  <NiveauBadge niveau={x.niveau} />
                  <StatutBadge statut={x.statut} />
                  <span className="font-medium text-slate-700">{x.type_impot}</span>
                  <span className="text-slate-500">· {x.regle === "baisse_ca" ? libellePeriode(x.annee_fiscale, x.mois) : `exercice ${x.annee_fiscale}`}</span>
                </div>
                <p className="mt-2 text-xs leading-relaxed text-slate-700">{x.motif}</p>
                {x.commentaire && <p className="mt-1 text-xs italic text-slate-500">Commentaire : {x.commentaire}</p>}
              </li>
            ))}
          </ul>
        )}
      </section>

      {/* Historique des déclarations et obligations */}
      {fiche && (
        <section className="space-y-4 rounded-xl border border-line bg-white p-5">
          <div>
            <h2 className="text-sm font-semibold text-brand-blue">Historique des déclarations</h2>
            <p className="text-xs text-slate-500">
              ✔ déclaré (survolez pour le CA et les dates) · ▼ baisse du CA · ✘ non déposé, date limite dépassée · … pas encore dû · · aucune obligation
            </p>
          </div>
          {fiche.historique.mensuel.impots.length === 0 && fiche.historique.annuel.impots.length === 0 ? (
            <p className="rounded-lg bg-slate-50 p-3 text-sm text-slate-500">Aucune déclaration enregistrée.</p>
          ) : (
            <>
              <Matrice
                titre="Déclarations mensuelles (12 derniers mois)"
                entetes={fiche.historique.mensuel.periodes.map((p) => `${MOIS_COURTS[p.mois - 1]} ${String(p.annee).slice(2)}`)}
                lignes={fiche.historique.mensuel.impots}
                baisses={baisses}
              />
              <Matrice
                titre="Déclarations annuelles (5 derniers exercices)"
                entetes={fiche.historique.annuel.annees.map(String)}
                lignes={fiche.historique.annuel.impots}
                baisses={baisses}
              />
            </>
          )}

          {fiche.obligations.length > 0 && (
            <div>
              <h4 className="mb-1 text-xs font-semibold text-slate-600">Obligations déclaratives</h4>
              <ul className="flex flex-wrap gap-2 text-xs">
                {fiche.obligations.map((o) => (
                  <li key={`${o.type_impot}-${o.annee_fiscale}`} className="rounded border border-line bg-slate-50 px-2 py-1 text-slate-700">
                    {o.type_impot} {o.annee_fiscale} · {o.periodicite}
                    {o.exonere ? " · exonéré" : ""}
                    {!o.actif ? " · inactif" : ""}
                  </li>
                ))}
              </ul>
            </div>
          )}
        </section>
      )}

      {/* Traitement */}
      <section className="rounded-xl border border-line bg-white p-5">
        <h2 className="text-sm font-semibold text-brand-blue">Traitement</h2>
        <label className="mt-3 block">
          <span className="mb-1 block text-sm font-medium text-slate-800">Commentaire</span>
          <textarea
            className={inputClass}
            rows={4}
            maxLength={2000}
            value={commentaire}
            disabled={lectureSeule}
            onChange={(e) => setCommentaire(e.target.value)}
            placeholder="Ex. : contribuable contacté, déclaration attendue avant le 15..."
          />
        </label>

        <div className="mt-4 flex flex-wrap justify-end gap-3">
          {a.statut === "a_traiter" && (
            <button type="button" className={primaryButtonClass} disabled={busy} onClick={() => void prendre()}>
              Prendre le dossier
            </button>
          )}
          {enTraitementParMoi && (
            <>
              <button type="button" className={secondaryButtonClass} disabled={busy} onClick={() => void liberer()}>
                Libérer le dossier
              </button>
              <button type="button" className={secondaryButtonClass} disabled={busy} onClick={() => void enregistrer()}>
                Enregistrer le commentaire
              </button>
              <button type="button" className={primaryButtonClass} disabled={busy} onClick={() => void marquerTraite()}>
                Marquer comme traité
              </button>
            </>
          )}
          {a.statut === "traitee" && (
            <button type="button" className={secondaryButtonClass} disabled={busy} onClick={() => void rouvrir()}>
              Rouvrir le dossier
            </button>
          )}
        </div>
      </section>
    </div>
  );
}
