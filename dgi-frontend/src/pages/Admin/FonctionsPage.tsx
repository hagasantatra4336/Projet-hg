import { useCallback, useEffect, useState, type FormEvent } from "react";
import api, { getErrorMessage } from "../../services/api";
import { ROLES, ROLE_LABELS, type Fonction, type Role } from "../../types";
import {
  ConfirmDialog,
  ErrorBox,
  Field,
  Modal,
  RoleBadge,
  inputClass,
  primaryButtonClass,
  secondaryButtonClass,
} from "../../components/ui";

interface FonctionForm {
  nom: string;
  role: Role;
}

const EMPTY_FORM: FonctionForm = { nom: "", role: "agent" };

const ROLE_HELP: Record<Role, string> = {
  agent: "Aucun accès à l'administration.",
  admin: "Gère les agents de son propre centre (souvent le chef de centre).",
  central: "Gère les centres et les utilisateurs de tous les centres (admins et agents).",
  superadmin: "Accès total : utilisateurs, centres, fonctions et rôles.",
};

export default function FonctionsPage() {
  const [fonctions, setFonctions] = useState<Fonction[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string>("");

  const [editing, setEditing] = useState<Fonction | "new" | null>(null);
  const [form, setForm] = useState<FonctionForm>(EMPTY_FORM);
  const [formError, setFormError] = useState<string>("");
  const [saving, setSaving] = useState<boolean>(false);

  const [toDelete, setToDelete] = useState<Fonction | null>(null);
  const [deleting, setDeleting] = useState<boolean>(false);

  const load = useCallback(async () => {
    try {
      const res = await api.get<Fonction[]>("/fonctions");
      setFonctions(res.data);
      setError("");
    } catch (err) {
      setError(getErrorMessage(err, "Impossible de charger les fonctions."));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  function openNew() {
    setForm(EMPTY_FORM);
    setFormError("");
    setEditing("new");
  }

  function openEdit(f: Fonction) {
    setForm({ nom: f.nom, role: f.role });
    setFormError("");
    setEditing(f);
  }

  async function handleSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setFormError("");
    setSaving(true);
    try {
      if (editing === "new") {
        await api.post("/fonctions", form);
      } else if (editing) {
        await api.put(`/fonctions/${editing.id}`, form);
      }
      setEditing(null);
      await load();
    } catch (err) {
      setFormError(getErrorMessage(err, "Enregistrement impossible."));
    } finally {
      setSaving(false);
    }
  }

  async function handleDelete() {
    if (!toDelete) return;
    setDeleting(true);
    try {
      await api.delete(`/fonctions/${toDelete.id}`);
      setToDelete(null);
      await load();
    } catch (err) {
      setToDelete(null);
      setError(getErrorMessage(err, "Suppression impossible."));
    } finally {
      setDeleting(false);
    }
  }

  return (
    <div>
      <div className="flex items-center justify-between mb-4">
        <p className="text-sm text-gray-600">
          Le rôle d'un utilisateur est déterminé par sa fonction.
        </p>
        <button type="button" className={primaryButtonClass} onClick={openNew}>
          + Ajouter une fonction
        </button>
      </div>

      <ErrorBox message={error} />

      <div className="bg-white rounded-2xl shadow overflow-x-auto">
        <table className="w-full text-sm">
          <thead className="bg-gray-50 text-left text-gray-600">
            <tr>
              <th className="px-4 py-3 font-semibold">Fonction</th>
              <th className="px-4 py-3 font-semibold">Rôle</th>
              <th className="px-4 py-3 font-semibold">Utilisateurs</th>
              <th className="px-4 py-3" />
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {loading && (
              <tr>
                <td colSpan={4} className="px-4 py-6 text-center text-gray-500">
                  Chargement...
                </td>
              </tr>
            )}
            {!loading && fonctions.length === 0 && (
              <tr>
                <td colSpan={4} className="px-4 py-6 text-center text-gray-500">
                  Aucune fonction.
                </td>
              </tr>
            )}
            {fonctions.map((f) => (
              <tr key={f.id}>
                <td className="px-4 py-3 font-medium text-brand-blue">{f.nom}</td>
                <td className="px-4 py-3">
                  <RoleBadge role={f.role} />
                </td>
                <td className="px-4 py-3 text-gray-600">{f.users_count ?? 0}</td>
                <td className="px-4 py-3 text-right whitespace-nowrap">
                  <button type="button" className="text-brand-blue hover:underline mr-4" onClick={() => openEdit(f)}>
                    Modifier
                  </button>
                  <button type="button" className="text-red-600 hover:underline" onClick={() => setToDelete(f)}>
                    Supprimer
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="bg-white rounded-2xl shadow p-5 mt-6">
        <h2 className="text-sm font-bold text-brand-blue mb-3">Droits de chaque rôle</h2>
        <ul className="space-y-2 text-sm text-gray-700">
          {ROLES.map((r) => (
            <li key={r} className="flex items-start gap-3">
              <span className="w-24 shrink-0">
                <RoleBadge role={r} />
              </span>
              <span>{ROLE_HELP[r]}</span>
            </li>
          ))}
        </ul>
      </div>

      {editing && (
        <Modal title={editing === "new" ? "Nouvelle fonction" : "Modifier la fonction"} onClose={() => setEditing(null)}>
          <ErrorBox message={formError} />
          <form onSubmit={handleSubmit} className="space-y-4">
            <Field label="Nom de la fonction">
              <input
                className={inputClass}
                value={form.nom}
                onChange={(e) => setForm({ ...form, nom: e.target.value })}
                required
              />
            </Field>
            <Field label="Rôle associé">
              <select
                className={inputClass}
                value={form.role}
                onChange={(e) => setForm({ ...form, role: e.target.value as Role })}
              >
                {ROLES.map((r) => (
                  <option key={r} value={r}>
                    {ROLE_LABELS[r]}
                  </option>
                ))}
              </select>
            </Field>
            <p className="text-xs text-gray-500">{ROLE_HELP[form.role]}</p>
            <div className="flex justify-end gap-3 pt-2">
              <button type="button" className={secondaryButtonClass} onClick={() => setEditing(null)}>
                Annuler
              </button>
              <button type="submit" className={primaryButtonClass} disabled={saving}>
                {saving ? "Enregistrement..." : "Enregistrer"}
              </button>
            </div>
          </form>
        </Modal>
      )}

      {toDelete && (
        <ConfirmDialog
          message={`Supprimer la fonction « ${toDelete.nom} » ?`}
          loading={deleting}
          onConfirm={handleDelete}
          onCancel={() => setToDelete(null)}
        />
      )}
    </div>
  );
}
