import { useCallback, useEffect, useState, type FormEvent } from "react";
import api, { getErrorMessage } from "../../services/api";
import type { Centre } from "../../types";
import {
  ConfirmDialog,
  DeleteButton,
  EditButton,
  ErrorBox,
  Field,
  Modal,
  inputClass,
  primaryButtonClass,
  secondaryButtonClass,
} from "../../components/ui";

interface CentreForm {
  nom: string;
  adresse: string;
}

const EMPTY_FORM: CentreForm = { nom: "", adresse: "" };

export default function CentresPage() {
  const [centres, setCentres] = useState<Centre[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string>("");

  const [editing, setEditing] = useState<Centre | "new" | null>(null);
  const [form, setForm] = useState<CentreForm>(EMPTY_FORM);
  const [formError, setFormError] = useState<string>("");
  const [saving, setSaving] = useState<boolean>(false);

  const [toDelete, setToDelete] = useState<Centre | null>(null);
  const [deleting, setDeleting] = useState<boolean>(false);

  const load = useCallback(async () => {
    try {
      const res = await api.get<Centre[]>("/centres");
      setCentres(res.data);
      setError("");
    } catch (err) {
      setError(getErrorMessage(err, "Impossible de charger les centres."));
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

  function openEdit(c: Centre) {
    setForm({ nom: c.nom, adresse: c.adresse ?? "" });
    setFormError("");
    setEditing(c);
  }

  async function handleSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setFormError("");
    setSaving(true);
    try {
      if (editing === "new") {
        await api.post("/centres", form);
      } else if (editing) {
        await api.put(`/centres/${editing.id}`, form);
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
      await api.delete(`/centres/${toDelete.id}`);
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
        <p className="text-sm text-gray-600">{centres.length} centre(s)</p>
        <button type="button" className={primaryButtonClass} onClick={openNew}>
          + Ajouter un centre
        </button>
      </div>

      <ErrorBox message={error} />

      <div className="bg-white rounded-2xl shadow overflow-x-auto">
        <table className="w-full text-sm">
          <thead className="bg-gray-50 text-left text-gray-600">
            <tr>
              <th className="px-4 py-3 font-semibold">Nom</th>
              <th className="px-4 py-3 font-semibold">Adresse</th>
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
            {!loading && centres.length === 0 && (
              <tr>
                <td colSpan={4} className="px-4 py-6 text-center text-gray-500">
                  Aucun centre. Ajoutez le premier.
                </td>
              </tr>
            )}
            {centres.map((c) => (
              <tr key={c.id}>
                <td className="px-4 py-3 font-medium text-brand-blue">{c.nom}</td>
                <td className="px-4 py-3 text-gray-600">{c.adresse ?? "—"}</td>
                <td className="px-4 py-3 text-gray-600">{c.users_count ?? 0}</td>
                <td className="px-4 py-3 text-right whitespace-nowrap">
                  <EditButton onClick={() => openEdit(c)} />
                  <DeleteButton onClick={() => setToDelete(c)} />
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {editing && (
        <Modal title={editing === "new" ? "Nouveau centre" : "Modifier le centre"} onClose={() => setEditing(null)}>
          <ErrorBox message={formError} />
          <form onSubmit={handleSubmit} className="space-y-4">
            <Field label="Nom du centre">
              <input
                className={inputClass}
                value={form.nom}
                onChange={(e) => setForm({ ...form, nom: e.target.value })}
                required
              />
            </Field>
            <Field label="Adresse">
              <input
                className={inputClass}
                value={form.adresse}
                onChange={(e) => setForm({ ...form, adresse: e.target.value })}
              />
            </Field>
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
          message={`Supprimer le centre « ${toDelete.nom} » ?`}
          loading={deleting}
          onConfirm={handleDelete}
          onCancel={() => setToDelete(null)}
        />
      )}
    </div>
  );
}
