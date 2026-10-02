import { useEffect, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { api } from "../api/client";
import { PortalApiError } from "../api/errors";
import type { BoatDetailResponse } from "../api/types";
import { badgeLabel } from "../lib/badges";
import { ScreenHeader } from "../components/ScreenHeader";

export function VariantPickScreen() {
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

  return (
    <div className="screen">
      <ScreenHeader title="Konfiguration" backTo="/" />
      <p className="muted" style={{ margin: 0 }}>
        {name} — Variante wählen
      </p>
      {error && <div className="error-box">{error}</div>}
      <ul className="boat-list">
        {(detail?.variants ?? []).map((v) => {
          const rig = badgeLabel("rigging", v.typeRigging);
          const cox = badgeLabel("coxing", v.typeCoxing);
          const hull = badgeLabel("hullType", v.typeType);
          return (
            <li key={`${v.variant}-${v.variantIndex}`}>
              <button
                type="button"
                className="boat-row"
                onClick={() =>
                  navigate(
                    `/trips/new?boatId=${encodeURIComponent(boatId)}&variant=${encodeURIComponent(v.variant)}`
                  )
                }
              >
                <span className="boat-row-title">
                  {v.seatCategoryLabel}
                  {v.typeDescription ? ` — ${v.typeDescription}` : ""}
                </span>
                <span className="boat-row-meta">
                  {rig && <span className="badge">{rig}</span>}
                  {cox && <span className="badge">{cox}</span>}
                  {hull && <span className="badge">{hull}</span>}
                  <span className="badge">Var. {v.variant}</span>
                </span>
              </button>
            </li>
          );
        })}
      </ul>
      <button
        type="button"
        className="btn btn-secondary btn-block"
        onClick={() => navigate(`/boats/${encodeURIComponent(boatId)}`)}
      >
        Boot & Schäden ansehen
      </button>
    </div>
  );
}
