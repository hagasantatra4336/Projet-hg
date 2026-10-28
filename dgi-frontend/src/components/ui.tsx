import { useState, type ButtonHTMLAttributes, type InputHTMLAttributes, type ReactNode } from "react";
import type { Role } from "../types";
import { ROLE_LABELS } from "../types";
import { IconEdit, IconTrash } from "./icons";

export const inputClass =
  "w-full rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-slate-900 placeholder-slate-400 " +
  "focus:outline-none focus:border-brand-blue focus:ring-1 focus:ring-brand-blue transition " +
  "disabled:bg-slate-100 disabled:text-slate-500";

export const primaryButtonClass =
  "rounded-lg bg-brand-blue text-white font-medium px-3 py-1.5 " +
  "hover:bg-brand-blue-light disabled:opacity-50 disabled:cursor-not-allowed transition";

export const secondaryButtonClass =
  "rounded-lg border border-slate-300 bg-white text-slate-700 font-medium px-3 py-1.5 " +
  "hover:bg-slate-50 disabled:opacity-50 transition";

export const dangerButtonClass =
  "rounded-lg bg-red-600 text-white font-medium px-3 py-1.5 hover:bg-red-700 disabled:opacity-50 transition";

export type IconButtonTone = "primary" | "neutral" | "danger";

const ICON_BUTTON_TONE: Record<IconButtonTone, string> = {
  primary: "bg-brand-blue text-white hover:bg-brand-blue-light",
  neutral: "border border-slate-300 bg-white text-slate-700 hover:bg-slate-50",
  danger: "border border-red-200 bg-white text-red-600 hover:bg-red-50",
};

/** Classes d'un bouton carré icône (36 px) — utilisable aussi sur un <Link>. */
export function iconButtonClass(tone: IconButtonTone = "neutral"): string {
  return (
    "inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg transition " +
    "focus:outline-none focus:ring-2 focus:ring-brand-blue " +
    "disabled:opacity-50 disabled:cursor-not-allowed " +
    ICON_BUTTON_TONE[tone]
  );
}

/** Bouton icône compact ; l'infobulle et le libellé accessible portent le sens de l'action. */
export function IconButton({
  label,
  tone = "neutral",
  className = "",
  type = "button",
  ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & { label: string; tone?: IconButtonTone }) {
  return (
    <button
      {...props}
      type={type}
      title={label}
      aria-label={label}
      className={`${iconButtonClass(tone)} ${className}`}
    />
  );
}

/** Champ mot de passe avec bouton « afficher / masquer ». Accepte les mêmes props qu'un <input>. */
export function PasswordInput({
  className,
  ...props
}: Omit<InputHTMLAttributes<HTMLInputElement>, "type">) {
  const [visible, setVisible] = useState<boolean>(false);

  return (
    <div className="relative">
      <input {...props} type={visible ? "text" : "password"} className={`${className ?? inputClass} pr-11`} />
      <button
        type="button"
        onClick={() => setVisible((v) => !v)}
        aria-label={visible ? "Masquer le mot de passe" : "Afficher le mot de passe"}
        title={visible ? "Masquer le mot de passe" : "Afficher le mot de passe"}
        className="absolute inset-y-0 right-0 flex items-center px-3 text-slate-400 hover:text-brand-blue"
      >
        <svg
          xmlns="http://www.w3.org/2000/svg"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth={1.8}
          strokeLinecap="round"
          strokeLinejoin="round"
          className="h-5 w-5"
          aria-hidden="true"
        >
          {visible ? (
            <>
              <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94" />
              <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19" />
              <path d="M14.12 14.12a3 3 0 1 1-4.24-4.24" />
              <line x1="1" y1="1" x2="23" y2="23" />
            </>
          ) : (
            <>
              <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
              <circle cx="12" cy="12" r="3" />
            </>
          )}
        </svg>
      </button>
    </div>
  );
}

export function ErrorBox({ message }: { message: string }) {
  if (!message) return null;
  return (
    <p className="text-sm text-red-700 bg-red-50 border border-red-200 rounded-lg px-3 py-2 mb-4">
      {message}
    </p>
  );
}

export function Field({
  label,
  children,
  hint,
  required,
  optional,
  className = "",
}: {
  label: string;
  children: ReactNode;
  hint?: ReactNode;
  required?: boolean;
  optional?: boolean;
  className?: string;
}) {
  return (
    <label className={`block ${className}`}>
      <span className="mb-1 flex items-baseline gap-1 text-sm font-medium text-slate-700">
        <span>{label}</span>
        {required && (
          <span className="text-red-600" aria-hidden="true" title="Champ obligatoire">
            *
          </span>
        )}
        {!required && optional && <span className="text-xs font-normal text-slate-400">facultatif</span>}
      </span>
      {children}
      {hint && <span className="mt-1 block text-xs leading-snug text-slate-500">{hint}</span>}
    </label>
  );
}

/** Grille de champs : une colonne sur mobile, deux dès 640 px. */
export function FormGrid({ children, className = "" }: { children: ReactNode; className?: string }) {
  return <div className={`grid gap-4 sm:grid-cols-2 ${className}`}>{children}</div>;
}

/** Bloc de formulaire avec un titre de section (au sein d'une même carte ou modale). */
export function FormSection({
  title,
  hint,
  children,
}: {
  title: string;
  hint?: ReactNode;
  children: ReactNode;
}) {
  return (
    <fieldset className="space-y-3">
      <legend className="mb-2 w-full border-b border-line pb-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500">
        {title}
      </legend>
      {hint && <p className="text-xs leading-snug text-slate-500">{hint}</p>}
      {children}
    </fieldset>
  );
}

/** Barre d'actions d'un formulaire : séparateur, alignement à droite, retour à la ligne sur mobile. */
export function FormActions({ children, className = "" }: { children: ReactNode; className?: string }) {
  return (
    <div className={`mt-6 flex flex-wrap items-center justify-end gap-3 border-t border-line pt-4 ${className}`}>
      {children}
    </div>
  );
}

const ROLE_BADGE: Record<Role, string> = {
  agent: "bg-slate-100 text-slate-700",
  admin: "bg-brand-blue/10 text-brand-blue",
  central: "bg-sky-100 text-sky-900",
  superadmin: "bg-brand-blue text-white",
};

export function RoleBadge({ role }: { role: Role | null }) {
  if (!role) return <span className="text-slate-400">—</span>;
  return (
    <span className={`inline-block rounded px-2 py-0.5 text-xs font-semibold ${ROLE_BADGE[role]}`}>
      {ROLE_LABELS[role]}
    </span>
  );
}

export function Modal({
  title,
  onClose,
  children,
}: {
  title: string;
  onClose: () => void;
  children: ReactNode;
}) {
  return (
    <div
      className="fixed inset-0 z-50 bg-brand-blue-dark/55 flex items-center justify-center p-4"
      onMouseDown={(e) => {
        if (e.target === e.currentTarget) onClose();
      }}
    >
      <div className="w-full max-w-lg max-h-[90vh] overflow-y-auto bg-white rounded-xl border border-line shadow-xl p-5">
        <div className="flex items-center justify-between mb-4 border-b border-line pb-2.5">
          <h2 className="text-lg font-semibold text-brand-blue">{title}</h2>
          <button
            type="button"
            onClick={onClose}
            className="text-slate-400 hover:text-slate-700 text-2xl leading-none"
            aria-label="Fermer"
          >
            ×
          </button>
        </div>
        {children}
      </div>
    </div>
  );
}

/** Boîte de confirmation : suppression (danger) ou action à conséquence (primary). */
export function ConfirmDialog({
  title = "Confirmation",
  message,
  detail,
  confirmLabel = "Supprimer",
  loadingLabel,
  tone = "danger",
  loading,
  onConfirm,
  onCancel,
}: {
  title?: string;
  message: ReactNode;
  detail?: ReactNode;
  confirmLabel?: string;
  loadingLabel?: string;
  tone?: "danger" | "primary";
  loading: boolean;
  onConfirm: () => void;
  onCancel: () => void;
}) {
  const danger = tone === "danger";

  return (
    <Modal title={title} onClose={loading ? () => undefined : onCancel}>
      <div className="flex items-start gap-4">
        <span
          className={`mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-full ${
            danger ? "bg-red-50 text-red-600" : "bg-brand-blue/10 text-brand-blue"
          }`}
          aria-hidden="true"
        >
          <svg
            xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth={1.8}
            strokeLinecap="round"
            strokeLinejoin="round"
            className="h-5 w-5"
          >
            {danger ? (
              <>
                <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
                <line x1="12" y1="9" x2="12" y2="13" />
                <line x1="12" y1="17" x2="12.01" y2="17" />
              </>
            ) : (
              <>
                <circle cx="12" cy="12" r="10" />
                <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3" />
                <line x1="12" y1="17" x2="12.01" y2="17" />
              </>
            )}
          </svg>
        </span>
        <div className="min-w-0 space-y-2">
          <p className="text-sm leading-relaxed text-slate-700">{message}</p>
          {detail && <div className="text-xs leading-snug text-slate-500">{detail}</div>}
        </div>
      </div>

      <div className="mt-6 flex flex-wrap justify-end gap-3">
        <button type="button" className={secondaryButtonClass} onClick={onCancel} disabled={loading} autoFocus>
          Annuler
        </button>
        <button
          type="button"
          className={danger ? dangerButtonClass : primaryButtonClass}
          onClick={onConfirm}
          disabled={loading}
        >
          {loading ? (loadingLabel ?? `${confirmLabel}...`) : confirmLabel}
        </button>
      </div>
    </Modal>
  );
}

/** Bouton icône « Modifier » (crayon). */
export function EditButton({ onClick, label = "Modifier" }: { onClick: () => void; label?: string }) {
  return (
    <button
      type="button"
      onClick={onClick}
      title={label}
      aria-label={label}
      className="inline-flex h-8 w-8 items-center justify-center rounded-lg text-brand-blue transition hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-brand-blue"
    >
      <IconEdit className="h-4 w-4" />
    </button>
  );
}

/** Bouton icône « Supprimer » (corbeille). */
export function DeleteButton({ onClick, label = "Supprimer" }: { onClick: () => void; label?: string }) {
  return (
    <button
      type="button"
      onClick={onClick}
      title={label}
      aria-label={label}
      className="inline-flex h-8 w-8 items-center justify-center rounded-lg text-red-600 transition hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-300"
    >
      <IconTrash className="h-4 w-4" />
    </button>
  );
}
