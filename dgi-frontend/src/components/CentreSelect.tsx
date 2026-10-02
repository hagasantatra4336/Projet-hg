import { useEffect, useId, useMemo, useRef, useState, type KeyboardEvent } from "react";

export interface CentreOption {
  id: number;
  nom: string;
  adresse?: string | null;
}

interface Props {
  centres: CentreOption[];
  /** Id du centre choisi (texte), "" si aucun. */
  value: string;
  onChange: (value: string) => void;
  /** Classes du champ (par ex. inputClass de la page). */
  className?: string;
  disabled?: boolean;
  required?: boolean;
  placeholder?: string;
  label?: string; // libellé pour les lecteurs d'écran
}

/** Retire accents et majuscules pour une recherche tolérante (« yaounde » trouve « Yaoundé »). */
const norm = (s: string): string =>
  s.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase();

/** « Nom — Adresse » (ou seulement « Nom » si le centre n'a pas d'adresse). */
export function centreLabel(c: CentreOption): string {
  return c.adresse ? `${c.nom} — ${c.adresse}` : c.nom;
}

/**
 * Champ « Centre » avec recherche : on tape une partie du nom ou de l'adresse, la liste se filtre,
 * on clique (ou Entrée) sur un résultat pour le choisir. Une fois choisi, le champ affiche « Nom — Adresse ».
 * Clavier : ↑ ↓ pour naviguer, Entrée pour choisir, Échap pour fermer.
 */
export default function CentreSelect({
  centres,
  value,
  onChange,
  className = "",
  disabled = false,
  required = false,
  placeholder = "Rechercher un centre (nom ou adresse)",
  label = "Centre",
}: Props) {
  const [open, setOpen] = useState<boolean>(false);
  const [query, setQuery] = useState<string>("");
  const [active, setActive] = useState<number>(0);
  const rootRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLInputElement>(null);
  const listRef = useRef<HTMLUListElement>(null);
  const listId = useId();

  const selected = centres.find((c) => String(c.id) === value) ?? null;

  const results = useMemo(() => {
    const q = norm(query.trim());
    if (!q) return centres;
    return centres.filter((c) => norm(`${c.nom} ${c.adresse ?? ""}`).includes(q));
  }, [centres, query]);

  // Fermeture au clic en dehors du champ
  useEffect(() => {
    if (!open) return;
    const onDown = (e: MouseEvent) => {
      if (rootRef.current && !rootRef.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener("mousedown", onDown);
    return () => document.removeEventListener("mousedown", onDown);
  }, [open]);

  // Garde l'élément surligné visible dans la liste (défilement interne uniquement)
  useEffect(() => {
    const list = listRef.current;
    const el = list?.children[active] as HTMLElement | undefined;
    if (!list || !el) return;
    if (el.offsetTop < list.scrollTop) {
      list.scrollTop = el.offsetTop;
    } else if (el.offsetTop + el.offsetHeight > list.scrollTop + list.clientHeight) {
      list.scrollTop = el.offsetTop + el.offsetHeight - list.clientHeight;
    }
  }, [active, open]);

  function openList() {
    if (disabled) return;
    setQuery("");
    setActive(Math.max(0, centres.findIndex((c) => String(c.id) === value)));
    setOpen(true);
  }

  function choose(c: CentreOption) {
    onChange(String(c.id));
    setOpen(false);
    setQuery("");
    inputRef.current?.blur(); // le champ réaffiche « Nom — Adresse »
  }

  function clear() {
    onChange("");
    setQuery("");
    setOpen(false);
  }

  function handleKeyDown(e: KeyboardEvent<HTMLInputElement>) {
    if (disabled) return;

    if (e.key === "ArrowDown") {
      e.preventDefault();
      if (!open) openList();
      else setActive((i) => Math.min(i + 1, Math.max(results.length - 1, 0)));
    } else if (e.key === "ArrowUp") {
      e.preventDefault();
      setActive((i) => Math.max(i - 1, 0));
    } else if (e.key === "Enter" && open) {
      e.preventDefault(); // choisit le résultat surligné sans envoyer le formulaire
      const c = results[active];
      if (c) choose(c);
    } else if (e.key === "Escape" && open) {
      e.preventDefault();
      setOpen(false);
      inputRef.current?.blur();
    } else if (e.key === "Tab") {
      setOpen(false);
    }
  }

  return (
    <div ref={rootRef} className="relative">
      <input
        ref={inputRef}
        type="text"
        role="combobox"
        aria-label={label}
        aria-expanded={open}
        aria-controls={listId}
        aria-autocomplete="list"
        autoComplete="off"
        disabled={disabled}
        className={`${className} pr-9`}
        value={open ? query : selected ? centreLabel(selected) : ""}
        placeholder={open && selected ? centreLabel(selected) : placeholder}
        onFocus={() => {
          if (!open) openList();
        }}
        onClick={() => {
          if (!open) openList();
        }}
        onChange={(e) => {
          setQuery(e.target.value);
          setActive(0);
        }}
        onKeyDown={handleKeyDown}
      />

      {/* Champ invisible : permet au navigateur de signaler « champ obligatoire » tant qu'aucun centre n'est choisi */}
      <input
        tabIndex={-1}
        aria-hidden="true"
        required={required && !disabled}
        value={value}
        onChange={() => undefined}
        className="pointer-events-none absolute bottom-0 left-1/2 h-px w-px opacity-0"
      />

      {value && !required && !disabled ? (
        <button
          type="button"
          onClick={clear}
          aria-label="Retirer le centre"
          title="Retirer le centre"
          className="absolute inset-y-0 right-0 flex items-center px-3 text-lg leading-none text-gray-400 hover:text-red-500"
        >
          ×
        </button>
      ) : (
        <span className="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-gray-400">
          <svg
            xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth={1.8}
            strokeLinecap="round"
            strokeLinejoin="round"
            className="h-4 w-4"
            aria-hidden="true"
          >
            <circle cx="11" cy="11" r="7" />
            <line x1="21" y1="21" x2="16.65" y2="16.65" />
          </svg>
        </span>
      )}

      {open && (
        <ul
          id={listId}
          ref={listRef}
          role="listbox"
          className="absolute z-30 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-gray-200 bg-white py-1 shadow-lg"
        >
          {results.length === 0 && <li className="px-3 py-2 text-sm text-gray-500">Aucun centre trouvé</li>}
          {results.map((c, i) => (
            <li
              key={c.id}
              role="option"
              aria-selected={String(c.id) === value}
              onMouseDown={(e) => {
                e.preventDefault(); // évite de faire perdre le focus au champ avant le choix
                choose(c);
              }}
              onMouseEnter={() => setActive(i)}
              className={`cursor-pointer px-3 py-2 ${i === active ? "bg-brand-green/30" : ""}`}
            >
              <div className={`text-sm text-brand-blue ${String(c.id) === value ? "font-bold" : "font-medium"}`}>
                {c.nom}
              </div>
              {c.adresse && <div className="text-xs text-gray-500">{c.adresse}</div>}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
