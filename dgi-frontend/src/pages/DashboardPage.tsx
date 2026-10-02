import { Link } from "react-router-dom";
import { useAuth } from "../context/AuthContext";
import { ROLE_LABELS, type Role } from "../types";

interface Card {
  to: string;
  titre: string;
  description: string;
  roles?: Role[]; // absent = visible par tous les utilisateurs connectés
}

// Les 3 pages de gestion, chacune visible selon le rôle (même règle que le menu Administration).
const CARDS: Card[] = [
  {
    to: "/profile",
    titre: "Mon profil",
    description: "Modifier mes informations et mon mot de passe (confirmation par e-mail).",
  },
  {
    to: "/admin/utilisateurs",
    titre: "Gestion des utilisateurs",
    description: "Ajouter, modifier ou supprimer des comptes (IM, centre, fonction).",
    roles: ["superadmin", "central", "admin"],
  },
  {
    to: "/admin/centres",
    titre: "Gestion des centres",
    description: "Créer et modifier les centres (nom, adresse).",
    roles: ["superadmin", "central"],
  },
  {
    to: "/admin/fonctions",
    titre: "Fonctions & rôles",
    description: "Configurer les fonctions et le rôle associé à chacune.",
    roles: ["superadmin"],
  },
];

export default function DashboardPage() {
  const { user } = useAuth();
  const cards = CARDS.filter((c) => !c.roles || (user?.role && c.roles.includes(user.role)));

  return (
    <div>
      <h1 className="text-2xl font-bold text-brand-blue mb-1">Tableau de bord</h1>
      <p className="text-sm text-gray-600 mb-6">
        Bonjour {user?.nom}
        {user?.role && ` — ${ROLE_LABELS[user.role]}`}
        {user?.centre && ` · ${user.centre.nom}`}
      </p>

      {cards.length > 0 ? (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {cards.map((c) => (
            <Link
              key={c.to}
              to={c.to}
              className="block bg-white rounded-2xl shadow p-5 hover:shadow-md hover:ring-2 hover:ring-brand-green transition"
            >
              <h2 className="font-bold text-brand-blue mb-1">{c.titre}</h2>
              <p className="text-sm text-gray-600">{c.description}</p>
            </Link>
          ))}
        </div>
      ) : (
        <p className="text-sm text-gray-500">
          Votre rôle n'a pas accès à l'administration. D'autres modules seront ajoutés ici.
        </p>
      )}
    </div>
  );
}
