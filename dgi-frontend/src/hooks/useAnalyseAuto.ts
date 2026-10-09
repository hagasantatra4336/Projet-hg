import { useEffect } from "react";
import api from "../services/api";
import type { Role } from "../types";

const INTERVALLE_MS = 5 * 60 * 1000;
// Mêmes rôles que la route /defaillances/analyser (un agent ne peut pas lancer l'analyse).
const ROLES_AUTORISES: Role[] = ["superadmin", "central", "admin", "agent"];

/**
 * Lance l'analyse des défaillances dès l'ouverture de la session, puis toutes les 5 minutes.
 * S'arrête toute seule à la déconnexion (le composant est démonté) ou à la fermeture de l'application.
 * Le serveur évite les doublons si plusieurs onglets / utilisateurs sont ouverts en même temps.
 */
export function useAnalyseAuto(role: Role | null | undefined): void {
  useEffect(() => {
    if (!role || !ROLES_AUTORISES.includes(role)) return;

    let actif = true;
    let enCours = false;

    const lancer = async () => {
      if (enCours) return;
      enCours = true;
      try {
        const res = await api.post<{ execute?: boolean }>("/defaillances/auto", null, { timeout: 300_000 });
        // Les pages qui affichent les alertes peuvent écouter cet événement pour se rafraîchir.
        if (actif && res.data.execute) window.dispatchEvent(new Event("defaillances:maj"));
      } catch {
        // silencieux : la prochaine tentative a lieu dans 5 minutes
      } finally {
        enCours = false;
      }
    };

    void lancer();
    const id = window.setInterval(() => void lancer(), INTERVALLE_MS);

    return () => {
      actif = false;
      window.clearInterval(id);
    };
  }, [role]);
}
