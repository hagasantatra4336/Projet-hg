import { useEffect, useState } from "react";
import { Link, useParams } from "react-router-dom";
import api, { getErrorMessage } from "../services/api";
import { ErrorBox } from "../components/ui";

/**
 * Habillage écran : feuille A4 blanche centrée avec ses marges.
 * Les marges d'impression (`@page`) du gabarit ne s'appliquent qu'au PDF, pas au navigateur.
 */
const HABILLAGE = `<style>
  html { background: #eef2f7; }
  body { width: 210mm; min-height: 297mm; margin: 0 auto; padding: 14mm 14mm 16mm; background: #ffffff; }
</style>`;

/**
 * Aperçu de la fiche contribuable à l'écran.
 * Le serveur renvoie le gabarit du PDF en HTML : rien n'est téléchargé et aucun lecteur PDF n'est requis.
 */
export default function ContribuableFicheApercuPage() {
  const { id } = useParams();
  const [html, setHtml] = useState<string>("");
  const [error, setError] = useState<string>("");

  // Charge le document (même contenu que le PDF) et l'affiche tel quel.
  useEffect(() => {
    let abandonne = false;

    api
      .get<string>(`/contribuables/${id}/fiche.html`, { responseType: "text", headers: { Accept: "text/html" } })
      .then((res) => {
        if (!abandonne) setHtml(res.data);
      })
      .catch((err) => {
        if (!abandonne) setError(getErrorMessage(err, "Impossible d'afficher la fiche."));
      });

    return () => {
      abandonne = true;
    };
  }, [id]);

  return (
    <div className="flex h-[calc(100dvh-6rem)] flex-col gap-3 lg:h-[calc(100dvh-3rem)]">
      {/* Retour à la fiche de travail */}
      <Link to={`/contribuables/${id}`} className="no-print shrink-0 text-sm font-medium text-brand-blue underline">
        ← Retour à la fiche
      </Link>

      <ErrorBox message={error} />
      {!html && !error && <p className="text-sm text-slate-500">Chargement de la fiche...</p>}

      {/* Document au format A4 (794 px de large), affiché dans un cadre isolé */}
      {html && (
        <iframe
          title="Aperçu de la fiche contribuable"
          srcDoc={html.replace("</head>", `${HABILLAGE}</head>`)}
          // sandbox vide : aucun script ni formulaire ne peut s'exécuter dans le document injecté,
          // même si une donnée non échappée y arrivait un jour (le jeton est dans le localStorage).
          sandbox=""
          referrerPolicy="no-referrer"
          className="mx-auto min-h-[420px] w-[794px] max-w-full flex-1 rounded-xl border border-line bg-white"
        />
      )}
    </div>
  );
}
