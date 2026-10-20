import { useEffect, useState, type FormEvent } from "react";
import { Link } from "react-router-dom";
import api, { getErrorMessage } from "../../services/api";
import type { FormuleRegle, ParametreSchema, RegleConfig, ValeursRegle } from "../../types";
import { DeleteButton, ErrorBox, inputClass, primaryButtonClass, secondaryButtonClass } from "../../components/ui";
import { formatDateHeure } from "../../components/defaillance";

type Tranche = (number | "")[];

/** Regroupement des réglages en rubriques : l'ordre d'affichage est celui de cette liste. */
const RUBRIQUES: { titre: string; cles: string[] }[] = [
  { titre: "Périmètre de l'analyse", cles: ["impots", "fenetre_exercices", "fenetre_annees"] },
  { titre: "Barème de score", cles: ["score_annuel", "bareme_mensuel"] },
  { titre: "Hypothèses de calcul", cles: ["taux_tva", "marge"] },
  { titre: "Niveaux de risque", cles: ["seuil_pourcentage", "seuil_moyen", "seuil_eleve", "score_moyen", "score_eleve"] },
];

const POINT_NIVEAU: Record<string, string> = {
  vert: "bg-brand-green",
  faible: "bg-slate-400",
  orange: "bg-orange-500",
  rouge: "bg-red-600",
};

const LABEL_NIVEAU: Record<string, string> = { vert: "Vert", faible: "Faible", orange: "Orange", rouge: "Rouge" };

function rubriques(schema: ParametreSchema[]): { titre: string; champs: ParametreSchema[] }[] {
  const utilises = new Set<string>();
  const liste = RUBRIQUES.map((r) => {
    const champs = schema.filter((p) => r.cles.includes(p.cle));
    champs.forEach((p) => utilises.add(p.cle));
    return { titre: r.titre, champs };
  }).filter((r) => r.champs.length > 0);

  const autres = schema.filter((p) => !utilises.has(p.cle));
  return autres.length > 0 ? [...liste, { titre: "Autres réglages", champs: autres }] : liste;
}

function ChampParametre({ p, valeur, onChange }: { p: ParametreSchema; valeur: unknown; onChange: (v: unknown) => void }) {
  const titre = <span className="block text-sm font-medium text-slate-800">{p.label}</span>;
  const aide = <span className="mt-1 block text-xs leading-snug text-slate-500">{p.aide}</span>;

  if (p.type === "entier" || p.type === "decimal") {
    return (
      <label className="block">
        {titre}
        <input
          type="number"
          className={`${inputClass} mt-1.5 max-w-[9rem]`}
          min={p.min}
          max={p.max}
          step={p.type === "decimal" ? 0.1 : 1}
          value={String(valeur ?? "")}
          onChange={(e) => onChange(e.target.value === "" ? "" : Number(e.target.value))}
        />
        {aide}
      </label>
    );
  }

  if (p.type === "liste_impots") {
    const choisis = Array.isArray(valeur) ? (valeur as string[]) : [];

    return (
      <div>
        {titre}
        <div className="mt-2 flex flex-wrap gap-2">
          {(p.options ?? []).map((impot) => {
            const coche = choisis.includes(impot);
            return (
              <label
                key={impot}
                className={`flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-1.5 text-sm transition ${
                  coche ? "border-brand-blue bg-brand-blue/5 font-medium text-brand-blue" : "border-slate-300 text-slate-600 hover:bg-slate-50"
                }`}
              >
                <input
                  type="checkbox"
                  className="h-4 w-4 accent-brand-blue"
                  checked={coche}
                  onChange={(e) => onChange(e.target.checked ? [...choisis, impot] : choisis.filter((i) => i !== impot))}
                />
                {impot}
              </label>
            );
          })}
        </div>
        {aide}
      </div>
    );
  }

  // Barème : tableau « de … à … mois non déposés → score »
  const tranches: Tranche[] = Array.isArray(valeur) ? (valeur as Tranche[]) : [];
  const maj = (i: number, j: number, v: string) =>
    onChange(tranches.map((t, k) => (k === i ? t.map((x, m) => (m === j ? (v === "" ? "" : Number(v)) : x)) : t)));

  return (
    <div className="md:col-span-2">
      {titre}
      <div className="mt-2 overflow-hidden rounded-lg border border-line">
        <table className="w-full text-sm">
          <thead className="bg-slate-50 text-left text-xs font-medium text-slate-500">
            <tr>
              <th className="px-3 py-2">Mois non déposés, de</th>
              <th className="px-3 py-2">à</th>
              <th className="px-3 py-2">Score (sur 5)</th>
              <th className="w-12 px-3 py-2" />
            </tr>
          </thead>
          <tbody className="divide-y divide-line">
            {tranches.map((t, i) => (
              <tr key={i}>
                <td className="px-3 py-2">
                  <input type="number" min={1} max={12} className={`${inputClass} w-24`} value={String(t[0] ?? "")} onChange={(e) => maj(i, 0, e.target.value)} aria-label="Mois minimum" />
                </td>
                <td className="px-3 py-2">
                  <input type="number" min={1} max={12} className={`${inputClass} w-24`} value={String(t[1] ?? "")} onChange={(e) => maj(i, 1, e.target.value)} aria-label="Mois maximum" />
                </td>
                <td className="px-3 py-2">
                  <input type="number" min={0} max={5} className={`${inputClass} w-24`} value={String(t[2] ?? "")} onChange={(e) => maj(i, 2, e.target.value)} aria-label="Score" />
                </td>
                <td className="px-3 py-2 text-right">
                  {tranches.length > 1 && <DeleteButton label="Retirer la tranche" onClick={() => onChange(tranches.filter((_, k) => k !== i))} />}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {tranches.length < 6 && (
        <button type="button" className="mt-2 text-sm font-medium text-brand-blue underline" onClick={() => onChange([...tranches, ["", "", ""]])}>
          Ajouter une tranche
        </button>
      )}
      {aide}
    </div>
  );
}

/** Formules de calcul et lecture des niveaux de risque (texte fourni par l'API, seuils en vigueur). */
function Formules({ formule }: { formule: FormuleRegle }) {
  if (formule.formules.length === 0) return null;

  return (
    <div className="mt-4 rounded-lg border border-line bg-slate-50/70 p-4">
      <h3 className="text-sm font-semibold text-brand-blue">Comment c'est calculé</h3>

      <dl className="mt-3 space-y-2.5">
        {formule.formules.map((f) => (
          <div key={f.label} className="grid gap-x-4 gap-y-0.5 sm:grid-cols-[11rem_1fr]">
            <dt className="text-xs font-medium text-slate-500">{f.label}</dt>
            <dd className="text-sm leading-relaxed text-slate-800">{f.expr}</dd>
          </div>
        ))}
        {formule.sources.length > 0 && (
          <div className="grid gap-x-4 gap-y-0.5 sm:grid-cols-[11rem_1fr]">
            <dt className="text-xs font-medium text-slate-500">Données utilisées</dt>
            <dd className="text-sm leading-relaxed text-slate-700">{formule.sources.join(" · ")}</dd>
          </div>
        )}
      </dl>

      <ul className="mt-4 space-y-1.5 border-t border-line pt-3">
        {formule.niveaux.map((n) => (
          <li key={n.texte} className="flex items-start gap-2.5 text-sm text-slate-700">
            <span className={`mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full ${POINT_NIVEAU[n.couleur] ?? "bg-slate-400"}`} aria-hidden="true" />
            <span>
              <span className="font-medium text-slate-900">{LABEL_NIVEAU[n.couleur] ?? n.couleur}</span> : {n.texte}
            </span>
          </li>
        ))}
      </ul>
    </div>
  );
}

function RegleCard({ regle, onChange }: { regle: RegleConfig; onChange: (r: RegleConfig) => void }) {
  const [actif, setActif] = useState<boolean>(regle.actif);
  const [valeurs, setValeurs] = useState<ValeursRegle>(regle.parametres);
  const [saving, setSaving] = useState<boolean>(false);
  const [error, setError] = useState<string>("");
  const [message, setMessage] = useState<string>("");

  // Recharge le formulaire quand la règle est mise à jour (enregistrement ou réinitialisation)
  useEffect(() => {
    setActif(regle.actif);
    setValeurs(regle.parametres);
  }, [regle]);

  async function enregistrer(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError("");
    setMessage("");

    try {
      const res = await api.put<RegleConfig>(`/regles/${regle.code}`, { actif, parametres: valeurs });
      onChange(res.data);
      setMessage("Réglages enregistrés. Les alertes d'un impôt décoché ou d'une règle désactivée sont masquées tout de suite ; les nouvelles alertes tiennent compte des réglages à la prochaine analyse.");
    } catch (err) {
      setError(getErrorMessage(err, "Enregistrement impossible."));
    } finally {
      setSaving(false);
    }
  }

  async function reinitialiser() {
    if (!window.confirm("Rétablir les valeurs par défaut et réactiver cette règle ?")) return;
    setSaving(true);
    setError("");
    setMessage("");

    try {
      const res = await api.post<RegleConfig>(`/regles/${regle.code}/reinitialiser`);
      onChange(res.data);
      setMessage("Valeurs par défaut rétablies.");
    } catch (err) {
      setError(getErrorMessage(err, "Réinitialisation impossible."));
    } finally {
      setSaving(false);
    }
  }

  return (
    <section id={regle.code} className="rounded-xl border border-line bg-white p-5 sm:p-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0 flex-1">
          <h2 className="text-lg font-semibold text-brand-blue">{regle.nom}</h2>
          {regle.description && <p className="mt-1 text-sm text-slate-600">{regle.description}</p>}
          <p className="mt-1.5 text-xs text-slate-500">
            {regle.personnalisee ? "Réglages personnalisés" : "Valeurs par défaut"}
            {regle.modifie_par && regle.modifie_le ? ` · modifiés par ${regle.modifie_par} le ${formatDateHeure(regle.modifie_le)}` : ""}
            {" · "}
            <Link to={`/admin/regles/historique?regle=${regle.code}`} className="font-medium text-brand-blue underline">
              Voir l'historique
            </Link>
          </p>
        </div>
        <label className="flex items-center gap-2 rounded-lg border border-line px-3 py-1.5 text-sm font-medium text-brand-blue">
          <input type="checkbox" className="h-5 w-5 accent-brand-blue" checked={actif} onChange={(e) => setActif(e.target.checked)} />
          Règle {actif ? "activée" : "désactivée"}
        </label>
      </div>

      {regle.formule && <Formules formule={regle.formule} />}

      <form onSubmit={enregistrer} className="mt-5 space-y-6">
        {rubriques(regle.schema).map((r) => (
          <fieldset key={r.titre}>
            <legend className="mb-3 w-full border-b border-line pb-1.5 text-sm font-semibold text-brand-blue">{r.titre}</legend>
            <div className="grid gap-x-8 gap-y-5 md:grid-cols-2">
              {r.champs.map((p) => (
                <ChampParametre key={p.cle} p={p} valeur={valeurs[p.cle]} onChange={(v) => setValeurs((x) => ({ ...x, [p.cle]: v }))} />
              ))}
            </div>
          </fieldset>
        ))}

        <ErrorBox message={error} />
        {message && <p className="rounded-lg border border-brand-green/60 bg-leaf-50 px-3 py-2 text-sm text-brand-blue">{message}</p>}

        <div className="flex flex-wrap justify-end gap-3 border-t border-line pt-4">
          <button type="button" className={secondaryButtonClass} onClick={reinitialiser} disabled={saving}>
            Valeurs par défaut
          </button>
          <button type="submit" className={primaryButtonClass} disabled={saving}>
            {saving ? "Enregistrement..." : "Enregistrer"}
          </button>
        </div>
      </form>
    </section>
  );
}

export default function ReglesPage() {
  const [regles, setRegles] = useState<RegleConfig[] | null>(null);
  const [error, setError] = useState<string>("");

  useEffect(() => {
    api
      .get<{ regles: RegleConfig[] }>("/regles")
      .then((res) => setRegles(res.data.regles))
      .catch((err) => setError(getErrorMessage(err, "Impossible de charger les règles.")));
  }, []);

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="flex flex-wrap gap-3">
          <Link to="/admin/regles/historique" className={`${secondaryButtonClass} inline-block`}>
            Historique des règles
          </Link>
          <Link to="/admin/regles-personnalisees" className={`${primaryButtonClass} inline-block text-center`}>
            Règles personnalisées
          </Link>
        </div>
      </div>

      <ErrorBox message={error} />
      {!regles && !error && <p className="text-sm text-slate-500">Chargement...</p>}

      {regles?.map((r) => (
        <RegleCard key={r.code} regle={r} onChange={(maj) => setRegles((liste) => (liste ?? []).map((x) => (x.code === maj.code ? maj : x)))} />
      ))}
    </div>
  );
}
