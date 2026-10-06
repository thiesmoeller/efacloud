import { useEffect, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { api } from "../api/client";
import { PortalApiError } from "../api/errors";
import type { BoatDetailResponse } from "../api/types";
import { ScreenHeader } from "../components/ScreenHeader";

export function BoatDetailScreen() {
  const { boatId = "" } = useParams();
  const navigate = useNavigate();
  const [detail, setDetail] = useState<BoatDetailResponse | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    api
      .getBoat(boatId)
      .then((d) => {
        if (!cancelled) setDetail(d);
      })
      .catch((err) => {
        if (!cancelled) {
          setError(err instanceof PortalApiError ? err.message : "Boot nicht geladen.");
        }
      });
    return () => {
      cancelled = true;
    };
  }, [boatId]);

  const name = String(detail?.boat?.Name ?? "Boot");
  const open = detail?.openDamages ?? [];

  return (
    <div className="screen">
      <ScreenHeader title={name} backTo="/" />
      {error && <div className="error-box">{error}</div>}
      {detail && (
        <>
          <p className="muted" style={{ margin: 0 }}>
            Status: {({ available: "Verfügbar", onwater: "Auf Fahrt", unavailable: "Nicht verfügbar" } as Record<string, string>)[detail.listView] ?? detail.listView}
            {detail.status?.Comment ? ` — ${String(detail.status.Comment)}` : ""}
          </p>

          <h2 style={{ fontFamily: "var(--font-display)", fontSize: "1.1rem", margin: "8px 0 0" }}>
            Offene Schäden
          </h2>
          {open.length === 0 && <p className="muted">Keine offenen Schäden.</p>}
          <div className="stack">
            {open.map((d) => (
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
              disabled={detail.listView === "onwater"}
              onClick={() => {
                if ((detail.variants?.length ?? 0) > 1) {
                  navigate(`/boats/${encodeURIComponent(boatId)}/variants`);
                } else {
                  const v = detail.variants?.[0]?.variant ?? "1";
                  navigate(
                    `/trips/new?boatId=${encodeURIComponent(boatId)}&variant=${encodeURIComponent(v)}`
                  );
                }
              }}
            >
              Fahrt starten
            </button>
            <button
              type="button"
              className="btn btn-secondary btn-block"
              onClick={() => navigate(`/boats/${encodeURIComponent(boatId)}/damages`)}
            >
              Alle Schäden / Historie
            </button>
            <button
              type="button"
              className="btn btn-secondary btn-block"
              onClick={() => navigate(`/boats/${encodeURIComponent(boatId)}/damages/new`)}
            >
              Schaden melden
            </button>
          </div>
        </>
      )}
    </div>
  );
}
