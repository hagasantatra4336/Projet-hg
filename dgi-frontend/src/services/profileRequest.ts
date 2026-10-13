/** Demande de modification du profil en attente de validation par e-mail (mémorisée pour la page d'attente). */
export interface PendingProfileRequest {
  request_id: string;
  email: string;
  champs: string[];
  expires_at: string;
  debug_confirm_url?: string; // mode développement uniquement
}

const KEY = "profile-pending-request";

export function savePendingRequest(request: PendingProfileRequest): void {
  try {
    sessionStorage.setItem(KEY, JSON.stringify(request));
  } catch {
    // stockage indisponible (navigation privée...) : la page d'attente ne sera pas restaurée après un rechargement
  }
}

export function loadPendingRequest(): PendingProfileRequest | null {
  try {
    const raw = sessionStorage.getItem(KEY);
    return raw ? (JSON.parse(raw) as PendingProfileRequest) : null;
  } catch {
    return null;
  }
}

export function clearPendingRequest(): void {
  try {
    sessionStorage.removeItem(KEY);
  } catch {
    // rien à faire
  }
}
