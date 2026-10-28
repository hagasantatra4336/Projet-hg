import { useEffect, useRef, useState, type FormEvent, type ReactNode } from "react";
import { useLocation, useNavigate } from "react-router-dom";
import api, { getErrorMessage } from "../services/api";
import { useAuth } from "../context/AuthContext";
import {
  ErrorBox,
  Field,
  FormActions,
  FormGrid,
  Modal,
  PasswordInput,
  RoleBadge,
  inputClass,
  primaryButtonClass,
  secondaryButtonClass,
} from "../components/ui";
import CentreSelect, { type CentreOption } from "../components/CentreSelect";
import { savePendingRequest } from "../services/profileRequest";

interface ProfileForm {
  nom: string;
  telephone: string;
  adresse: string;
  centre_id: string;
}

/** Nouveau mot de passe : le mot de passe actuel n'est plus affiché, il est demandé au moment de confirmer. */
interface PasswordForm {
  password: string;
  password_confirmation: string;
}

interface RequestChangeResponse {
  message: string;
  email: string;
  request_id: string;
  champs: string[];
  expires_at: string;
  debug_confirm_url?: string; // renvoyé par l'API uniquement en mode développement (APP_DEBUG=true)
}

const EMPTY_PASSWORDS: PasswordForm = { password: "", password_confirmation: "" };

/** Initiales affichées dans l'avatar (deux premières lettres du nom). */
function initials(nom: string): string {
  const parts = nom.trim().split(/\s+/).filter(Boolean);
  if (parts.length === 0) return "?";
  const letters = parts.length === 1 ? parts[0].slice(0, 2) : parts[0][0] + parts[parts.length - 1][0];
  return letters.toUpperCase();
}

function LockIcon() {
  return (
    <svg
      xmlns="http://www.w3.org/2000/svg"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={1.8}
      strokeLinecap="round"
      strokeLinejoin="round"
      className="h-4 w-4 shrink-0 text-slate-400"
      aria-hidden="true"
    >
      <rect x="4" y="11" width="16" height="10" rx="2" />
      <path d="M8 11V7a4 4 0 0 1 8 0v4" />
    </svg>
  );
}

/** Ligne « libellé / valeur » non modifiable, avec un cadenas à droite. */
function InfoRow({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex items-center justify-between gap-3 py-2.5">
      <div className="min-w-0">
        <dt className="text-xs font-medium text-slate-500">{label}</dt>
        <dd className="break-words text-sm font-medium text-brand-blue">{children}</dd>
      </div>
      <LockIcon />
    </div>
  );
}

/** Carte blanche avec titre et filet de séparation. */
function Section({ title, hint, children }: { title: string; hint?: string; children: ReactNode }) {
  return (
    <section className="rounded-xl border border-line bg-white p-6">
      <div className="mb-4 border-b border-line pb-3">
        <h2 className="font-semibold text-brand-blue">{title}</h2>
        {hint && <p className="mt-0.5 text-xs text-slate-500">{hint}</p>}
      </div>
      {children}
    </section>
  );
}

export default function ProfilePage() {
  const { user } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();

  const [form, setForm] = useState<ProfileForm>({
    nom: user?.nom ?? "",
    telephone: user?.telephone ?? "",
    adresse: user?.adresse ?? "",
    centre_id: user?.centre_id ? String(user.centre_id) : "",
  });
  const [passwords, setPasswords] = useState<PasswordForm>(EMPTY_PASSWORDS);
  // Le bloc « mot de passe » reste replié : l'utilisateur l'ouvre seulement s'il veut le changer.
  const [motDePasseOuvert, setMotDePasseOuvert] = useState<boolean>(false);
  // Fenêtre de confirmation : demande le mot de passe actuel avant d'envoyer la demande.
  const [confirmation, setConfirmation] = useState<{ motDePasse: string; erreur: string } | null>(null);
  const [centres, setCentres] = useState<CentreOption[]>([]);
  const [error, setError] = useState<string>("");
  const [loading, setLoading] = useState<boolean>(false);
  // Arrivée depuis la page d'attente après validation de l'e-mail : on affiche une confirmation.
  const [updated, setUpdated] = useState<boolean>(
    () => (location.state as { updated?: boolean } | null)?.updated === true
  );

  // Liste des centres (route publique de l'inscription)
  useEffect(() => {
    api
      .get<{ centres: CentreOption[] }>("/signup-options")
      .then((res) => setCentres(res.data.centres))
      .catch((err) => setError(getErrorMessage(err, "Impossible de charger la liste des centres.")));
  }, []);

  // Quand les valeurs enregistrées changent (après confirmation), le formulaire suit.
  useEffect(() => {
    setForm({
      nom: user?.nom ?? "",
      telephone: user?.telephone ?? "",
      adresse: user?.adresse ?? "",
      centre_id: user?.centre_id ? String(user.centre_id) : "",
    });
  }, [user?.nom, user?.telephone, user?.adresse, user?.centre_id]);

  // Le message disparaît tout seul et ne réapparaît pas si on recharge la page (l'état de navigation est effacé une fois).
  const etatEfface = useRef<boolean>(false);
  useEffect(() => {
    if (!updated) return;
    if (!etatEfface.current) {
      etatEfface.current = true;
      navigate(location.pathname, { replace: true, state: null });
    }
    const timer = window.setTimeout(() => setUpdated(false), 8000);
    return () => window.clearTimeout(timer);
  }, [updated, navigate, location.pathname]);

  if (!user) return null;

  // Seuls les profils centraux peuvent se rattacher à un autre centre : pour un agent ou un chef
  // de centre, ce changement relève d'un responsable (même règle vérifiée côté serveur).
  const peutChangerCentre = user.role === "central" || user.role === "superadmin";

  // Le centre actuel reste sélectionnable même s'il manquait dans la liste chargée.
  const centreOptions =
    user.centre && !centres.some((c) => c.id === user.centre!.id) ? [...centres, user.centre] : centres;

  // Étape 1 : on vérifie le formulaire puis on demande le mot de passe actuel.
  function handleSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setError("");
    if (passwords.password && passwords.password !== passwords.password_confirmation) {
      setError("Les deux mots de passe ne correspondent pas.");
      return;
    }
    setConfirmation({ motDePasse: "", erreur: "" });
  }

  // Étape 2 : mot de passe actuel saisi, envoi de la demande (un e-mail de confirmation suivra).
  async function confirmerEnvoi(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (!confirmation) return;

    if (!confirmation.motDePasse) {
      setConfirmation({ ...confirmation, erreur: "Saisissez votre mot de passe actuel." });
      return;
    }

    setLoading(true);
    setError("");

    const payload: Record<string, string | number | null> = {
      nom: form.nom,
      telephone: form.telephone,
      adresse: form.adresse,
      current_password: confirmation.motDePasse,
    };
    if (peutChangerCentre) payload.centre_id = form.centre_id ? Number(form.centre_id) : null;
    if (passwords.password) {
      payload.password = passwords.password;
      payload.password_confirmation = passwords.password_confirmation;
    }

    try {
      const res = await api.post<RequestChangeResponse>("/profile/request-change", payload);
      // Page d'attente : elle suit la demande et affiche les nouvelles données dès que l'e-mail est validé.
      savePendingRequest({
        request_id: res.data.request_id,
        email: res.data.email,
        champs: res.data.champs,
        expires_at: res.data.expires_at,
        debug_confirm_url: res.data.debug_confirm_url,
      });
      setPasswords(EMPTY_PASSWORDS);
      navigate("/profile/attente");
    } catch (err) {
      const message = getErrorMessage(err, "Impossible d'envoyer la demande.");
      // Erreur de mot de passe : on la garde dans la fenêtre pour que l'utilisateur corrige sur place.
      if (message.toLowerCase().includes("mot de passe actuel")) {
        setConfirmation({ ...confirmation, erreur: message });
      } else {
        setConfirmation(null);
        setError(message);
      }
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="mx-auto w-full max-w-5xl">
      {/* Titre aligné à gauche, sur toute la largeur */}
      <div className="mb-6 border-b border-line pb-4">
        <h1 className="text-2xl font-semibold tracking-tight text-brand-blue">Mon profil</h1>
      </div>

      <ErrorBox message={error} />

      {updated && (
        <div
          role="status"
          className="mb-6 rounded-lg border border-brand-blue/30 bg-brand-blue/5 px-4 py-3 text-sm font-medium text-brand-blue"
        >
          Vos modifications ont été appliquées.
        </div>
      )}

      {/* Deux colonnes : identité à gauche, formulaires à droite (une seule colonne sur mobile) */}
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,19rem)_minmax(0,1fr)] lg:items-start">
        <aside className="space-y-6">
          <div className="overflow-hidden rounded-xl border border-line bg-white">
            <div className="flex h-14 flex-col justify-end bg-brand-blue">
              <div className="h-1 bg-brand-gold" />
            </div>
            <div className="px-6 pb-6">
              <div className="relative z-10 -mt-8 flex h-16 w-16 items-center justify-center rounded-full bg-brand-blue text-xl font-semibold text-white ring-4 ring-white">
                {initials(user.nom)}
              </div>
              <h2 className="mt-3 break-words text-lg font-semibold text-brand-blue">{user.nom}</h2>
              <div className="mt-3 flex flex-wrap items-center gap-2">
                <RoleBadge role={user.role} />
                {user.fonction && (
                  <span className="rounded-full bg-brand-blue/10 px-2.5 py-0.5 text-xs font-semibold text-brand-blue">
                    {user.fonction.nom}
                  </span>
                )}
                {user.centre && (
                  <span className="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-700">
                    {user.centre.nom}
                  </span>
                )}
              </div>
            </div>
          </div>

          <Section title="Informations du compte" hint="L'IM, l'e-mail et la fonction sont gérés par un responsable.">
            <dl className="-my-1 divide-y divide-line">
              <InfoRow label="IM (matricule)">{user.im ?? "—"}</InfoRow>
              <InfoRow label="E-mail">{user.email}</InfoRow>
              <InfoRow label="Fonction">{user.fonction?.nom ?? "—"}</InfoRow>
            </dl>
          </Section>
        </aside>

        <form onSubmit={handleSubmit} className="space-y-6">
          <Section title="Mes informations">
            <FormGrid>
              <Field label="Nom" required>
                <input
                  className={inputClass}
                  value={form.nom}
                  onChange={(e) => setForm({ ...form, nom: e.target.value })}
                  autoComplete="name"
                  required
                />
              </Field>
              <Field label="Téléphone" optional>
                <input
                  className={inputClass}
                  value={form.telephone}
                  onChange={(e) => setForm({ ...form, telephone: e.target.value })}
                  autoComplete="tel"
                />
              </Field>
              <Field label="Adresse" optional>
                <input
                  className={inputClass}
                  value={form.adresse}
                  onChange={(e) => setForm({ ...form, adresse: e.target.value })}
                  autoComplete="street-address"
                />
              </Field>
              <div>
                <span className="mb-1 flex items-baseline gap-1 text-sm font-medium text-slate-700">
                  <span>Centre</span>
                  <span className="text-xs font-normal text-slate-400">facultatif</span>
                </span>
                <CentreSelect
                  centres={centreOptions}
                  value={form.centre_id}
                  onChange={(v) => setForm({ ...form, centre_id: v })}
                  className={inputClass}
                  disabled={!peutChangerCentre}
                />
                {!peutChangerCentre && (
                  <span className="mt-1 block text-xs leading-snug text-slate-500">
                    Le rattachement à un centre est géré par un responsable : contactez l'administration pour en changer.
                  </span>
                )}
              </div>
            </FormGrid>
          </Section>

          <Section
            title="Mot de passe"
            hint="Le mot de passe actuel vous sera demandé pour confirmer l'enregistrement."
          >
            {!motDePasseOuvert ? (
              <button type="button" className={secondaryButtonClass} onClick={() => setMotDePasseOuvert(true)}>
                Changer mon mot de passe
              </button>
            ) : (
              <div className="space-y-4">
                <FormGrid>
                  <Field label="Nouveau mot de passe" hint="8 caractères minimum.">
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
                </FormGrid>
                <button
                  type="button"
                  onClick={() => {
                    setPasswords(EMPTY_PASSWORDS);
                    setMotDePasseOuvert(false);
                  }}
                  className="text-sm font-medium text-slate-500 underline transition hover:text-brand-blue"
                >
                  Annuler le changement de mot de passe
                </button>
              </div>
            )}
          </Section>

          <FormActions className="rounded-xl px-6 py-4">
            <button type="submit" disabled={loading} className={primaryButtonClass}>
              Enregistrer les modifications
            </button>
          </FormActions>
        </form>
      </div>

      {/* Confirmation : le mot de passe actuel n'est jamais affiché, il est demandé ici pour valider la modification */}
      {confirmation && (
        <Modal
          title="Confirmer la modification"
          onClose={loading ? () => undefined : () => setConfirmation(null)}
        >
          <form onSubmit={confirmerEnvoi}>
            <p className="text-sm leading-relaxed text-slate-700">
              Saisissez votre mot de passe actuel pour confirmer. Un e-mail de confirmation vous sera ensuite envoyé
              pour appliquer les changements.
            </p>
            <div className="mt-4">
              <Field label="Mot de passe actuel" required>
                <PasswordInput
                  value={confirmation.motDePasse}
                  onChange={(e) => setConfirmation({ ...confirmation, motDePasse: e.target.value, erreur: "" })}
                  autoComplete="current-password"
                  autoFocus
                  required
                />
              </Field>
              {confirmation.erreur && (
                <p className="mt-2 text-sm font-medium text-red-700">{confirmation.erreur}</p>
              )}
            </div>
            <div className="mt-6 flex flex-wrap justify-end gap-3">
              <button
                type="button"
                className={secondaryButtonClass}
                onClick={() => setConfirmation(null)}
                disabled={loading}
              >
                Annuler
              </button>
              <button type="submit" className={primaryButtonClass} disabled={loading}>
                {loading ? "Envoi..." : "Confirmer"}
              </button>
            </div>
          </form>
        </Modal>
      )}
    </div>
  );
}
