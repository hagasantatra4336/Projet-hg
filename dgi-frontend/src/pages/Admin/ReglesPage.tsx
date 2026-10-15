import { useEffect, useState, type FormEvent } from "react";
import { Link } from "react-router-dom";
import api, { getErrorMessage } from "../../services/api";
import type { HistoriqueRegle, ParametreSchema, RegleConfig, ValeursRegle } from "../../types";
import { DeleteButton, ErrorBox, inputClass, primaryButtonClass, secondaryButtonClass } from "../../components/ui";
import { formatDateHeure } from "../../components/defaillance";
import { IconChevron } from "../../components/icons";

type Tranche = (number | "")[];

/** Texte lisible d'une valeur de réglage (pour l'historique). */
function formater(p: ParametreSchema, valeur: unknown): string {
  if (p.type === "bareme" && Array.isArray(valeur)) {
    return (valeur as Tranche[]).map((t) => `${t[0]} à ${t[1]} mois → ${t[2]}`).join(" ; ");
  }
  if (Array.isArray(valeur)) return valeur.join(", ");
  return String(valeur ?? "");
}

/** Résumé d'une modification : ce qui a changé entre « avant » et « après ». */
function resume(h: HistoriqueRegle, schema: ParametreSchema[]): string {
  if (h.action === "reinitialisation") return "Valeurs par défaut rétablies et règle réactivée.";

  const lignes: string[] = [];
  if (h.avant.actif !== h.apres.actif) lignes.push(h.apres.actif ? "Règle activée" : "Règle désactivée");

  for (const p of schema) {
    const a = JSON.stringify(h.avant.parametres?.[p.cle]);
    const b = JSON.stringify(h.apres.parametres?.[p.cle]);
    if (a !== b) lignes.push(`${p.label} : ${formater(p, h.avant.parametres?.[p.cle])} → ${formater(p, h.apres.parametres?.[p.cle])}`);
  }

  return lignes.length > 0 ? lignes.join(" · ") : "Aucun changement de valeur.";
}

function ChampParametre({
  p,
  valeur,
  onChange,
}: {
  p: ParametreSchema;
  valeur: unknown;
  onChange: (v: unknown) => void;
}) {
  const titre = <span className="mb-1 block text-sm font-medium text-brand-blue">{p.label}</span>;
  const aide = <span className="mt-1 block text-xs text-slate-500">{p.aide}</span>;

  if (p.type === "entier" || p.type === "decimal") {
    return (
      <label className="block">
        {titre}
        <input
          type="number"
          className={inputClass}
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
        <div className="flex flex-wrap gap-3">
          {(p.options ?? []).map((impot) => (
            <label key={impot} className="flex items-center gap-2 text-sm text-slate-700">
              <input
                type="checkbox"
                className="h-4 w-4 accent-brand-blue"
                checked={choisis.includes(impot)}
                onChange={(e) => onChange(e.target.checked ? [...choisis, impot] : choisis.filter((i) => i !== impot))}
              />
              {impot}
            </label>
          ))}
        </div>
        {aide}
      </div>
    );
  }

  // Barème : tranches « de … à … mois non déposés → score »
  const tranches: Tranche[] = Array.isArray(valeur) ? (valeur as Tranche[]) : [];
  const maj = (i: number, j: number, v: string) =>
    onChange(tranches.map((t, k) => (k === i ? t.map((x, m) => (m === j ? (v === "" ? "" : Number(v)) : x)) : t)));

  return (
    <div className="md:col-span-2">
      {titre}
      <div className="space-y-2">
        {tranches.map((t, i) => (
          <div key={i} className="flex flex-wrap items-center gap-2 text-sm text-slate-700">
            <span>De</span>
            <input type="number" min={1} max={12} className={`${inputClass} w-20`} value={String(t[0] ?? "")} onChange={(e) => maj(i, 0, e.target.value)} aria-label="Mois minimum" />
            <span>à</span>
            <input type="number" min={1} max={12} className={`${inputClass} w-20`} value={String(t[1] ?? "")} onChange={(e) => maj(i, 1, e.target.value)} aria-label="Mois maximum" />
            <span>mois non déposés → score</span>
            <input type="number" min={0} max={5} className={`${inputClass} w-20`} value={String(t[2] ?? "")} onChange={(e) => maj(i, 2, e.target.value)} aria-label="Score" />
            <span>/ 5</span>
            {tranches.length > 1 && <DeleteButton label="Retirer la tranche" onClick={() => onChange(tranches.filter((_, k) => k !== i))} />}
          </div>
        ))}
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
      setMessage("Réglages enregistrés. Ils s'appliquent à la prochaine analyse (au plus tard dans 5 minutes).");
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
    <section className="rounded-xl bg-white p-5 border border-line sm:p-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0 flex-1">
          <h2 className="text-lg font-semibold text-brand-blue">{regle.nom}</h2>
          {regle.description && <p className="mt-1 text-sm text-slate-600">{regle.description}</p>}
          <p className="mt-1 text-xs text-slate-500">
            {regle.personnalisee ? "Réglages personnalisés" : "Valeurs par défaut"}
            {regle.modifie_par && regle.modifie_le ? ` · modifiés par ${regle.modifie_par} le ${formatDateHeure(regle.modifie_le)}` : ""}
          </p>
        </div>
        <label className="flex items-center gap-2 text-sm font-medium text-brand-blue">
          <input type="checkbox" className="h-5 w-5 accent-brand-blue" checked={actif} onChange={(e) => setActif(e.target.checked)} />
          Règle {actif ? "activée" : "désactivée"}
        </label>
      </div>

      <form onSubmit={enregistrer} className="mt-5 space-y-5">
        <div className="grid gap-5 md:grid-cols-2">
          {regle.schema.map((p) => (
            <ChampParametre key={p.cle} p={p} valeur={valeurs[p.cle]} onChange={(v) => setValeurs((x) => ({ ...x, [p.cle]: v }))} />
          ))}
        </div>

        <ErrorBox message={error} />
        {message && <p className="rounded-lg border border-brand-green/60 bg-leaf-50 px-3 py-2 text-sm text-brand-blue">{message}</p>}

        <div className="flex flex-wrap justify-end gap-3">
          <button type="button" className={secondaryButtonClass} onClick={reinitialiser} disabled={saving}>
            Valeurs par défaut
          </button>
          <button type="submit" className={primaryButtonClass} disabled={saving}>
            {saving ? "Enregistrement..." : "Enregistrer"}
          </button>
        </div>
      </form>

      {regle.historique.length > 0 && (
        <details className="mt-5 border-t border-line pt-4">
          <summary className="cursor-pointer text-sm font-medium text-brand-blue">Historique des modifications ({regle.historique.length})</summary>
          <ul className="mt-3 space-y-2">
            {regle.historique.map((h) => (
              <li key={h.id} className="text-xs text-slate-700">
                <span className="font-medium">{h.date ? formatDateHeure(h.date) : ""}</span> · {h.par ?? "Utilisateur supprimé"} : {resume(h, regle.schema)}
              </li>
            ))}
          </ul>
        </details>
      )}
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
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <p className="max-w-3xl text-sm text-slate-600">
          Réglez ici les seuils, les impôts contrôlés et le barème de chaque règle, ou désactivez une règle. Les changements
          s'appliquent à la prochaine analyse, sans modifier le code. Les alertes déjà créées ne sont pas supprimées.
        </p>
        <Link to="/admin/regles-personnalisees" className={`${primaryButtonClass} inline-flex shrink-0 items-center gap-1 whitespace-nowrap`}>
          Règles personnalisées <IconChevron className="h-4 w-4" />
        </Link>
      </div>

      <ErrorBox message={error} />
      {!regles && !error && <p className="text-sm text-slate-500">Chargement...</p>}

      {regles?.map((r) => (
        <RegleCard key={r.code} regle={r} onChange={(maj) => setRegles((liste) => (liste ?? []).map((x) => (x.code === maj.code ? maj : x)))} />
      ))}
    </div>
  );
}
