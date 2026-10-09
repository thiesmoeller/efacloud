import { useCallback, useEffect, useState, useId, useRef } from "react";
import { api } from "../api/client";
import type { Person } from "../api/types";
import { useAuth } from "../auth/AuthContext";
import { rankPersonsByRecent, readRecentPersonIds, rememberPerson } from "../lib/recentPersons";

type Props = {
  label: string;
  value: Person | null;
  onChange: (p: Person | null) => void;
};

export function PersonPicker({ label, value, onChange }: Props) {
  const inputId = useId();
  const listId = `${inputId}-suggestions`;
  const { user } = useAuth();
  const editing = useRef(false);
  const inputRef = useRef<HTMLInputElement>(null);
  const [query, setQuery] = useState(value?.displayName ?? "");
  const [hits, setHits] = useState<Person[]>([]);
  const [open, setOpen] = useState(false);
  const [activeIndex, setActiveIndex] = useState(-1);
  const [suggestionHeight, setSuggestionHeight] = useState(180);

  const fitSuggestionsAboveKeyboard = useCallback(() => {
    const input = inputRef.current;
    if (!input || !open) return;
    const viewport = window.visualViewport;
    const top = viewport?.offsetTop ?? 0;
    const bottom = top + (viewport?.height ?? window.innerHeight);
    const rect = input.getBoundingClientRect();
    const available = Math.max(48, Math.min(180, bottom - rect.bottom - 12));
    setSuggestionHeight(available);

    const wantedTop = top + 12;
    const wantedBottom = bottom - available - 12;
    if (rect.top < wantedTop) window.scrollBy({ top: rect.top - wantedTop, behavior: "smooth" });
    else if (rect.bottom > wantedBottom) window.scrollBy({ top: rect.bottom - wantedBottom, behavior: "smooth" });
  }, [open]);

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
        .then((r) => {
          if (cancelled) return;
          const recent = user ? readRecentPersonIds(window.localStorage, user.efaCloudUserID) : [];
          setHits(rankPersonsByRecent(r.persons, recent).slice(0, 12));
          setActiveIndex(-1);
          window.requestAnimationFrame(fitSuggestionsAboveKeyboard);
        })
        .catch(() => setHits([]));
    }, 200);
    return () => { cancelled = true; window.clearTimeout(t); };
  }, [query, open, user, fitSuggestionsAboveKeyboard]);

  useEffect(() => {
    if (!open) return;
    const viewport = window.visualViewport;
    const resize = () => window.requestAnimationFrame(fitSuggestionsAboveKeyboard);
    viewport?.addEventListener("resize", resize);
    viewport?.addEventListener("scroll", resize);
    window.addEventListener("resize", resize);
    return () => {
      viewport?.removeEventListener("resize", resize);
      viewport?.removeEventListener("scroll", resize);
      window.removeEventListener("resize", resize);
    };
  }, [open, fitSuggestionsAboveKeyboard]);

  const selectPerson = (person: Person) => {
    if (user) rememberPerson(window.localStorage, user.efaCloudUserID, person.id);
    onChange(person);
    setQuery(person.displayName);
    setOpen(false);
    setActiveIndex(-1);
  };

  return (
    <div className="field">
      <label htmlFor={inputId}>{label}</label>
      <input
        ref={inputRef}
        id={inputId}
        role="combobox"
        aria-autocomplete="list"
        aria-expanded={open && hits.length > 0}
        aria-controls={listId}
        aria-activedescendant={activeIndex >= 0 ? `${listId}-${activeIndex}` : undefined}
        onKeyDown={e => {
          if (e.key === "Escape") { setOpen(false); setActiveIndex(-1); }
          else if (e.key === "ArrowDown" && hits.length) { e.preventDefault(); setActiveIndex(i => Math.min(i + 1, hits.length - 1)); }
          else if (e.key === "ArrowUp" && hits.length) { e.preventDefault(); setActiveIndex(i => Math.max(i - 1, 0)); }
          else if (e.key === "Enter" && activeIndex >= 0) { e.preventDefault(); selectPerson(hits[activeIndex]); }
        }}
        value={query}
        placeholder="Name suchen…"
        autoComplete="off"
        onFocus={() => { setOpen(true); window.requestAnimationFrame(fitSuggestionsAboveKeyboard); }}
        onChange={(e) => {
          setQuery(e.target.value);
          setOpen(true);
          if (value) { editing.current = true; onChange(null); }
        }}
      />
      {open && hits.length > 0 && (
        <ul id={listId} role="listbox" className="person-suggest" style={{ maxHeight: suggestionHeight }}>
          {hits.map((p, index) => (
            <li key={p.id} id={`${listId}-${index}`} role="option" aria-selected={activeIndex === index}>
              <button
                type="button"
                tabIndex={-1}
                onPointerDown={e => e.preventDefault()}
                onClick={() => selectPerson(p)}
              >
                {p.displayName}
              </button>
            </li>
          ))}
        </ul>
      )}
      {value && (
        <button type="button" className="btn btn-ghost" onClick={() => { onChange(null); setQuery(""); inputRef.current?.focus(); }}>
          Leeren
        </button>
      )}
    </div>
  );
}
