import type { NiveauApprobation } from "../types";

/**
 * Demande d'inscription mémorisée dans le navigateur : si l'agent ferme l'onglet pendant
 * l'attente d'approbation (ou pendant la saisie du code), il retrouve sa demande en revenant.
 * Seul le request_id de 48 caractères (secret de suivi) est stocké, jamais le code OTP.
 */
const KEY = "signup_request";
const TTL_MS = 7 * 24 * 60 * 60 * 1000; // 7 jours, comme la demande côté serveur

export interface PendingSignup {
  request_id: string;
  email: string;
  nom: string;
  approbateur: { niveau: NiveauApprobation | null; centre: string | null };
  created_at: number;
  otp_expires_at?: string | null;
  setup_token?: string;
}

export function savePendingSignup(value: Omit<PendingSignup, "created_at"> & { created_at?: number }): void {
  localStorage.setItem(
    KEY,
    JSON.stringify({ ...value, created_at: value.created_at ?? Date.now() })
  );
}

export function loadPendingSignup(): PendingSignup | null {
  try {
    const raw = localStorage.getItem(KEY);
    if (!raw) return null;

    const data = JSON.parse(raw) as PendingSignup;
    if (!data.request_id || Date.now() - (data.created_at ?? 0) > TTL_MS) {
      localStorage.removeItem(KEY);
      return null;
    }

    return data;
  } catch {
    localStorage.removeItem(KEY);
    return null;
  }
}

export function updatePendingSignup(patch: Partial<PendingSignup>): void {
  const current = loadPendingSignup();
  if (!current) return;

  localStorage.setItem(KEY, JSON.stringify({ ...current, ...patch }));
}

export function clearPendingSignup(): void {
  localStorage.removeItem(KEY);
}
