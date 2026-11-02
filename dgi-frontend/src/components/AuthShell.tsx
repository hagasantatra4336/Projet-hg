/** Éléments purement visuels partagés par les pages d'accès (connexion, inscription, confirmation). */

/** Panneau institutionnel à gauche (écrans larges uniquement). */
export function AuthAside() {
  return (
    <aside className="relative hidden overflow-hidden bg-gradient-to-br from-brand-blue via-brand-blue to-brand-blue-dark text-white lg:sticky lg:top-0 lg:flex lg:h-screen lg:flex-col lg:px-14 lg:py-12">
      <span
        aria-hidden="true"
        className="pointer-events-none absolute -right-20 -top-24 h-72 w-72 rounded-full bg-brand-azure/20 blur-2xl"
      />
      <span
        aria-hidden="true"
        className="pointer-events-none absolute -bottom-24 -left-16 h-72 w-72 rounded-full bg-brand-gold/10 blur-2xl"
      />

      <div className="relative flex items-center gap-4">
        <img src="/logo-dgi.png" alt="Direction Générale des Impôts" className="h-20 w-auto" />
        <div className="border-l border-white/20 pl-4">
          <p className="text-2xl font-semibold tracking-tight">E-Kajy</p>
          <p className="text-sm text-white/65">Direction Générale des Impôts</p>
        </div>
      </div>

      <div className="relative flex flex-1 items-center">
        <img
          src="/logo-mef.png"
          alt="Ministère de l'Économie et des Finances"
          className="h-72 w-auto max-w-full object-contain drop-shadow-[0_18px_36px_rgba(0,0,0,0.35)]"
        />
      </div>

      <div className="relative border-t border-brand-gold/60 pt-6">
        <p className="max-w-md text-lg leading-relaxed text-white/85">
          Suivi des défaillances de déclaration et des baisses de chiffre d'affaires, centre par centre.
        </p>
        <p className="mt-3 text-sm text-white/55">Ministère de l'Économie et des Finances</p>
      </div>
    </aside>
  );
}

/** Logos en tête de formulaire sur mobile, là où le panneau de gauche n'est pas affiché. */
export function AuthLogos() {
  return (
    <div className="mb-8 flex items-center gap-4 lg:hidden">
      <img src="/logo-mef.png" alt="Ministère de l'Économie et des Finances" className="h-16 w-auto" />
      <img src="/logo-dgi.png" alt="Direction Générale des Impôts" className="h-16 w-auto" />
    </div>
  );
}

/** Bandeau de logos pour les pages en carte unique. */
export function LogoBand() {
  return (
    <div className="flex items-center justify-center gap-5 bg-gradient-to-r from-brand-blue to-brand-blue-dark px-6 py-5">
      <img src="/logo-mef.png" alt="Ministère de l'Économie et des Finances" className="h-16 w-auto" />
      <span className="h-12 w-px bg-white/20" aria-hidden="true" />
      <img src="/logo-dgi.png" alt="Direction Générale des Impôts" className="h-16 w-auto" />
    </div>
  );
}
