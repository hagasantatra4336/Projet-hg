import { useCallback, useEffect, useState } from "react";
import { useLocation } from "react-router-dom";
import api from "../services/api";
import type { Role } from "../types";

export interface Notifications {
  defaillances: number;
  traitements: number;
  demandes: number;
  total: number;
}

const VIDE: Notifications = { defaillances: 0, traitements: 0, demandes: 0, total: 0 };
const INTERVALLE_MS = 60_000;

/**
 * Compteurs affichés dans la navigation. Rechargés à chaque changement de page, au retour sur l'onglet,
 * après une analyse automatique et toutes les minutes. En cas d'erreur, la navigation reste intacte.
 */
export function useNotifications(role: Role | null | undefined): Notifications {
  const [compteurs, setCompteurs] = useState<Notifications>(VIDE);
  const { pathname } = useLocation();

  const charger = useCallback(async () => {
    try {
      const res = await api.get<Notifications>("/notifications");
      setCompteurs(res.data);
    } catch {
      // silencieux : un compteur ne doit jamais gêner la navigation
    }
  }, []);

  useEffect(() => {
    if (!role) {
      setCompteurs(VIDE);
      return;
    }

    void charger();
    const id = window.setInterval(() => void charger(), INTERVALLE_MS);
    const maj = () => void charger();
    window.addEventListener("notifications:maj", maj);
    window.addEventListener("defaillances:maj", maj);
    window.addEventListener("focus", maj);

    return () => {
      window.clearInterval(id);
      window.removeEventListener("notifications:maj", maj);
      window.removeEventListener("defaillances:maj", maj);
      window.removeEventListener("focus", maj);
    };
  }, [role, pathname, charger]);

  return compteurs;
}
