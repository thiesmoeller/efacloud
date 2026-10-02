import { useCallback, useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { api } from "../api/client";
import { PortalApiError } from "../api/errors";
import type { BoatVariantRow, BoatView, SeatCategory } from "../api/types";
import { useAuth } from "../auth/AuthContext";
import { badgeLabel } from "../lib/badges";
import { buildSeatFilterChips } from "../lib/seatFilters";
import { ScreenHeader } from "../components/ScreenHeader";

const VIEWS: Array<{ id: BoatView; label: string }> = [
  { id: "available", label: "Verfügbar" },
  { id: "onwater", label: "Auf Fahrt" },
  { id: "unavailable", label: "Nicht verfügbar" },
];

function groupByBoat(boats: BoatVariantRow[]): Map<string, BoatVariantRow[]> {
  const map = new Map<string, BoatVariantRow[]>();
  for (const row of boats) {
    const list = map.get(row.boatId) ?? [];
    list.push(row);
    map.set(row.boatId, list);
  }
  return map;
}

/** Preserve server order of first appearance of each boatId. */
function orderedBoatIds(boats: BoatVariantRow[]): string[] {
  const seen = new Set<string>();
  const ids: string[] = [];
  for (const b of boats) {
    if (!seen.has(b.boatId)) {
      seen.add(b.boatId);
      ids.push(b.boatId);
    }
  }
  return ids;
}

export function BoatListScreen() {
  const navigate = useNavigate();
  const { user, logout, privileges } = useAuth();
  const [view, setView] = useState<BoatView>("available");
  const [seatCategory, setSeatCategory] = useState("ALL");
  const [search, setSearch] = useState("");
  const [debouncedSearch, setDebouncedSearch] = useState("");
  const [riggingFilter, setRiggingFilter] = useState("");
  const [coxingFilter, setCoxingFilter] = useState("");
  const [hullFilter, setHullFilter] = useState("");
  const [boats, setBoats] = useState<BoatVariantRow[]>([]);
  const [seatCategories, setSeatCategories] = useState<SeatCategory[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const t = window.setTimeout(() => setDebouncedSearch(search.trim()), 250);
    return () => window.clearTimeout(t);
  }, [search]);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await api.listBoats({
        view: debouncedSearch ? undefined : view,
        seatCategory: debouncedSearch ? undefined : seatCategory,
        search: debouncedSearch || undefined,
      });
      setBoats(res.boats);
      setSeatCategories(res.seatCategories);
    } catch (err) {
      setError(err instanceof PortalApiError ? err.message : "Boote konnten nicht geladen werden.");
    } finally {
      setLoading(false);
    }
  }, [view, seatCategory, debouncedSearch]);

  useEffect(() => {
    void load();
  }, [load]);

  const chips = useMemo(() => buildSeatFilterChips(seatCategories), [seatCategories]);

  const filtered = useMemo(() => {
    return boats.filter((b) => {
      if (riggingFilter && b.typeRigging.toUpperCase() !== riggingFilter) return false;
      if (coxingFilter && b.typeCoxing.toUpperCase() !== coxingFilter) return false;
      if (hullFilter && b.typeType.toUpperCase() !== hullFilter) return false;
      return true;
    });
  }, [boats, riggingFilter, coxingFilter, hullFilter]);

  const byBoat = useMemo(() => groupByBoat(filtered), [filtered]);
  const boatIds = useMemo(() => orderedBoatIds(filtered), [filtered]);

  const onSelectBoat = (boatId: string) => {
    const variants = byBoat.get(boatId) ?? [];
    if (variants.length === 0) return;
    const first = variants[0];
    if (first.listView === "onwater" && first.entryNo) {
      navigate(`/trips/${encodeURIComponent(String(first.entryNo))}`);
      return;
    }
    if (variants.length === 1) {
      navigate(
        `/trips/new?boatId=${encodeURIComponent(boatId)}&variant=${encodeURIComponent(variants[0].variant)}`
      );
      return;
    }
    navigate(`/boats/${encodeURIComponent(boatId)}/variants`);
  };

  return (
    <div className="screen">
      <ScreenHeader
        title="Boote"
        trailing={
          <button type="button" className="btn btn-ghost" onClick={() => void logout()}>
            Abmelden
          </button>
        }
      />
      <p className="muted" style={{ margin: 0 }}>
        {user?.firstName} {user?.lastName}
        {privileges?.trainer ? " · Trainer" : ""}
      </p>

      <div className="view-tabs" role="tablist">
        {VIEWS.map((v) => (
          <button
            key={v.id}
            type="button"
            role="tab"
            aria-selected={view === v.id}
            className={`view-tab${view === v.id ? " active" : ""}`}
            onClick={() => setView(v.id)}
            disabled={!!debouncedSearch}
          >
            {v.label}
          </button>
        ))}
      </div>

      <div className="field">
        <label htmlFor="search">Suche (Name / Variante)</label>
        <input
          id="search"
          value={search}
          placeholder="z. B. Empacher…"
          onChange={(e) => setSearch(e.target.value)}
        />
      </div>

      {!debouncedSearch && (
        <div className="chip-row" aria-label="Sitzplätze">
          {chips.map((c) => (
            <button
              key={c.code}
              type="button"
              className={`chip${seatCategory === c.code ? " active" : ""}`}
              onClick={() => setSeatCategory(c.code)}
            >
              {c.label}
            </button>
          ))}
        </div>
      )}

      <details>
        <summary className="muted">Weitere Filter</summary>
        <div className="secondary-filters" style={{ marginTop: 8 }}>
          <select
            aria-label="Rigger"
            value={riggingFilter}
            onChange={(e) => setRiggingFilter(e.target.value)}
          >
            <option value="">Rigger: alle</option>
            <option value="SCULL">Skull</option>
            <option value="SWEEP">Riemen</option>
          </select>
          <select
            aria-label="Steuermann"
            value={coxingFilter}
            onChange={(e) => setCoxingFilter(e.target.value)}
          >
            <option value="">Stm.: alle</option>
            <option value="COXED">mit Stm.</option>
            <option value="COXLESS">ohne Stm.</option>
          </select>
          <select
            aria-label="Rumpftyp"
            value={hullFilter}
            onChange={(e) => setHullFilter(e.target.value)}
          >
            <option value="">Typ: alle</option>
            <option value="RACING">Rennboot</option>
            <option value="GIG">Gig</option>
            <option value="WHERRY">Wherry</option>
            <option value="OTHER">Sonstiges</option>
          </select>
        </div>
      </details>

      {error && <div className="error-box">{error}</div>}
      {loading && <p className="muted">Laden…</p>}

      {!loading && boatIds.length === 0 && (
        <div className="empty">Keine Boote in dieser Ansicht.</div>
      )}

      <ul className="boat-list">
        {boatIds.map((id) => {
          const variants = byBoat.get(id) ?? [];
          const primary = variants[0];
          const rig = badgeLabel("rigging", primary.badges.rigging);
          const cox = badgeLabel("coxing", primary.badges.coxing);
          const hull = badgeLabel("hullType", primary.badges.hullType);
          return (
            <li key={id}>
              <button type="button" className="boat-row" onClick={() => onSelectBoat(id)}>
                <span className="boat-row-title">{primary.name}</span>
                <span className="boat-row-meta">
                  <span className="badge">{primary.seatCategoryLabel}</span>
                  {rig && <span className="badge">{rig}</span>}
                  {cox && <span className="badge">{cox}</span>}
                  {hull && <span className="badge">{hull}</span>}
                  {variants.length > 1 && (
                    <span className="badge">{variants.length} Konfig.</span>
                  )}
                  {primary.damage?.hasOpen && (
                    <span className="badge badge-damage">
                      Schaden{primary.damage.count > 1 ? ` (${primary.damage.count})` : ""}
                    </span>
                  )}
                  {primary.statusComment && (
                    <span className="badge badge-warn">{primary.statusComment}</span>
                  )}
                </span>
                {primary.typeDescription && (
                  <span className="muted" style={{ fontSize: "0.85rem" }}>
                    {primary.typeDescription}
                  </span>
                )}
              </button>
            </li>
          );
        })}
      </ul>
    </div>
  );
}
