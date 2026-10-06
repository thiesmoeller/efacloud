import { useEffect, useState, useId, useRef } from "react";
import { api } from "../api/client";
import type { Person } from "../api/types";

type Props = {
  label: string;
  value: Person | null;
  onChange: (p: Person | null) => void;
};

export function PersonPicker({ label, value, onChange }: Props) {
  const inputId = useId();
  const editing = useRef(false);
  const [query, setQuery] = useState(value?.displayName ?? "");
  const [hits, setHits] = useState<Person[]>([]);
  const [open, setOpen] = useState(false);

  useEffect(() => {
    if (value || !editing.current) setQuery(value?.displayName ?? "");
    editing.current = false;
  }, [value]);

  useEffect(() => {
    if (!open || query.trim().length < 1) {
      setHits([]);
      return;
    }
    let cancelled = false;
    const t = window.setTimeout(() => {
      api
        .searchPersons(query.trim())
        .then((r) => { if (!cancelled) setHits(r.persons.slice(0, 12)); })
        .catch(() => setHits([]));
    }, 200);
    return () => { cancelled = true; window.clearTimeout(t); };
  }, [query, open]);

  return (
    <div className="field">
      <label htmlFor={inputId}>{label}</label>
      <input
        id={inputId}
        onKeyDown={e => { if (e.key === "Escape") setOpen(false); }}
        value={query}
        placeholder="Name suchen…"
        autoComplete="off"
        onFocus={() => setOpen(true)}
        onChange={(e) => {
          setQuery(e.target.value);
          setOpen(true);
          if (value) { editing.current = true; onChange(null); }
        }}
      />
      {open && hits.length > 0 && (
        <ul className="person-suggest">
          {hits.map((p) => (
            <li key={p.id}>
              <button
                type="button"
                onClick={() => {
                  onChange(p);
                  setQuery(p.displayName);
                  setOpen(false);
                }}
              >
                {p.displayName}
              </button>
            </li>
          ))}
        </ul>
      )}
      {value && (
        <button type="button" className="btn btn-ghost" onClick={() => { onChange(null); setQuery(""); }}>
          Leeren
        </button>
      )}
    </div>
  );
}
