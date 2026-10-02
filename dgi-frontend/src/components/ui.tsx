import { useState, type InputHTMLAttributes, type ReactNode } from "react";
import type { Role } from "../types";
import { ROLE_LABELS } from "../types";

export const inputClass =
  "w-full rounded-lg border border-gray-300 px-3 py-2 text-gray-800 placeholder-gray-400 " +
  "focus:outline-none focus:ring-2 focus:ring-brand-green focus:border-transparent transition " +
  "disabled:bg-gray-100 disabled:text-gray-500";

export const primaryButtonClass =
  "rounded-lg bg-brand-blue text-white font-medium px-4 py-2 " +
  "hover:bg-opacity-90 disabled:opacity-50 disabled:cursor-not-allowed transition";

export const secondaryButtonClass =
  "rounded-lg border border-gray-300 bg-white text-gray-700 font-medium px-4 py-2 " +
  "hover:bg-gray-50 disabled:opacity-50 transition";

export const dangerButtonClass =
  "rounded-lg bg-red-600 text-white font-medium px-4 py-2 hover:bg-red-700 disabled:opacity-50 transition";

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
        className="absolute inset-y-0 right-0 flex items-center px-3 text-gray-400 hover:text-brand-blue"
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
    <p className="text-sm text-red-600 bg-red-50 border border-red-200 rounded-lg px-3 py-2 mb-4">
      {message}
    </p>
  );
}

export function Field({ label, children }: { label: string; children: ReactNode }) {
  return (
    <label className="block">
      <span className="block text-sm font-medium text-brand-blue mb-1">{label}</span>
      {children}
    </label>
  );
}

const ROLE_BADGE: Record<Role, string> = {
  agent: "bg-gray-100 text-gray-700",
  admin: "bg-brand-green/30 text-brand-blue",
  central: "bg-blue-100 text-blue-800",
  superadmin: "bg-brand-blue text-white",
};

export function RoleBadge({ role }: { role: Role | null }) {
  if (!role) return <span className="text-gray-400">—</span>;
  return (
    <span className={`inline-block rounded-full px-2.5 py-0.5 text-xs font-semibold ${ROLE_BADGE[role]}`}>
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
      className="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4"
      onMouseDown={(e) => {
        if (e.target === e.currentTarget) onClose();
      }}
    >
      <div className="w-full max-w-lg max-h-[90vh] overflow-y-auto bg-white rounded-2xl shadow-xl p-6">
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-bold text-brand-blue">{title}</h2>
          <button
            type="button"
            onClick={onClose}
            className="text-gray-400 hover:text-gray-600 text-2xl leading-none"
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

export function ConfirmDialog({
  message,
  loading,
  onConfirm,
  onCancel,
}: {
  message: string;
  loading: boolean;
  onConfirm: () => void;
  onCancel: () => void;
}) {
  return (
    <Modal title="Confirmation" onClose={onCancel}>
      <p className="text-sm text-gray-700 mb-6">{message}</p>
      <div className="flex justify-end gap-3">
        <button type="button" className={secondaryButtonClass} onClick={onCancel} disabled={loading}>
          Annuler
        </button>
        <button type="button" className={dangerButtonClass} onClick={onConfirm} disabled={loading}>
          {loading ? "Suppression..." : "Supprimer"}
        </button>
      </div>
    </Modal>
  );
}
