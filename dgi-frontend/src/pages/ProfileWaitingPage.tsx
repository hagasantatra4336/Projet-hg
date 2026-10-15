import { useEffect, useState, type ReactNode } from "react";
import { Link, Navigate, useNavigate } from "react-router-dom";
import api, { getErrorMessage } from "../services/api";
import { useAuth } from "../context/AuthContext";
import { ErrorBox, primaryButtonClass, secondaryButtonClass } from "../components/ui";
import { clearPendingRequest, loadPendingRequest } from "../services/profileRequest";

type Phase = "pending" | "confirmed" | "cancelled" | "failed" | "expired";

interface StatusResponse {
  status: Phase;
  champs: string[];
  expires_at: string | null;
  password_changed: boolean;
  message: string | null;
}

const POLL_MS = 3000;

function formatCountdown(seconds: number): string {
  const m = Math.floor(seconds / 60);
  const s = seconds % 60;
  return `${String(m).padStart(2, "0")}:${String(s).padStart(2, "0")}`;
}

function Card({ children }: { children: ReactNode }) {
  return (
    <div className="mx-auto w-full max-w-xl rounded-xl border border-line bg-white p-8 text-center">
      {children}
    </div>
  );
}

/**
 * Page affichée après « Enregistrer et demander la confirmation ».
 * Elle interroge le serveur toutes les 3 secondes : dès que l'utilisateur a cliqué sur « Confirmer » depuis le lien
 * reçu par e-mail (sur ordinateur, smartphone ou tout autre appareil), la modification est appliquée côté serveur
 * et cette page redirige automatiquement vers le profil, qui affiche les nouvelles informations.
 */
export default function ProfileWaitingPage() {
  const { refreshUser, logout } = useAuth();
  const navigate = useNavigate();

  const [request] = useState(() => loadPendingRequest());
  const [phase, setPhase] = useState<Phase>("pending");
  const [passwordChanged, setPasswordChanged] = useState<boolean>(false);
  const [serverMessage, setServerMessage] = useState<string>("");
  const [now, setNow] = useState<number>(() => Date.now());
  const [cancelling, setCancelling] = useState<boolean>(false);
  const [error, setError] = useState<string>("");

  // Suivi de la demande : toutes les 3 s, et immédiatement quand l'onglet redevient visible.
  useEffect(() => {
    if (!request || phase !== "pending") return;

    let stopped = false;

    const verifier = async () => {
      try {
        const res = await api.get<StatusResponse>(`/profile/change-status/${request.request_id}`);
        if (stopped || res.data.status === "pending") return;

        stopped = true; // un seul traitement du résultat, même si deux vérifications se croisent
        setPasswordChanged(res.data.password_changed);
        setServerMessage(res.data.message ?? "");

        // Modification appliquée : on recharge l'utilisateur pour afficher ses nouvelles données.
        // (Si le mot de passe a changé, ses sessions sont fermées : inutile de recharger.)
        if (res.data.status === "confirmed" && !res.data.password_changed) {
          await refreshUser();
        }
        setPhase(res.data.status);
      } catch {
        // problème réseau passager : on réessaie au prochain passage
      }
    };

    void verifier();
    const timer = window.setInterval(() => void verifier(), POLL_MS);
    const onVisible = () => {
      if (document.visibilityState === "visible") void verifier();
    };
    document.addEventListener("visibilitychange", onVisible);
    window.addEventListener("focus", onVisible);

    return () => {
      stopped = true;
      window.clearInterval(timer);
      document.removeEventListener("visibilitychange", onVisible);
      window.removeEventListener("focus", onVisible);
    };
  }, [request, phase, refreshUser]);

  // Compte à rebours de validité du lien.
  useEffect(() => {
    if (phase !== "pending") return;
    const timer = window.setInterval(() => setNow(Date.now()), 1000);
    return () => window.clearInterval(timer);
  }, [phase]);

  // Modification appliquée : redirection vers le profil (après un court instant pour voir la confirmation).
  // Si le mot de passe a changé, les sessions sont fermées : le profil n'est plus accessible, on reste ici.
  useEffect(() => {
    if (phase !== "confirmed" || passwordChanged) return;
    const timer = window.setTimeout(() => navigate("/profile", { replace: true, state: { updated: true } }), 1200);
    return () => window.clearTimeout(timer);
  }, [phase, passwordChanged, navigate]);

  // Demande terminée : on n'a plus besoin de la mémoriser.
  useEffect(() => {
    if (phase !== "pending") clearPendingRequest();
  }, [phase]);

  // Page ouverte sans demande en cours (adresse tapée à la main, onglet fermé puis rouvert...).
  if (!request) return <Navigate to="/profile" replace />;

  const secondesRestantes = Math.max(0, Math.floor((new Date(request.expires_at).getTime() - now) / 1000));

  async function handleCancel() {
    setCancelling(true);
    setError("");
    try {
      await api.delete("/profile/request-change");
      clearPendingRequest();
      navigate("/profile");
    } catch (err) {
      setError(getErrorMessage(err, "Impossible d'annuler la demande."));
      setCancelling(false);
    }
  }

  async function handleReconnect() {
    await logout();
    navigate("/");
  }

  return (
    <div className="w-full">
      <div className="mb-6 border-b border-line pb-4">
        <h1 className="text-2xl font-semibold tracking-tight text-brand-blue">Validation de vos modifications</h1>
        <p className="text-sm text-slate-600">
          Vous serez redirigé vers votre profil dès que vous aurez validé l'e-mail, sur ordinateur ou sur smartphone.
        </p>
      </div>

      <ErrorBox message={error} />

      {phase === "pending" && (
        <Card>
          <div
            role="status"
            aria-label="En attente de validation"
            className="mx-auto mb-5 h-14 w-14 animate-spin rounded-full border-4 border-brand-green/30 border-t-brand-blue"
          />
          <h2 className="mb-2 text-lg font-semibold text-brand-blue">En attente de votre validation</h2>
          <p className="text-sm text-slate-600">
            Un e-mail a été envoyé à <span className="font-semibold text-brand-blue">{request.email}</span>. Ouvrez-le
            (Gmail ou autre) sur cet ordinateur ou sur votre smartphone, cliquez sur le lien, puis sur « Confirmer les
            modifications ».
          </p>
          <p className="mt-3 text-sm text-slate-600">
            À modifier : <span className="font-semibold text-brand-blue">{request.champs.join(", ")}</span>
          </p>
          <p className="mt-4 text-xs text-slate-500">
            Lien valable encore{" "}
            <span className="font-mono font-semibold text-brand-blue">{formatCountdown(secondesRestantes)}</span>
          </p>

          {request.debug_confirm_url && (
            <div className="mt-4 space-y-2 rounded-lg bg-leaf-50 px-3 py-2 text-xs text-brand-blue">
              <p className="break-all">
                Mode développement — lien :{" "}
                <a href={request.debug_confirm_url} target="_blank" rel="noreferrer" className="underline">
                  {request.debug_confirm_url}
                </a>
              </p>
              {/\/\/(localhost|127\.0\.0\.1)[:/]/.test(request.debug_confirm_url) && (
                <p className="text-slate-700">
                  Ce lien contient « localhost » : il ne s'ouvrira pas sur un smartphone. Ouvrez l'application depuis
                  l'adresse de votre PC (ex. http://192.168.1.20:5173) puis refaites la demande.
                </p>
              )}
            </div>
          )}

          <div className="mt-6 flex flex-wrap justify-center gap-3">
            <button type="button" className={secondaryButtonClass} onClick={handleCancel} disabled={cancelling}>
              {cancelling ? "Annulation..." : "Annuler la demande"}
            </button>
            <Link to="/profile" className={secondaryButtonClass}>
              Retour au profil
            </Link>
          </div>
          <p className="mt-3 text-xs text-slate-500">
            Vous pouvez quitter cette page : la demande reste valable jusqu'à la fin du délai.
          </p>
        </Card>
      )}

      {phase === "confirmed" && !passwordChanged && (
        <Card>
          <div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-brand-green text-2xl font-semibold tracking-tight text-brand-blue">
            ✓
          </div>
          <h2 className="mb-1 text-lg font-semibold text-brand-blue">Modifications appliquées</h2>
          <p className="mb-5 text-sm text-slate-600">Redirection vers votre profil...</p>
          <Link to="/profile" replace state={{ updated: true }} className={`${primaryButtonClass} inline-block`}>
            Aller au profil maintenant
          </Link>
        </Card>
      )}

      {phase === "confirmed" && passwordChanged && (
        <Card>
          <div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-brand-green text-2xl font-semibold tracking-tight text-brand-blue">
            ✓
          </div>
          <h2 className="mb-1 text-lg font-semibold text-brand-blue">Modifications appliquées</h2>
          <p className="mb-6 text-sm text-slate-600">
            Votre mot de passe a été modifié : toutes vos sessions ont été fermées par sécurité. Reconnectez-vous avec
            votre nouveau mot de passe.
          </p>
          <button type="button" className={primaryButtonClass} onClick={handleReconnect}>
            Se connecter
          </button>
        </Card>
      )}

      {phase === "failed" && (
        <Card>
          <h2 className="mb-2 text-lg font-bold text-red-600">Modification impossible</h2>
          <p className="mb-6 text-sm text-slate-600">
            {serverMessage || "La modification n'a pas pu être appliquée. Refaites une demande depuis votre profil."}
          </p>
          <Link to="/profile" className={`${primaryButtonClass} inline-block`}>
            Retour à mon profil
          </Link>
        </Card>
      )}

      {(phase === "expired" || phase === "cancelled") && (
        <Card>
          <h2 className="mb-2 text-lg font-semibold text-brand-blue">
            {phase === "expired" ? "Le lien a expiré" : "Demande annulée"}
          </h2>
          <p className="mb-6 text-sm text-slate-600">
            {phase === "expired"
              ? "Le délai de validation est dépassé : aucune modification n'a été appliquée."
              : "Cette demande a été annulée ou remplacée par une nouvelle : aucune modification n'a été appliquée."}
          </p>
          <Link to="/profile" className={`${primaryButtonClass} inline-block`}>
            Refaire une demande
          </Link>
        </Card>
      )}
    </div>
  );
}
