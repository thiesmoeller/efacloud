import { useEffect, useState } from "react";
import { api } from "../api/client";
import type { Person } from "../api/types";

type Props = {
  label: string;
  value: Person | null;
  onChange: (p: Person | null) => void;
};

export function PersonPicker({ label, value, onChange }: Props) {
  const [query, setQuery] = useState(value?.displayName ?? "");
  const [hits, setHits] = useState<Person[]>([]);
  const [open, setOpen] = useState(false);

  useEffect(() => {
    setQuery(value?.displayName ?? "");
  }, [value]);

  useEffect(() => {
    if (!open || query.trim().length < 1) {
      setHits([]);
      return;
    }
    const t = window.setTimeout(() => {
      api
        .searchPersons(query.trim())
        .then((r) => setHits(r.persons.slice(0, 12)))
        .catch(() => setHits([]));
    }, 200);
    return () => window.clearTimeout(t);
  }, [query, open]);

  return (
    <div className="field">
      <label>{label}</label>
      <input
        value={query}
        placeholder="Name suchen…"
        autoComplete="off"
        onFocus={() => setOpen(true)}
        onChange={(e) => {
          setQuery(e.target.value);
          setOpen(true);
          if (!e.target.value) onChange(null);
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
        <button type="button" className="btn btn-ghost" onClick={() => onChange(null)}>
          Leeren
        </button>
      )}
    </div>
  );
}
