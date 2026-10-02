import { useEffect, useState, type FormEvent, type ReactNode } from "react";
import api, { getErrorMessage } from "../services/api";
import { useAuth } from "../context/AuthContext";
import {
  ErrorBox,
  Field,
  PasswordInput,
  RoleBadge,
  inputClass,
  primaryButtonClass,
} from "../components/ui";

interface ProfileForm {
  nom: string;
  telephone: string;
  adresse: string;
}

interface PasswordForm {
  current_password: string;
  password: string;
  password_confirmation: string;
}

interface RequestChangeResponse {
  message: string;
  email: string;
  debug_confirm_url?: string; // renvoyé par l'API uniquement en mode développement (APP_DEBUG=true)
}

const EMPTY_PASSWORDS: PasswordForm = { current_password: "", password: "", password_confirmation: "" };

function ReadOnly({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div>
      <span className="block text-sm font-medium text-brand-blue mb-1">{label}</span>
      <div className="rounded-lg bg-gray-50 border border-gray-200 px-3 py-2 text-gray-700 min-h-[2.5rem]">
        {children}
      </div>
    </div>
  );
}

export default function ProfilePage() {
  const { user, refreshUser } = useAuth();

  const [form, setForm] = useState<ProfileForm>({
    nom: user?.nom ?? "",
    telephone: user?.telephone ?? "",
    adresse: user?.adresse ?? "",
  });
  const [passwords, setPasswords] = useState<PasswordForm>(EMPTY_PASSWORDS);
  const [error, setError] = useState<string>("");
  const [sentTo, setSentTo] = useState<string>("");
  const [debugUrl, setDebugUrl] = useState<string>("");
  const [loading, setLoading] = useState<boolean>(false);

  // Quand les valeurs enregistrées changent (après confirmation), le formulaire suit.
  useEffect(() => {
    setForm({
      nom: user?.nom ?? "",
      telephone: user?.telephone ?? "",
      adresse: user?.adresse ?? "",
    });
  }, [user?.nom, user?.telephone, user?.adresse]);

  // L'utilisateur confirme dans sa boîte mail (souvent un autre onglet) puis revient ici :
  // on recharge alors ses informations pour afficher les nouvelles valeurs.
  useEffect(() => {
    if (!sentTo) return;
    const onFocus = () => void refreshUser();
    window.addEventListener("focus", onFocus);
    return () => window.removeEventListener("focus", onFocus);
  }, [sentTo, refreshUser]);

  if (!user) return null;

  async function handleSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setError("");
    setSentTo("");
    setDebugUrl("");
    setLoading(true);

    const payload: Record<string, string> = { ...form };
    if (passwords.password || passwords.current_password || passwords.password_confirmation) {
      Object.assign(payload, passwords);
    }

    try {
      const res = await api.post<RequestChangeResponse>("/profile/request-change", payload);
      setSentTo(res.data.email);
      setDebugUrl(res.data.debug_confirm_url ?? "");
      setPasswords(EMPTY_PASSWORDS);
    } catch (err) {
      setError(getErrorMessage(err, "Impossible d'envoyer la demande."));
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="max-w-2xl">
      <h1 className="text-2xl font-bold text-brand-blue mb-1">Mon profil</h1>
      <p className="text-sm text-gray-600 mb-6">
        Les modifications ne sont appliquées qu'après confirmation par un lien envoyé à votre adresse e-mail.
      </p>

      <ErrorBox message={error} />

      {sentTo && (
        <div className="text-sm text-brand-blue bg-brand-green/20 border border-brand-green rounded-lg px-3 py-2 mb-4">
          <p>
            Un e-mail de confirmation a été envoyé à <span className="font-semibold">{sentTo}</span>. Cliquez sur le
            lien qu'il contient (valable 30 minutes) pour appliquer vos modifications.
          </p>
          {debugUrl && (
            <p className="mt-2 break-all">
              Mode développement — lien :{" "}
              <a href={debugUrl} className="underline">
                {debugUrl}
              </a>
            </p>
          )}
        </div>
      )}

      <form onSubmit={handleSubmit} className="space-y-6">
        <section className="bg-white rounded-2xl shadow p-6 space-y-4">
          <h2 className="font-bold text-brand-blue">Mes informations</h2>
          <Field label="Nom">
            <input
              className={inputClass}
              value={form.nom}
              onChange={(e) => setForm({ ...form, nom: e.target.value })}
              required
            />
          </Field>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <Field label="Téléphone">
              <input
                className={inputClass}
                value={form.telephone}
                onChange={(e) => setForm({ ...form, telephone: e.target.value })}
              />
            </Field>
            <Field label="Adresse">
              <input
                className={inputClass}
                value={form.adresse}
                onChange={(e) => setForm({ ...form, adresse: e.target.value })}
              />
            </Field>
          </div>
        </section>

        <section className="bg-white rounded-2xl shadow p-6 space-y-4">
          <div>
            <h2 className="font-bold text-brand-blue">Changer mon mot de passe</h2>
            <p className="text-xs text-gray-500">Laissez vide pour conserver votre mot de passe actuel.</p>
          </div>
          <Field label="Mot de passe actuel">
            <PasswordInput
              className={inputClass}
              value={passwords.current_password}
              onChange={(e) => setPasswords({ ...passwords, current_password: e.target.value })}
              autoComplete="current-password"
            />
          </Field>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <Field label="Nouveau mot de passe">
              <PasswordInput
                className={inputClass}
                value={passwords.password}
                onChange={(e) => setPasswords({ ...passwords, password: e.target.value })}
                minLength={8}
                autoComplete="new-password"
              />
            </Field>
            <Field label="Confirmer le nouveau mot de passe">
              <PasswordInput
                className={inputClass}
                value={passwords.password_confirmation}
                onChange={(e) => setPasswords({ ...passwords, password_confirmation: e.target.value })}
                minLength={8}
                autoComplete="new-password"
              />
            </Field>
          </div>
        </section>

        <section className="bg-white rounded-2xl shadow p-6">
          <h2 className="font-bold text-brand-blue mb-1">Informations gérées par un responsable</h2>
          <p className="text-xs text-gray-500 mb-4">
            Pour modifier l'e-mail, l'IM, le centre ou la fonction, contactez un administrateur.
          </p>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <ReadOnly label="E-mail">{user.email}</ReadOnly>
            <ReadOnly label="IM">{user.im ?? "—"}</ReadOnly>
            <ReadOnly label="Centre">{user.centre?.nom ?? "—"}</ReadOnly>
            <ReadOnly label="Fonction">
              <span className="mr-2">{user.fonction?.nom ?? "—"}</span>
              <RoleBadge role={user.role} />
            </ReadOnly>
          </div>
        </section>

        <div className="flex justify-end">
          <button type="submit" className={primaryButtonClass} disabled={loading}>
            {loading ? "Envoi..." : "Enregistrer et demander la confirmation"}
          </button>
        </div>
      </form>
    </div>
  );
}
