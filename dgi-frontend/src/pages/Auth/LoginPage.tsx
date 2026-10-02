import { useState, type FormEvent } from "react";
import { Link, Navigate, useNavigate } from "react-router-dom";
import api, { getErrorMessage } from "../../services/api";
import { useAuth } from "../../context/AuthContext";
import { PasswordInput } from "../../components/ui";
import type { AppUser } from "../../types";

const STEP_CREDENTIALS = 1;
const STEP_OTP = 2;

interface LoginForm {
  email: string;
  password: string;
}

interface OtpSentResponse {
  message: string;
  otp_required: boolean;
  debug_otp?: string; // renvoyé par l'API uniquement en mode développement (APP_DEBUG=true)
}

interface LoginResponse {
  token: string;
  user: AppUser;
}

const inputClass =
  "w-full rounded-lg border border-gray-300 px-4 py-2.5 text-gray-800 placeholder-gray-400 " +
  "focus:outline-none focus:ring-2 focus:ring-brand-green focus:border-transparent transition";

const primaryButtonClass =
  "w-full rounded-lg bg-brand-blue text-white font-medium py-2.5 " +
  "hover:bg-opacity-90 disabled:opacity-50 disabled:cursor-not-allowed transition";

export default function LoginPage() {
  const navigate = useNavigate();
  const { user, login } = useAuth();
  const [step, setStep] = useState<number>(STEP_CREDENTIALS);
  const [form, setForm] = useState<LoginForm>({ email: "", password: "" });
  const [otp, setOtp] = useState<string>("");
  const [error, setError] = useState<string>("");
  const [info, setInfo] = useState<string>("");
  const [loading, setLoading] = useState<boolean>(false);

  // Étape 1 : e-mail + mot de passe → l'API envoie un code par e-mail
  async function handleCredentials(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setError("");
    setInfo("");
    setLoading(true);
    try {
      const res = await api.post<OtpSentResponse>("/login", form);
      if (res.data.debug_otp) {
        setInfo(`Mode développement — code : ${res.data.debug_otp}`);
      }
      setOtp("");
      setStep(STEP_OTP);
    } catch (err) {
      setError(getErrorMessage(err, "Identifiants incorrects."));
    } finally {
      setLoading(false);
    }
  }

  // Étape 2 : code reçu par e-mail → l'API renvoie le jeton
  async function handleOtp(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setError("");
    setInfo("");
    setLoading(true);
    try {
      const res = await api.post<LoginResponse>("/login/verify-otp", {
        email: form.email,
        otp_code: otp,
      });
      login(res.data.token, res.data.user);
      navigate("/dashboard");
    } catch (err) {
      setError(getErrorMessage(err, "Code invalide ou expiré."));
    } finally {
      setLoading(false);
    }
  }

  async function handleResend() {
    setError("");
    setInfo("");
    setLoading(true);
    try {
      const res = await api.post<OtpSentResponse>("/login/resend-otp", { email: form.email });
      setInfo(
        res.data.debug_otp
          ? `Mode développement — nouveau code : ${res.data.debug_otp}`
          : "Un nouveau code a été envoyé."
      );
    } catch (err) {
      setError(getErrorMessage(err, "Impossible de renvoyer le code."));
    } finally {
      setLoading(false);
    }
  }

  function handleBack() {
    setError("");
    setInfo("");
    setOtp("");
    setForm({ ...form, password: "" });
    setStep(STEP_CREDENTIALS);
  }

  // Déjà connecté : inutile d'afficher le formulaire
  if (user) return <Navigate to="/dashboard" replace />;

  return (
    <div className="min-h-screen bg-brand-white flex items-center justify-center px-4">
      <div className="w-full max-w-md bg-white rounded-2xl shadow-lg p-8">
        <h1 className="text-xl font-bold text-brand-blue text-center mb-1">Connexion</h1>
        <p className="text-sm text-gray-500 text-center mb-6">Étape {step} sur 2</p>

        {error && (
          <p className="text-sm text-red-600 bg-red-50 border border-red-200 rounded-lg px-3 py-2 mb-4">
            {error}
          </p>
        )}
        {info && (
          <p className="text-sm text-brand-blue bg-brand-green/20 border border-brand-green rounded-lg px-3 py-2 mb-4">
            {info}
          </p>
        )}

        {step === STEP_CREDENTIALS && (
          <form onSubmit={handleCredentials} className="space-y-4">
            <input
              className={inputClass}
              type="email"
              placeholder="E-mail"
              value={form.email}
              onChange={(e) => setForm({ ...form, email: e.target.value })}
              autoComplete="username"
              required
            />
            <PasswordInput
              className={inputClass}
              placeholder="Mot de passe"
              value={form.password}
              onChange={(e) => setForm({ ...form, password: e.target.value })}
              autoComplete="current-password"
              required
            />
            <button disabled={loading} type="submit" className={primaryButtonClass}>
              {loading ? "Vérification..." : "Se connecter"}
            </button>
          </form>
        )}

        {step === STEP_OTP && (
          <form onSubmit={handleOtp} className="space-y-4">
            <p className="text-sm text-gray-600 text-center">
              Un code à 6 chiffres a été envoyé à{" "}
              <span className="font-medium text-brand-blue">{form.email}</span>.
              <br />
              Valable 10 minutes.
            </p>
            <input
              className={`${inputClass} text-center tracking-[0.5em] text-lg font-semibold`}
              placeholder="------"
              maxLength={6}
              inputMode="numeric"
              autoComplete="one-time-code"
              value={otp}
              onChange={(e) => setOtp(e.target.value.replace(/\D/g, ""))}
              required
            />
            <button disabled={loading} type="submit" className={primaryButtonClass}>
              {loading ? "Vérification..." : "Valider le code"}
            </button>
            <button
              type="button"
              onClick={handleResend}
              disabled={loading}
              className="w-full text-sm text-brand-blue font-medium hover:underline disabled:opacity-50"
            >
              Renvoyer le code
            </button>
            <button
              type="button"
              onClick={handleBack}
              disabled={loading}
              className="w-full text-sm text-gray-500 hover:underline disabled:opacity-50"
            >
              Retour
            </button>
          </form>
        )}

        {step === STEP_CREDENTIALS && (
          <p className="text-sm text-gray-500 text-center mt-6">
            Pas encore de compte ?{" "}
            <Link to="/signup" className="text-brand-blue font-medium hover:underline">
              Sign up
            </Link>
          </p>
        )}
      </div>
    </div>
  );
}
