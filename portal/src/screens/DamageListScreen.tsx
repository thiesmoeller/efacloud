import { useEffect, useMemo, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { api } from "../api/client";
import { PortalApiError } from "../api/errors";
import type { DamageRecord } from "../api/types";
import { ScreenHeader } from "../components/ScreenHeader";

export function DamageListScreen() {
  const { boatId = "" } = useParams();
  const navigate = useNavigate();
  const [damages, setDamages] = useState<DamageRecord[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [tab, setTab] = useState<"open" | "history">("open");

  useEffect(() => {
    let cancelled = false;
    api
      .listDamages(boatId, false)
      .then((r) => {
        if (!cancelled) setDamages(r.damages);
      })
      .catch((err) => {
        if (!cancelled) {
          setError(err instanceof PortalApiError ? err.message : "Schäden nicht geladen.");
        }
      });
    return () => {
      cancelled = true;
    };
  }, [boatId]);

  const open = useMemo(() => damages.filter((d) => !d.fixed), [damages]);
  const fixed = useMemo(() => damages.filter((d) => d.fixed), [damages]);
  const list = tab === "open" ? open : fixed;

  return (
    <div className="screen">
      <ScreenHeader title="Schäden" backTo={`/boats/${encodeURIComponent(boatId)}`} />
      <div className="chip-row">
        <button
          type="button"
          className={`chip${tab === "open" ? " active" : ""}`}
          onClick={() => setTab("open")}
        >
          Offen ({open.length})
        </button>
        <button
          type="button"
          className={`chip${tab === "history" ? " active" : ""}`}
          onClick={() => setTab("history")}
        >
          Behoben ({fixed.length})
        </button>
      </div>
      {error && <div className="error-box">{error}</div>}
      {list.length === 0 && <div className="empty">Keine Einträge.</div>}
      <div className="stack">
        {list.map((d) => (
          <div key={d.damage} className={`damage-item sev-${d.severity}`}>
            <strong>{d.severityLabel}</strong>
            <span>{d.description}</span>
            <span className="muted" style={{ fontSize: "0.85rem" }}>
              {d.reportDate} {d.reportTime}
              {d.reportedByPersonName ? ` · ${d.reportedByPersonName}` : ""}
            </span>
          </div>
        ))}
      </div>
      <div className="row-actions">
        <button
          type="button"
          className="btn btn-primary btn-block"
          onClick={() => navigate(`/boats/${encodeURIComponent(boatId)}/damages/new`)}
        >
          Schaden melden
        </button>
      </div>
    </div>
  );
}
