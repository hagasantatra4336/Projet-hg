import { useEffect, useId, useMemo, useRef, useState, type KeyboardEvent } from "react";

export interface SearchSelectOption {
  value: string;
  label: string;
  /** Ligne secondaire affichée sous le libellé (facultative). */
  description?: string | null;
}

interface Props {
  options: SearchSelectOption[];
  /** Valeur choisie, "" pour aucune. */
  value: string;
  onChange: (value: string) => void;
  className?: string;
  disabled?: boolean;
  required?: boolean;
  placeholder?: string;
  /** Libellé pour les lecteurs d'écran. */
  label?: string;
  /** Option qui remet la valeur à "" (par ex. « Tous les centres »). Absente = pas de remise à zéro. */
  clearLabel?: string;
  /** Texte affiché quand la recherche ne trouve rien. */
  emptyLabel?: string;
}

/** Recherche tolérante : « yaounde » trouve « Yaoundé », « ecole » trouve « École ». */
const norm = (s: string): string =>
  s.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase();

/**
 * Liste déroulante avec recherche, pour les listes longues (centres, fonctions...).
 * On tape une partie du libellé, la liste se filtre, on clique (ou Entrée) sur un résultat.
 * Clavier : ↑ ↓ pour naviguer, Entrée pour choisir, Échap pour fermer.
 */
export default function SearchSelect({
  options,
  value,
  onChange,
  className = "",
  disabled = false,
  required = false,
  placeholder,
  label = "Rechercher",
  clearLabel,
  emptyLabel = "Aucun résultat",
}: Props) {
  const [open, setOpen] = useState<boolean>(false);
  const [query, setQuery] = useState<string>("");
  const [active, setActive] = useState<number>(0);
  const rootRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLInputElement>(null);
  const listRef = useRef<HTMLUListElement>(null);
  const listId = useId();

  const selected = options.find((o) => o.value === value) ?? null;

  const results = useMemo(() => {
    const q = norm(query.trim());
    if (!q) return options;
    return options.filter((o) => norm(`${o.label} ${o.description ?? ""}`).includes(q));
  }, [options, query]);

  // L'option « Tous / Aucun » n'apparaît que lorsque la recherche est vide
  const entries: SearchSelectOption[] = useMemo(
    () => (clearLabel && !query.trim() ? [{ value: "", label: clearLabel }, ...results] : results),
    [clearLabel, query, results],
  );

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
    const offset = clearLabel ? 1 : 0;
    const index = options.findIndex((o) => o.value === value);
    setActive(index >= 0 ? index + offset : 0);
    setOpen(true);
  }

  function choose(o: SearchSelectOption) {
    onChange(o.value);
    setOpen(false);
    setQuery("");
    inputRef.current?.blur();
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
      else setActive((i) => Math.min(i + 1, Math.max(entries.length - 1, 0)));
    } else if (e.key === "ArrowUp") {
      e.preventDefault();
      setActive((i) => Math.max(i - 1, 0));
    } else if (e.key === "Enter" && open) {
      e.preventDefault(); // choisit le résultat surligné sans envoyer le formulaire
      const o = entries[active];
      if (o) choose(o);
    } else if (e.key === "Escape" && open) {
      e.preventDefault();
      setOpen(false);
      inputRef.current?.blur();
    } else if (e.key === "Tab") {
      setOpen(false);
    }
  }

  const shownPlaceholder = placeholder ?? clearLabel ?? "Rechercher...";

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
        value={open ? query : (selected?.label ?? "")}
        placeholder={open && selected ? selected.label : shownPlaceholder}
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

      {/* Champ invisible : permet au navigateur de signaler « champ obligatoire » tant que rien n'est choisi */}
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
          aria-label="Retirer la sélection"
          title="Retirer la sélection"
          className="absolute inset-y-0 right-0 flex items-center px-3 text-lg leading-none text-slate-400 hover:text-red-500"
        >
          ×
        </button>
      ) : (
        <span className="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-slate-400">
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
          className="absolute z-30 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-slate-300 bg-white py-1 shadow-lg"
        >
          {entries.length === 0 && <li className="px-3 py-2 text-sm text-slate-500">{emptyLabel}</li>}
          {entries.map((o, i) => (
            <li
              key={o.value === "" ? "__all__" : o.value}
              role="option"
              aria-selected={o.value === value}
              onMouseDown={(e) => {
                e.preventDefault(); // évite de faire perdre le focus au champ avant le choix
                choose(o);
              }}
              onMouseEnter={() => setActive(i)}
              className={`cursor-pointer px-3 py-2 ${i === active ? "bg-slate-100" : ""}`}
            >
              <div
                className={`text-sm ${
                  o.value === "" ? "text-slate-600" : `text-brand-blue ${o.value === value ? "font-bold" : "font-medium"}`
                }`}
              >
                {o.label}
              </div>
              {o.description && <div className="text-xs text-slate-500">{o.description}</div>}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
