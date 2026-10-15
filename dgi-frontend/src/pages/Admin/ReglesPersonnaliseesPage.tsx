import { useCallback, useEffect, useState, type FormEvent } from "react";
import { Link } from "react-router-dom";
import api, { getErrorMessage } from "../../services/api";
import type { GroupeConditions, ModeleRegle, OptionsReglesPerso, ReglePerso, ResultatSimulation } from "../../typesReglesPerso";
import { ACTION_LABELS, NIVEAU_REGLE_LABELS, STATUT_REGLE_LABELS } from "../../typesReglesPerso";
import { ErrorBox, inputClass, primaryButtonClass, secondaryButtonClass } from "../../components/ui";
import { formatDateHeure } from "../../components/defaillance";
import ConditionsEditor, { compter, decrire } from "./reglesPerso/ConditionsEditor";
import IndicateursSection from "./reglesPerso/IndicateursSection";

type Valeurs = Record<string, string | number>;

const badge: Record<string, string> = {
  brouillon: "bg-slate-100 text-slate-700",
  active: "bg-leaf-100 text-brand-blue",
  desactivee: "bg-orange-100 text-orange-800",
};

function ImpotsChoix({ options, choisis, onChange }: { options: string[]; choisis: string[]; onChange: (v: string[]) => void }) {
  return (
    <div className="flex flex-wrap gap-3">
      {options.map((impot) => (
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
  );
}

// ---------------------------------------------------------------- création

function NouvelleRegle({ options, onCree }: { options: OptionsReglesPerso; onCree: (r: ReglePerso) => void }) {
  const [modele, setModele] = useState<ModeleRegle>(options.modeles[0]);
  const [nom, setNom] = useState<string>("");
  const [valeurs, setValeurs] = useState<Valeurs>({});
  const [impots, setImpots] = useState<string[]>([]);
  const [niveau, setNiveau] = useState<string>(options.modeles[0].niveau_defaut);
  const [saving, setSaving] = useState<boolean>(false);
  const [error, setError] = useState<string>("");

  // Valeurs par défaut du modèle choisi (premier indicateur, seuils proposés)
  useEffect(() => {
    const v: Valeurs = {};
    for (const p of modele.parametres) {
      if (p.type === "indicateur") v[p.cle] = options.indicateurs[0]?.code ?? "";
      else if (p.defaut !== undefined) v[p.cle] = p.defaut;
    }
    setValeurs(v);
    setNiveau(modele.niveau_defaut);
  }, [modele, options.indicateurs]);

  async function creer(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError("");
    try {
      const res = await api.post<ReglePerso>("/regles-personnalisees", { modele: modele.code, nom, parametres: valeurs, impots, niveau });
      onCree(res.data);
      setNom("");
    } catch (err) {
      setError(getErrorMessage(err, "Création impossible."));
    } finally {
      setSaving(false);
    }
  }

  return (
    <section className="rounded-xl bg-white p-5 border border-line sm:p-6">
      <h2 className="text-lg font-semibold text-brand-blue">Nouvelle règle à partir d'un modèle</h2>
      <p className="mt-1 text-sm text-slate-600">La règle est créée en brouillon : elle ne produit aucune alerte tant qu'elle n'est pas simulée puis activée. Vous pourrez ensuite modifier ses conditions.</p>

      <form onSubmit={creer} className="mt-4 grid gap-4 md:grid-cols-2">
        <label className="block text-sm text-slate-700 md:col-span-2">
          Modèle
          <select className={inputClass} value={modele.code} onChange={(e) => setModele(options.modeles.find((m) => m.code === e.target.value) ?? options.modeles[0])}>
            {options.modeles.map((m) => (
              <option key={m.code} value={m.code}>
                {m.nom}
              </option>
            ))}
          </select>
          <span className="mt-1 block text-xs text-slate-500">{modele.description}</span>
        </label>

        <label className="block text-sm text-slate-700 md:col-span-2">
          Nom de la règle
          <input className={inputClass} value={nom} onChange={(e) => setNom(e.target.value)} required maxLength={150} />
        </label>

        {modele.parametres.map((p) => {
          const libelle: Record<string, string> = {
            indicateur: "Indicateur",
            indicateur_b: "Second indicateur",
            seuil_pct: "Seuil (%)",
            valeur: "Valeur",
            comparateur: "Comparateur",
          };
          return (
            <label key={p.cle} className="block text-sm text-slate-700">
              {libelle[p.cle] ?? p.cle}
              {p.type === "indicateur" ? (
                <select className={inputClass} value={String(valeurs[p.cle] ?? "")} onChange={(e) => setValeurs((v) => ({ ...v, [p.cle]: e.target.value }))}>
                  {options.indicateurs.map((i) => (
                    <option key={i.code} value={i.code}>
                      {i.libelle}
                    </option>
                  ))}
                </select>
              ) : p.type === "choix" ? (
                <select className={inputClass} value={String(valeurs[p.cle] ?? "")} onChange={(e) => setValeurs((v) => ({ ...v, [p.cle]: e.target.value }))}>
                  {(p.options ?? []).map((o) => (
                    <option key={o} value={o}>
                      {o}
                    </option>
                  ))}
                </select>
              ) : (
                <input
                  type="number"
                  step="any"
                  className={inputClass}
                  min={p.min}
                  max={p.max}
                  required
                  value={String(valeurs[p.cle] ?? "")}
                  onChange={(e) => setValeurs((v) => ({ ...v, [p.cle]: e.target.value === "" ? "" : Number(e.target.value) }))}
                />
              )}
            </label>
          );
        })}

        <div className="md:col-span-2">
          <span className="mb-1 block text-sm text-slate-700">Impôts contrôlés</span>
          <ImpotsChoix options={options.impots} choisis={impots} onChange={setImpots} />
        </div>

        <label className="block text-sm text-slate-700">
          Niveau de risque des alertes
          <select className={inputClass} value={niveau} onChange={(e) => setNiveau(e.target.value)}>
            {options.niveaux.map((n) => (
              <option key={n} value={n}>
                {NIVEAU_REGLE_LABELS[n] ?? n}
              </option>
            ))}
          </select>
        </label>

        <div className="md:col-span-2">
          <ErrorBox message={error} />
        </div>
        <div className="flex justify-end md:col-span-2">
          <button type="submit" className={primaryButtonClass} disabled={saving || impots.length === 0}>
            {saving ? "Création..." : "Créer le brouillon"}
          </button>
        </div>
      </form>
    </section>
  );
}

// ---------------------------------------------------------------- simulation

function Simulation({ r }: { r: ReglePerso }) {
  const s = r.simulation;
  if (!s) return <p className="text-sm text-slate-500">Aucune simulation pour l'instant.</p>;

  const res: ResultatSimulation | null = s.resultats;

  return (
    <div className="space-y-3 text-sm text-slate-700">
      <p className="font-medium text-brand-blue">
        Simulation du {s.date ? formatDateHeure(s.date) : ""} (version {s.version}, données au {s.date_analyse})
        {!s.a_jour && <span className="ml-2 text-orange-800">— règle modifiée depuis : à refaire</span>}
      </p>
      {s.statut === "erreur" && <p className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-red-700">Simulation en erreur : {s.erreur}</p>}
      {res && (
        <>
          <p>
            <strong>{res.alertes}</strong> alerte{res.alertes > 1 ? "s" : ""} sur <strong>{res.analyses}</strong> déclaration{res.analyses > 1 ? "s" : ""} analysée{res.analyses > 1 ? "s" : ""}.
            {res.valeurs_absentes > 0 && ` ${res.valeurs_absentes} déclaration(s) ont au moins une valeur utilisée non renseignée (condition non vérifiable).`}
          </p>
          <p className="text-xs text-slate-600">
            Par niveau : {Object.entries(res.par_niveau).map(([k, v]) => `${NIVEAU_REGLE_LABELS[k] ?? k} ${v}`).join(", ") || "aucun"} · Par impôt :{" "}
            {Object.entries(res.par_impot).map(([k, v]) => `${k} ${v}`).join(", ") || "aucun"}
          </p>
          {res.echantillon.length > 0 && (
            <details>
              <summary className="cursor-pointer text-sm font-medium text-brand-blue">Échantillon ({res.echantillon.length} premières alertes)</summary>
              <ul className="mt-2 max-h-72 space-y-1 overflow-y-auto text-xs">
                {res.echantillon.map((a, i) => (
                  <li key={i}>
                    Contribuable n° {a.contribuable_id} : {a.motif}
                  </li>
                ))}
              </ul>
            </details>
          )}
        </>
      )}
    </div>
  );
}

// ---------------------------------------------------------------- carte d'une règle

function RegleCard({ regle, options, onChange, onSupprimee }: { regle: ReglePerso; options: OptionsReglesPerso; onChange: (r: ReglePerso) => void; onSupprimee: (id: number) => void }) {
  const [edition, setEdition] = useState<boolean>(false);
  const [nom, setNom] = useState<string>(regle.nom);
  const [niveau, setNiveau] = useState<string>(regle.niveau);
  const [impots, setImpots] = useState<string[]>(regle.impots);
  const [fenetre, setFenetre] = useState<number>(regle.fenetre_annees);
  const [conditions, setConditions] = useState<GroupeConditions>(regle.conditions);
  const [busy, setBusy] = useState<string>("");
  const [error, setError] = useState<string>("");
  const [message, setMessage] = useState<string>("");

  useEffect(() => {
    setNom(regle.nom);
    setNiveau(regle.niveau);
    setImpots(regle.impots);
    setFenetre(regle.fenetre_annees);
    setConditions(regle.conditions);
  }, [regle]);

  async function action(nomAction: string, requete: () => Promise<ReglePerso | null>, ok: string) {
    setBusy(nomAction);
    setError("");
    setMessage("");
    try {
      const r = await requete();
      if (r) onChange(r);
      setMessage(ok);
      return true;
    } catch (err) {
      setError(getErrorMessage(err, "Opération impossible."));
      return false;
    } finally {
      setBusy("");
    }
  }

  const id = regle.id;
  const charger = () => action("detail", async () => (await api.get<ReglePerso>(`/regles-personnalisees/${id}`)).data, "");
  const simuler = () => action("simuler", async () => (await api.post<ReglePerso>(`/regles-personnalisees/${id}/simuler`, {})).data, "Simulation terminée : aucune alerte n'a été créée.");
  const activer = () => action("activer", async () => (await api.post<ReglePerso>(`/regles-personnalisees/${id}/activer`)).data, "Règle activée : elle s'applique à la prochaine analyse.");
  const desactiver = () => {
    if (!window.confirm("Désactiver cette règle ? Ses alertes seront masquées (pas supprimées) et plus aucune ne sera créée.")) return;
    action("desactiver", async () => (await api.post<ReglePerso>(`/regles-personnalisees/${id}/desactiver`)).data, "Règle désactivée.");
  };
  const supprimer = async () => {
    if (!window.confirm("Supprimer définitivement ce brouillon ?")) return;
    const ok = await action("supprimer", async () => {
      await api.delete(`/regles-personnalisees/${id}`);
      return null;
    }, "");
    if (ok) onSupprimee(id);
  };
  async function enregistrer(e: FormEvent) {
    e.preventDefault();
    const ok = await action(
      "enregistrer",
      async () => (await api.put<ReglePerso>(`/regles-personnalisees/${id}`, { nom, niveau, impots, fenetre_annees: fenetre, conditions })).data,
      "Règle enregistrée en brouillon : simulez-la à nouveau avant de l'activer."
    );
    if (ok) setEdition(false);
  }

  const modifiable = regle.statut !== "active";
  const peutSupprimer = modifiable && regle.nb_alertes === 0;

  return (
    <section className="rounded-xl bg-white p-5 border border-line sm:p-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0 flex-1">
          <h3 className="text-lg font-semibold text-brand-blue">{regle.nom}</h3>
          <p className="mt-1 text-sm text-slate-600">{decrire(regle.conditions, options.indicateurs)}</p>
          <p className="mt-1 text-xs text-slate-500">
            {regle.impots.join(", ")} · risque {NIVEAU_REGLE_LABELS[regle.niveau] ?? regle.niveau} · version {regle.version} · {regle.nb_alertes} alerte{regle.nb_alertes > 1 ? "s" : ""} produite{regle.nb_alertes > 1 ? "s" : ""}
            {regle.modifie_par && regle.modifie_le ? ` · modifiée par ${regle.modifie_par} le ${formatDateHeure(regle.modifie_le)}` : ""}
          </p>
        </div>
        <span className={`rounded px-2 py-0.5 text-xs font-semibold ${badge[regle.statut]}`}>{STATUT_REGLE_LABELS[regle.statut]}</span>
      </div>

      {edition && (
        <form onSubmit={enregistrer} className="mt-5 space-y-4">
          <label className="block text-sm text-slate-700">
            Nom
            <input className={inputClass} value={nom} onChange={(e) => setNom(e.target.value)} required maxLength={150} />
          </label>
          <ConditionsEditor groupe={conditions} options={options} onChange={setConditions} total={compter(conditions)} />
          <div>
            <span className="mb-1 block text-sm text-slate-700">Impôts contrôlés</span>
            <ImpotsChoix options={options.impots} choisis={impots} onChange={setImpots} />
          </div>
          <div className="grid gap-4 md:grid-cols-2">
            <label className="block text-sm text-slate-700">
              Niveau de risque
              <select className={inputClass} value={niveau} onChange={(e) => setNiveau(e.target.value)}>
                {options.niveaux.map((n) => (
                  <option key={n} value={n}>
                    {NIVEAU_REGLE_LABELS[n] ?? n}
                  </option>
                ))}
              </select>
            </label>
            <label className="block text-sm text-slate-700">
              Exercices analysés en plus de l'année en cours
              <input type="number" min={0} max={5} className={inputClass} value={fenetre} onChange={(e) => setFenetre(Number(e.target.value))} />
            </label>
          </div>
          <div className="flex justify-end gap-3">
            <button type="button" className={secondaryButtonClass} onClick={() => setEdition(false)}>
              Annuler
            </button>
            <button type="submit" className={primaryButtonClass} disabled={busy !== ""}>
              {busy === "enregistrer" ? "Enregistrement..." : "Enregistrer"}
            </button>
          </div>
        </form>
      )}

      <div className="mt-4">
        <ErrorBox message={error} />
        {message && <p className="mb-3 rounded-lg border border-brand-green/60 bg-leaf-50 px-3 py-2 text-sm text-brand-blue">{message}</p>}
      </div>

      <div className="flex flex-wrap justify-end gap-3">
        {modifiable && !edition && (
          <button type="button" className={secondaryButtonClass} onClick={() => setEdition(true)}>
            Modifier
          </button>
        )}
        {peutSupprimer && (
          <button type="button" className={secondaryButtonClass} onClick={supprimer} disabled={busy !== ""}>
            Supprimer
          </button>
        )}
        <button type="button" className={secondaryButtonClass} onClick={simuler} disabled={busy !== ""}>
          {busy === "simuler" ? "Simulation..." : "Simuler"}
        </button>
        {regle.statut === "active" ? (
          <button type="button" className={secondaryButtonClass} onClick={desactiver} disabled={busy !== ""}>
            Désactiver
          </button>
        ) : (
          <button type="button" className={primaryButtonClass} onClick={activer} disabled={busy !== "" || !regle.simulation_valide} title={regle.simulation_valide ? "" : "Simulez d'abord la règle dans sa version actuelle"}>
            Activer
          </button>
        )}
      </div>

      <details className="mt-5 border-t border-line pt-4" onToggle={(e) => { if ((e.currentTarget as HTMLDetailsElement).open && !regle.historique) charger(); }}>
        <summary className="cursor-pointer text-sm font-medium text-brand-blue">Résultat de simulation et historique</summary>
        <div className="mt-3 space-y-4">
          {regle.historique ? (
            <>
              <Simulation r={regle} />
              <ul className="space-y-1">
                {regle.historique.map((h) => (
                  <li key={h.id} className="text-xs text-slate-700">
                    <span className="font-medium">{h.date ? formatDateHeure(h.date) : ""}</span> · {h.par ?? "Utilisateur supprimé"} : {ACTION_LABELS[h.action] ?? h.action}
                  </li>
                ))}
              </ul>
            </>
          ) : (
            <p className="text-sm text-slate-500">Chargement...</p>
          )}
        </div>
      </details>
    </section>
  );
}

// ---------------------------------------------------------------- page

export default function ReglesPersonnaliseesPage() {
  const [options, setOptions] = useState<OptionsReglesPerso | null>(null);
  const [regles, setRegles] = useState<ReglePerso[] | null>(null);
  const [error, setError] = useState<string>("");

  const chargerOptions = useCallback(() => {
    api
      .get<OptionsReglesPerso>("/regles-personnalisees/options")
      .then((res) => setOptions(res.data))
      .catch((err) => setError(getErrorMessage(err, "Impossible de charger les options.")));
  }, []);

  useEffect(() => {
    chargerOptions();
    api
      .get<{ regles: ReglePerso[] }>("/regles-personnalisees")
      .then((res) => setRegles(res.data.regles))
      .catch((err) => setError(getErrorMessage(err, "Impossible de charger les règles.")));
  }, [chargerOptions]);

  return (
    <div className="space-y-5">
      <Link to="/admin/regles" className="inline-block text-sm font-medium text-brand-blue underline">
        ← Retour aux règles de détection
      </Link>
      <p className="max-w-3xl text-sm text-slate-600">
        Créez vos propres règles à partir d'un modèle, avec des conditions combinables. Une règle suit trois étapes : <strong>brouillon</strong> (aucune alerte),
        <strong> simulation</strong> (voir ce qu'elle donnerait, sans rien créer), puis <strong>activation</strong>. Modifier une règle la remet en brouillon.
      </p>

      <ErrorBox message={error} />
      {!options && !error && <p className="text-sm text-slate-500">Chargement...</p>}

      {options && (
        <>
          <IndicateursSection impots={options.impots} onChange={chargerOptions} />
          <NouvelleRegle options={options} onCree={(r) => setRegles((l) => [r, ...(l ?? [])])} />
          {regles?.length === 0 && <p className="text-sm text-slate-500">Aucune règle personnalisée pour l'instant.</p>}
          {regles?.map((r) => (
            <RegleCard
              key={r.id}
              regle={r}
              options={options}
              onChange={(maj) => setRegles((l) => (l ?? []).map((x) => (x.id === maj.id ? maj : x)))}
              onSupprimee={(id) => setRegles((l) => (l ?? []).filter((x) => x.id !== id))}
            />
          ))}
        </>
      )}
    </div>
  );
}
