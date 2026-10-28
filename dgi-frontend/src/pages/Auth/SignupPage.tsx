import { useEffect, useState, type FormEvent } from "react";
import { Link, useNavigate } from "react-router-dom";
import { isAxiosError } from "axios";
import api, { getErrorMessage } from "../../services/api";
import { useAuth } from "../../context/AuthContext";
import { ConfirmDialog, PasswordInput } from "../../components/ui";
import AuthLayout from "../../components/AuthLayout";
import CentreSelect, { type CentreOption } from "../../components/CentreSelect";
import SearchSelect from "../../components/SearchSelect";
import {
  clearPendingSignup,
  loadPendingSignup,
  savePendingSignup,
  updatePendingSignup,
} from "../../services/signupRequest";
import { ROLE_LABELS, type AppUser, type NiveauApprobation, type Role, type StatutDemande } from "../../types";

const STEP_INFOS = 1;
const STEP_ATTENTE = 2;
const STEP_OTP = 3;
const STEP_PASSWORD = 4;

const POLL_MS = 4000;
const RESEND_COOLDOWN_SECONDS = 60;

interface SignupCentre extends CentreOption {
  a_chef?: boolean;
}

interface InfosForm {
  nom: string;
  email: string;
  im: string;
  fonction_id: string;
  centre_id: string;
  telephone: string;
  adresse: string;
}

interface SignupOptions {
  centres: SignupCentre[];
  fonctions: { id: number; nom: string; role: Role }[];
}

interface PasswordForm {
  password: string;
  password_confirmation: string;
}

interface Approbateur {
  niveau: NiveauApprobation | null;
  centre: string | null;
}

interface RegisterResponse {
  message: string;
  request_id?: string;
  statut: StatutDemande;
  nom?: string;
  email?: string;
  approbateur?: Approbateur;
  expires_at?: string | null;
  otp_expires_at?: string | null;
  code_verifie?: boolean;
  // Demande déjà existante : le serveur ne renvoie aucun request_id (secret de suivi), seulement un message.
  reprise_locale_uniquement?: boolean;
}

interface StatusResponse {
  statut: StatutDemande;
  nom?: string;
  email?: string;
  centre?: { id: number; nom: string } | null;
  fonction?: string | null;
  niveau_approbation?: NiveauApprobation | null;
  approbateur?: string | null;
  motif_refus?: string | null;
  expires_at?: string | null;
  otp_expires_at?: string | null;
  code_verifie?: boolean;
  renvoi_possible?: boolean;
  secondes_avant_renvoi?: number;
  message?: string;
}

interface OtpResponse {
  message: string;
  otp_expires_at?: string | null;
  debug_otp?: string;
  retry_after?: number;
}

interface VerifyOtpResponse {
  message?: string;
  setup_token: string;
  email: string;
}

interface SetPasswordResponse {
  token: string;
  user: AppUser;
}

function StepIndicator({ step }: { step: number }) {
  const steps = [1, 2, 3, 4];
  return (
    <div className="mb-6 mt-5 flex items-center">
      {steps.map((s, i) => (
        <div key={s} className={`flex items-center ${i < steps.length - 1 ? "flex-1" : ""}`}>
          <div
            className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-semibold transition
              ${s < step ? "bg-brand-green text-brand-blue" : ""}
              ${s === step ? "bg-brand-blue text-white shadow-md shadow-brand-blue/30 ring-4 ring-brand-blue/10" : ""}
              ${s > step ? "bg-gray-100 text-gray-400" : ""}`}
          >
            {s < step ? "✓" : s}
          </div>
          {i < steps.length - 1 && <div className={`mx-2 h-0.5 flex-1 rounded-full ${s < step ? "bg-brand-green" : "bg-gray-200"}`} />}
        </div>
      ))}
    </div>
  );
}

function formatCountdown(seconds: number): string {
  const m = Math.floor(seconds / 60);
  const s = seconds % 60;
  return `${String(m).padStart(2, "0")}:${String(s).padStart(2, "0")}`;
}

function libelleApprobateur(approbateur: Approbateur | null): string {
  switch (approbateur?.niveau) {
    case "chef":
      return approbateur.centre ? `le chef du centre ${approbateur.centre}` : "le chef de votre centre";
    case "central":
    case "superadmin":
      return "l'administration centrale";
    default:
      return "le responsable de votre centre";
  }
}

const inputClass =
  "w-full rounded-xl border border-gray-200 bg-gray-50/70 px-4 py-3 text-sm text-gray-800 placeholder-gray-400 " +
  "focus:bg-white focus:outline-none focus:ring-2 focus:ring-brand-green/60 focus:border-brand-green transition";

const primaryButtonClass =
  "w-full rounded-xl bg-brand-blue py-3 text-sm font-semibold text-white shadow-md shadow-brand-blue/20 " +
  "hover:brightness-110 hover:shadow-lg active:scale-[0.99] disabled:opacity-50 disabled:cursor-not-allowed transition";

const linkButtonClass = "w-full text-sm font-medium text-brand-blue hover:underline disabled:opacity-50";

const errorClass = "mb-5 rounded-xl border-l-4 border-red-500 bg-red-50 px-4 py-3 text-sm text-red-700";
const infoClass = "mb-5 rounded-xl border-l-4 border-brand-green bg-brand-green/15 px-4 py-3 text-sm text-brand-blue";

export default function SignupPage() {
  const navigate = useNavigate();
  const { login } = useAuth();
  const [initial] = useState(() => loadPendingSignup());

  const [step, setStep] = useState<number>(() =>
    initial ? (initial.setup_token ? STEP_PASSWORD : STEP_ATTENTE) : STEP_INFOS
  );
  const [form, setForm] = useState<InfosForm>({
    nom: initial?.nom ?? "",
    email: initial?.email ?? "",
    im: "",
    fonction_id: "",
    centre_id: "",
    telephone: "",
    adresse: "",
  });
  const [options, setOptions] = useState<SignupOptions>({ centres: [], fonctions: [] });
  const [requestId, setRequestId] = useState<string>(initial?.request_id ?? "");
  const [statut, setStatut] = useState<StatutDemande>(initial?.setup_token ? "approuvee" : "en_attente");
  const [approbateur, setApprobateur] = useState<Approbateur | null>(initial?.approbateur ?? null);
  const [approuvePar, setApprouvePar] = useState<string>("");
  const [motifRefus, setMotifRefus] = useState<string>("");
  const [otp, setOtp] = useState<string>("");
  const [otpExpiresAt, setOtpExpiresAt] = useState<string | null>(initial?.otp_expires_at ?? null);
  const [codeVerifie, setCodeVerifie] = useState<boolean>(Boolean(initial?.setup_token));
  const [setupToken, setSetupToken] = useState<string>(initial?.setup_token ?? "");
  const [passwords, setPasswords] = useState<PasswordForm>({
    password: "",
    password_confirmation: "",
  });
  const [resendAt, setResendAt] = useState<number>(0);
  const [now, setNow] = useState<number>(() => Date.now());
  const [error, setError] = useState<string>("");
  const [info, setInfo] = useState<string>("");
  const [loading, setLoading] = useState<boolean>(false);
  const [aAnnuler, setAAnnuler] = useState<boolean>(false);

  // Listes déroulantes : centres + fonctions ouvertes à l'inscription
  useEffect(() => {
    api
      .get<SignupOptions>("/signup-options")
      .then((res) => setOptions(res.data))
      .catch((err) => setError(getErrorMessage(err, "Impossible de charger les centres et fonctions.")));
  }, []);

  // Suivi de la demande : toutes les 4 s tant qu'elle est en attente d'approbation.
  useEffect(() => {
    if (!requestId || statut !== "en_attente" || setupToken) return;

    let stopped = false;

    const verifier = async () => {
      try {
        const res = await api.get<StatusResponse>(`/register/status/${requestId}`);
        if (stopped) return;

        setStatut(res.data.statut);
        setApprobateur((prev) => ({
          niveau: res.data.niveau_approbation ?? prev?.niveau ?? null,
          centre: res.data.centre?.nom ?? prev?.centre ?? null,
        }));

        if (res.data.statut === "approuvee") {
          setOtpExpiresAt(res.data.otp_expires_at ?? null);
          setCodeVerifie(Boolean(res.data.code_verifie));
          setApprouvePar(res.data.approbateur ?? "");
          updatePendingSignup({ otp_expires_at: res.data.otp_expires_at ?? null });
          setResendAt(Date.now() + (res.data.secondes_avant_renvoi ?? 0) * 1000);
          setStep(STEP_OTP);
        } else if (["refusee", "annulee", "expiree", "introuvable"].includes(res.data.statut)) {
          setMotifRefus(res.data.motif_refus ?? res.data.message ?? "");
        }
      } catch (err) {
        if (stopped) return;
        if (isAxiosError(err) && err.response?.status === 404) {
          setStatut("introuvable");
          setMotifRefus("Demande introuvable ou expirée.");
        }
        // autre erreur (réseau) : on réessaie au prochain passage
      }
    };

    void verifier();
    const timer = window.setInterval(() => void verifier(), POLL_MS);
    return () => {
      stopped = true;
      window.clearInterval(timer);
    };
  }, [requestId, statut, setupToken]);

  // Compte à rebours du code (5 minutes) et du délai de renvoi.
  useEffect(() => {
    if (step !== STEP_OTP) return;
    const timer = window.setInterval(() => setNow(Date.now()), 1000);
    return () => window.clearInterval(timer);
  }, [step]);

  async function handleStep1(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setError("");
    setInfo("");
    setLoading(true);
    try {
      const res = await api.post<RegisterResponse>("/register", {
        ...form,
        centre_id: Number(form.centre_id),
        fonction_id: Number(form.fonction_id),
      });

      // Une demande existe déjà pour cet e-mail : le serveur ne transmet plus son request_id
      // (secret de suivi) pour empêcher qu'un tiers la consulte ou l'annule. On reste sur le
      // formulaire avec le message indiquant comment la reprendre depuis le navigateur d'origine.
      if (!res.data.request_id) {
        setInfo(res.data.message);
        return;
      }

      const centreNom =
        options.centres.find((c) => String(c.id) === form.centre_id)?.nom ??
        res.data.approbateur?.centre ??
        null;
      const approbateurRecu = res.data.approbateur ?? { niveau: null, centre: centreNom };

      savePendingSignup({
        request_id: res.data.request_id,
        email: res.data.email ?? form.email,
        nom: res.data.nom ?? form.nom,
        approbateur: approbateurRecu,
        otp_expires_at: res.data.otp_expires_at ?? null,
      });

      setRequestId(res.data.request_id);
      setStatut(res.data.statut);
      setApprobateur(approbateurRecu);
      setCodeVerifie(Boolean(res.data.code_verifie));
      setOtpExpiresAt(res.data.otp_expires_at ?? null);
      setInfo(res.data.message);

      if (res.data.statut === "approuvee") {
        setResendAt(Date.now() + RESEND_COOLDOWN_SECONDS * 1000);
        setStep(STEP_OTP);
      } else {
        setStep(STEP_ATTENTE);
      }
    } catch (err) {
      setError(getErrorMessage(err, "Erreur lors de l'inscription."));
    } finally {
      setLoading(false);
    }
  }

  async function handleStep3(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setError("");
    setInfo("");
    setLoading(true);
    try {
      const res = await api.post<VerifyOtpResponse>("/register/verify-otp", {
        request_id: requestId,
        otp_code: otp,
      });
      setSetupToken(res.data.setup_token);
      updatePendingSignup({ setup_token: res.data.setup_token });
      setStep(STEP_PASSWORD);
    } catch (err) {
      setError(getErrorMessage(err, "Code invalide ou expiré."));
    } finally {
      setLoading(false);
    }
  }

  async function handleResendOtp() {
    setError("");
    setInfo("");
    setLoading(true);
    try {
      const res = await api.post<OtpResponse>("/register/resend-otp", { request_id: requestId });
      setOtpExpiresAt(res.data.otp_expires_at ?? null);
      setCodeVerifie(false);
      setResendAt(Date.now() + RESEND_COOLDOWN_SECONDS * 1000);
      updatePendingSignup({ otp_expires_at: res.data.otp_expires_at ?? null });
      setInfo(
        res.data.debug_otp
          ? `Mode développement — nouveau code : ${res.data.debug_otp}`
          : res.data.message
      );
    } catch (err) {
      const retryAfter = isAxiosError<{ retry_after?: number }>(err)
        ? err.response?.data?.retry_after
        : undefined;
      if (retryAfter) setResendAt(Date.now() + retryAfter * 1000);
      setError(getErrorMessage(err, "Impossible de renvoyer le code."));
    } finally {
      setLoading(false);
    }
  }

  async function handleStep4(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setError("");
    setInfo("");
    setLoading(true);
    try {
      const res = await api.post<SetPasswordResponse>("/register/set-password", {
        email: form.email,
        setup_token: setupToken,
        ...passwords,
      });
      clearPendingSignup();
      login(res.data.token, res.data.user);
      navigate("/dashboard");
    } catch (err) {
      setError(getErrorMessage(err, "Erreur lors de la définition du mot de passe."));
    } finally {
      setLoading(false);
    }
  }

  async function handleCancel() {
    setError("");
    setLoading(true);
    try {
      await api.post("/register/cancel", { request_id: requestId });
      setAAnnuler(false);
      recommencer();
    } catch (err) {
      setError(getErrorMessage(err, "Impossible d'annuler la demande."));
      setAAnnuler(false);
    } finally {
      setLoading(false);
    }
  }

  function recommencer() {
    clearPendingSignup();
    setRequestId("");
    setStatut("en_attente");
    setApprobateur(null);
    setApprouvePar("");
    setMotifRefus("");
    setOtp("");
    setOtpExpiresAt(null);
    setCodeVerifie(false);
    setSetupToken("");
    setPasswords({ password: "", password_confirmation: "" });
    setResendAt(0);
    setError("");
    setInfo("");
    setStep(STEP_INFOS);
  }

  const secondesCode = otpExpiresAt
    ? Math.max(0, Math.floor((new Date(otpExpiresAt).getTime() - now) / 1000))
    : 0;
  const codeExpire = !otpExpiresAt || secondesCode <= 0;
  const attenteRenvoi = Math.max(0, Math.ceil((resendAt - now) / 1000));

  const centreChoisi = options.centres.find((c) => String(c.id) === form.centre_id);
  const fonctionChoisie = options.fonctions.find((f) => String(f.id) === form.fonction_id);
  const refusee = ["refusee", "annulee", "expiree", "introuvable"].includes(statut);

  // L'approbateur dépend de la fonction choisie (agent → chef du centre, chef/central → administration centrale).
  const infoApprobation = (() => {
    if (!fonctionChoisie) {
      return "L'approbateur dépend de la fonction choisie : un agent est approuvé par le chef de son centre, un chef de centre par l'administration centrale.";
    }
    if (fonctionChoisie.role === "agent") {
      if (!centreChoisi) return "Choisissez aussi votre centre : le chef de ce centre approuvera votre demande.";
      return centreChoisi.a_chef
        ? "Votre demande sera soumise au chef de ce centre. Le code de vérification ne sera envoyé qu'après son approbation."
        : "Aucun chef de centre n'est enregistré pour ce centre : la demande sera transmise à l'administration centrale.";
    }
    return "Votre demande sera soumise à l'administration centrale pour approbation.";
  })();

  return (
    <AuthLayout>
      <h1 className="text-2xl font-bold tracking-tight text-brand-blue">Créer un compte</h1>
      <p className="mt-1 text-sm text-gray-500">Étape {step} sur 4</p>

      <StepIndicator step={step} />

      {error && <p className={errorClass}>{error}</p>}
      {info && <p className={infoClass}>{info}</p>}

      {step === STEP_INFOS && (
        <form onSubmit={handleStep1} className="grid gap-4 sm:grid-cols-2">
          <input
            className={`${inputClass} sm:col-span-2`}
            placeholder="Nom"
            value={form.nom}
            onChange={(e) => setForm({ ...form, nom: e.target.value })}
            required
          />
          <input
            className={`${inputClass} sm:col-span-2`}
            type="email"
            placeholder="E-mail"
            value={form.email}
            onChange={(e) => setForm({ ...form, email: e.target.value })}
            required
          />
          <input
            className={inputClass}
            placeholder="IM (matricule)"
            value={form.im}
            onChange={(e) => setForm({ ...form, im: e.target.value })}
          />
          <input
            className={inputClass}
            placeholder="Téléphone"
            value={form.telephone}
            onChange={(e) => setForm({ ...form, telephone: e.target.value })}
          />
          <div className="sm:col-span-2">
            <CentreSelect
              centres={options.centres}
              value={form.centre_id}
              onChange={(v) => setForm({ ...form, centre_id: v })}
              className={inputClass}
              placeholder="Centre (rechercher par nom ou adresse)"
              required
            />
          </div>
          <div className="sm:col-span-2">
            <SearchSelect
              options={options.fonctions.map((f) => ({
                value: String(f.id),
                label: f.nom,
                description: ROLE_LABELS[f.role],
              }))}
              value={form.fonction_id}
              onChange={(v) => setForm({ ...form, fonction_id: v })}
              className={inputClass}
              required
              label="Fonction"
              placeholder="Fonction (rechercher parmi la liste)"
              emptyLabel="Aucune fonction trouvée"
            />
          </div>
          <input
            className={`${inputClass} sm:col-span-2`}
            placeholder="Adresse"
            value={form.adresse}
            onChange={(e) => setForm({ ...form, adresse: e.target.value })}
          />

          <p className="rounded-xl bg-gray-50 px-4 py-3 text-xs text-gray-600 sm:col-span-2">
            {infoApprobation}
          </p>

          <button disabled={loading} type="submit" className={`${primaryButtonClass} sm:col-span-2`}>
            {loading ? "Envoi de la demande..." : "Demander la création du compte"}
          </button>
        </form>
      )}

      {step === STEP_ATTENTE && (
        <div className="space-y-4">
          {refusee && (
            <div className="rounded-xl border border-red-200 bg-red-50 p-5 text-center">
              <h2 className="mb-2 text-lg font-semibold text-red-700">
                {statut === "refusee"
                  ? "Demande refusée"
                  : statut === "annulee"
                    ? "Demande annulée"
                    : statut === "introuvable"
                      ? "Demande introuvable"
                      : "Demande expirée"}
              </h2>
              <p className="mb-4 text-sm text-slate-700">
                {statut === "refusee"
                  ? "Votre demande n'a pas été approuvée : aucun compte n'a été créé et aucun code ne vous sera envoyé."
                  : statut === "annulee"
                    ? "Cette demande a été annulée."
                    : statut === "introuvable"
                      ? "Cette demande n'existe plus. Recommencez l'inscription."
                      : "La demande n'a pas été approuvée dans le délai de 7 jours."}
              </p>
              {motifRefus && (
                <p className="mb-4 rounded-lg bg-white px-3 py-2 text-sm text-red-700">Motif : {motifRefus}</p>
              )}
              <button type="button" onClick={recommencer} className={primaryButtonClass}>
                Recommencer l'inscription
              </button>
            </div>
          )}

          {!refusee && (
            <div className="rounded-xl border border-line bg-white p-6 text-center">
              <div
                role="status"
                aria-label="En attente d'approbation"
                className="mx-auto mb-5 h-12 w-12 animate-spin rounded-full border-4 border-brand-green/30 border-t-brand-blue"
              />
              <h2 className="mb-2 text-lg font-semibold text-brand-blue">En attente d'approbation</h2>
              <p className="text-sm text-gray-600">
                Votre demande a été envoyée à {libelleApprobateur(approbateur)}.
                <br />
                Dès qu'elle est approuvée, un code à 6 chiffres valable <strong>5 minutes</strong> sera envoyé à{" "}
                <span className="font-semibold text-brand-blue">{form.email}</span>.
              </p>
              <p className="mt-3 text-xs text-gray-500">
                Cette page se met à jour automatiquement. Vous pouvez la fermer : la demande reste valable 7 jours et
                vous la retrouverez en revenant sur ce navigateur.
              </p>
              <button
                type="button"
                onClick={() => setAAnnuler(true)}
                disabled={loading}
                className="mt-5 text-sm font-medium text-gray-500 hover:text-red-600 hover:underline disabled:opacity-50"
              >
                Annuler la demande
              </button>
            </div>
          )}
        </div>
      )}

      {step === STEP_OTP && (
        <form onSubmit={handleStep3} className="space-y-4">
          <div className="rounded-xl bg-gray-50 px-4 py-3 text-center text-sm text-gray-600">
            {codeVerifie && !setupToken ? (
              <p>
                Votre e-mail a déjà été vérifié sur un autre appareil. Recevez un nouveau code pour définir votre mot
                de passe ici.
              </p>
            ) : (
              <p>
                Code à 6 chiffres envoyé à{" "}
                <span className="font-semibold text-brand-blue">{form.email}</span>.
                <br />
                Valable <strong>5 minutes</strong>
                {!codeExpire && (
                  <>
                    {" "}
                    — encore <span className="font-mono font-semibold text-brand-blue">{formatCountdown(secondesCode)}</span>
                  </>
                )}
              </p>
            )}
            {approuvePar && (
              <p className="mt-1 text-xs text-gray-500">
                Demande approuvée par{" "}
                {approbateur?.niveau === "superadmin" ? "l'administration centrale" : approuvePar}.
              </p>
            )}
          </div>

          {codeExpire && !(codeVerifie && !setupToken) && (
            <p className="rounded-xl border-l-4 border-amber-400 bg-amber-50 px-4 py-3 text-sm text-amber-800">
              Le code a expiré. Demandez un nouveau code ci-dessous.
            </p>
          )}

          {!(codeVerifie && !setupToken) && (
            <input
              className={`${inputClass} text-center tracking-[0.5em] text-xl font-semibold`}
              placeholder="------"
              maxLength={6}
              inputMode="numeric"
              autoComplete="one-time-code"
              value={otp}
              onChange={(e) => setOtp(e.target.value.replace(/\D/g, ""))}
              disabled={codeExpire}
              required
            />
          )}

          {!(codeVerifie && !setupToken) && (
            <button disabled={loading || codeExpire} type="submit" className={primaryButtonClass}>
              {loading ? "Vérification..." : "Vérifier le code"}
            </button>
          )}

          <button
            type="button"
            onClick={handleResendOtp}
            disabled={loading || attenteRenvoi > 0}
            className={linkButtonClass}
          >
            {attenteRenvoi > 0 ? `Renvoyer le code (dans ${attenteRenvoi} s)` : "Renvoyer le code"}
          </button>
          <button
            type="button"
            onClick={recommencer}
            disabled={loading}
            className="w-full text-sm text-gray-500 hover:text-brand-blue hover:underline disabled:opacity-50"
          >
            Recommencer l'inscription
          </button>
        </form>
      )}

      {step === STEP_PASSWORD && (
        <form onSubmit={handleStep4} className="space-y-4">
          <p className="rounded-xl bg-gray-50 px-4 py-3 text-center text-sm text-gray-600">
            E-mail vérifié pour <span className="font-semibold text-brand-blue">{form.email}</span>. Choisissez votre
            mot de passe pour activer le compte.
          </p>
          <PasswordInput
            className={inputClass}
            placeholder="Mot de passe"
            value={passwords.password}
            onChange={(e) => setPasswords({ ...passwords, password: e.target.value })}
            minLength={8}
            autoComplete="new-password"
            required
          />
          <PasswordInput
            className={inputClass}
            placeholder="Confirmer le mot de passe"
            value={passwords.password_confirmation}
            onChange={(e) =>
              setPasswords({ ...passwords, password_confirmation: e.target.value })
            }
            minLength={8}
            autoComplete="new-password"
            required
          />
          <p className="text-xs text-gray-500">8 caractères minimum.</p>
          <button disabled={loading} type="submit" className={primaryButtonClass}>
            {loading ? "Création..." : "Créer mon compte"}
          </button>
          <button
            type="button"
            onClick={recommencer}
            disabled={loading}
            className="w-full text-sm text-gray-500 hover:text-brand-blue hover:underline disabled:opacity-50"
          >
            Recommencer l'inscription
          </button>
        </form>
      )}

      {step === STEP_INFOS && (
        <p className="mt-8 text-center text-sm text-gray-500">
          Déjà un compte ?{" "}
          <Link to="/" className="font-semibold text-brand-blue hover:underline">
            Se connecter
          </Link>
        </p>
      )}

      {aAnnuler && (
        <ConfirmDialog
          title="Annuler l'inscription"
          message="Annuler cette demande de création de compte ?"
          detail="L'approbateur ne pourra plus la valider. Vous pourrez recommencer l'inscription à tout moment."
          confirmLabel="Confirmer l'annulation"
          loadingLabel="Annulation..."
          tone="primary"
          loading={loading}
          onConfirm={() => void handleCancel()}
          onCancel={() => setAAnnuler(false)}
        />
      )}
    </AuthLayout>
  );
}
