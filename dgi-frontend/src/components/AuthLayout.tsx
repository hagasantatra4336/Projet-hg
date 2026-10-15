import type { ReactNode } from "react";

/** Mise en page des pages de connexion et d'inscription : image E-Fehy à gauche, formulaire à droite. */
export default function AuthLayout({ children }: { children: ReactNode }) {
  return (
    <div className="grid min-h-screen bg-white lg:grid-cols-2">
      {/* Côté image (ordinateur) */}
      <aside className="relative hidden overflow-hidden lg:block">
        <img src="/efehy-auth.jpg" alt="E-Fehy" className="absolute inset-0 h-full w-full object-cover" />
        <div className="absolute inset-0 bg-gradient-to-t from-brand-blue/85 via-brand-blue/10 to-transparent" />
        <div className="absolute inset-x-0 bottom-0 p-10 text-white xl:p-14">
          <span className="mb-4 block h-1 w-12 rounded-full bg-brand-green" />
          <p className="max-w-sm text-lg font-medium leading-snug">Suivez les déclarations et repérez les anomalies, simplement.</p>
        </div>
      </aside>

      {/* Côté formulaire */}
      <main className="flex flex-col bg-gradient-to-br from-white via-white to-brand-green/10">
        {/* Bannière (téléphone et tablette) */}
        <div className="relative h-32 overflow-hidden sm:h-44 lg:hidden">
          <img src="/efehy-auth.jpg" alt="E-Fehy" className="h-full w-full object-cover" />
          <div className="absolute inset-0 bg-gradient-to-t from-white via-transparent to-brand-blue/20" />
        </div>

        <div className="flex flex-1 items-center justify-center px-6 pb-10 pt-4 sm:px-10 lg:py-10">
          <div className="w-full max-w-md">{children}</div>
        </div>
      </main>
    </div>
  );
}
