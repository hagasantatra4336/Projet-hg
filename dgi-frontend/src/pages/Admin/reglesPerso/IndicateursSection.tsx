import { useEffect, useState, type FormEvent } from "react";
import api, { getErrorMessage } from "../../../services/api";
import type { IndicateurAdmin } from "../../../typesReglesPerso";
import { DeleteButton, ErrorBox, inputClass, primaryButtonClass } from "../../../components/ui";

interface Reponse {
  indicateurs: IndicateurAdmin[];
  colonnes_disponibles: { colonne: string; type: string }[];
}

/** Indicateurs : valeurs lues dans les colonnes numériques de la table des déclarations. */
export default function IndicateursSection({ impots, onChange }: { impots: string[]; onChange: () => void }) {
  const [data, setData] = useState<Reponse | null>(null);
  const [error, setError] = useState<string>("");
  const [saving, setSaving] = useState<boolean>(false);
  const [code, setCode] = useState<string>("");
  const [libelle, setLibelle] = useState<string>("");
  const [colonne, setColonne] = useState<string>("");
  const [unite, setUnite] = useState<string>("");
  const [choisis, setChoisis] = useState<string[]>([]);

  function charger() {
    api
      .get<Reponse>("/indicateurs")
      .then((res) => setData(res.data))
      .catch((err) => setError(getErrorMessage(err, "Impossible de charger les indicateurs.")));
  }

  useEffect(charger, []);

  async function ajouter(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError("");
    try {
      await api.post("/indicateurs", { code, libelle, colonne, unite: unite || null, impots: choisis.length > 0 ? choisis : null });
      setCode("");
      setLibelle("");
      setColonne("");
      setUnite("");
      setChoisis([]);
      charger();
      onChange();
    } catch (err) {
      setError(getErrorMessage(err, "Ajout impossible."));
    } finally {
      setSaving(false);
    }
  }

  async function basculer(i: IndicateurAdmin) {
    setError("");
    try {
      await api.put(`/indicateurs/${i.id}`, { actif: !i.actif });
      charger();
      onChange();
    } catch (err) {
      setError(getErrorMessage(err, "Modification impossible."));
    }
  }

  async function supprimer(i: IndicateurAdmin) {
    if (!window.confirm(`Supprimer l'indicateur « ${i.libelle} » ?`)) return;
    setError("");
    try {
      await api.delete(`/indicateurs/${i.id}`);
      charger();
      onChange();
    } catch (err) {
      setError(getErrorMessage(err, "Suppression impossible."));
    }
  }

  return (
    <details className="rounded-xl bg-white p-5 border border-line sm:p-6">
      <summary className="cursor-pointer text-lg font-semibold text-brand-blue">Indicateurs ({data?.indicateurs.length ?? "…"})</summary>
      <p className="mt-2 max-w-3xl text-sm text-slate-600">
        Un indicateur est une valeur lue dans une colonne numérique de la table des déclarations (chiffre d'affaires, montant dû, etc.). Les règles personnalisées s'appuient sur eux.
      </p>

      <ErrorBox message={error} />

      <ul className="mt-4 divide-y divide-line">
        {data?.indicateurs.map((i) => (
          <li key={i.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
            <div className="min-w-0">
              <span className="font-medium text-brand-blue">{i.libelle}</span>{" "}
              <span className="text-xs text-slate-500">
                ({i.code} · colonne {i.colonne}
                {i.unite ? ` · ${i.unite}` : ""}
                {i.impots ? ` · ${i.impots.join(", ")}` : ""})
              </span>
              {i.systeme && <span className="ml-2 text-xs text-slate-500">fourni</span>}
              {i.utilise && <span className="ml-2 text-xs text-slate-500">utilisé</span>}
            </div>
            <div className="flex items-center gap-3">
              <label className="flex items-center gap-2 text-xs text-slate-700">
                <input type="checkbox" className="h-4 w-4 accent-brand-blue" checked={i.actif} onChange={() => basculer(i)} />
                Actif
              </label>
              {!i.systeme && !i.utilise && <DeleteButton label="Supprimer l'indicateur" onClick={() => supprimer(i)} />}
            </div>
          </li>
        ))}
      </ul>

      <form onSubmit={ajouter} className="mt-5 grid gap-4 border-t border-line pt-4 md:grid-cols-2">
        <p className="text-sm font-medium text-brand-blue md:col-span-2">Ajouter un indicateur</p>
        <label className="block text-sm text-slate-700">
          Libellé
          <input className={inputClass} value={libelle} onChange={(e) => setLibelle(e.target.value)} required maxLength={150} />
        </label>
        <label className="block text-sm text-slate-700">
          Code (lettres minuscules, chiffres, _)
          <input className={inputClass} value={code} onChange={(e) => setCode(e.target.value)} required pattern="[a-z][a-z0-9_]{1,39}" />
        </label>
        <label className="block text-sm text-slate-700">
          Colonne de la table des déclarations
          <select className={inputClass} value={colonne} onChange={(e) => setColonne(e.target.value)} required>
            <option value="">— choisir —</option>
            {data?.colonnes_disponibles.map((c) => (
              <option key={c.colonne} value={c.colonne}>
                {c.colonne}
              </option>
            ))}
          </select>
        </label>
        <label className="block text-sm text-slate-700">
          Unité (facultatif)
          <input className={inputClass} value={unite} onChange={(e) => setUnite(e.target.value)} maxLength={20} placeholder="Ar" />
        </label>
        <div className="md:col-span-2">
          <span className="block text-sm text-slate-700">Impôts concernés (aucun coché = tous)</span>
          <div className="mt-1 flex flex-wrap gap-3">
            {impots.map((impot) => (
              <label key={impot} className="flex items-center gap-2 text-sm text-slate-700">
                <input
                  type="checkbox"
                  className="h-4 w-4 accent-brand-blue"
                  checked={choisis.includes(impot)}
                  onChange={(e) => setChoisis(e.target.checked ? [...choisis, impot] : choisis.filter((x) => x !== impot))}
                />
                {impot}
              </label>
            ))}
          </div>
        </div>
        <div className="flex justify-end md:col-span-2">
          <button type="submit" className={primaryButtonClass} disabled={saving}>
            {saving ? "Ajout..." : "Ajouter"}
          </button>
        </div>
      </form>
    </details>
  );
}
