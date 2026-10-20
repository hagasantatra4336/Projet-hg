import type { HistoriqueRegle, ParametreSchema } from "./types";

type Tranche = (number | "")[];

/** Texte lisible d'une valeur de réglage (pour l'historique). */
export function formaterValeur(p: ParametreSchema, valeur: unknown): string {
  if (p.type === "bareme" && Array.isArray(valeur)) {
    return (valeur as Tranche[]).map((t) => `${t[0]} à ${t[1]} mois → ${t[2]}`).join(" ; ");
  }
  if (Array.isArray(valeur)) return valeur.join(", ");
  return String(valeur ?? "");
}

/** Changements d'une modification : une ligne par réglage modifié (« avant → après »). */
export function changements(h: Pick<HistoriqueRegle, "action" | "avant" | "apres">, schema: ParametreSchema[]): { label: string; avant: string; apres: string }[] {
  const lignes: { label: string; avant: string; apres: string }[] = [];

  if (h.avant.actif !== h.apres.actif) {
    lignes.push({ label: "État de la règle", avant: h.avant.actif ? "activée" : "désactivée", apres: h.apres.actif ? "activée" : "désactivée" });
  }

  for (const p of schema) {
    const a = h.avant.parametres?.[p.cle];
    const b = h.apres.parametres?.[p.cle];
    if (JSON.stringify(a) !== JSON.stringify(b)) {
      lignes.push({ label: p.label, avant: formaterValeur(p, a), apres: formaterValeur(p, b) });
    }
  }

  return lignes;
}
