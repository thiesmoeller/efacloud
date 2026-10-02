import { useCallback, useEffect, useMemo, useState, type FormEvent } from "react";
import { useNavigate, useParams, useSearchParams } from "react-router-dom";
import { api } from "../api/client";
import { PortalApiError } from "../api/errors";
import type {
  AckCheck,
  AckKind,
  BoatDetailResponse,
  DamageSeverity,
  Destination,
  Person,
  Trip,
} from "../api/types";
import { AckDialog } from "../components/AckDialog";
import { PersonPicker } from "../components/PersonPicker";
import { ScreenHeader } from "../components/ScreenHeader";
import { SeverityPicker } from "../components/SeverityPicker";
import {
  clearTokensForStale,
  confirmAcknowledgment,
  extractAckChecks,
  isAckFlowError,
  nextAckDialog,
} from "../lib/ackFlow";
import { isCoxed, seatCountFromCategory } from "../lib/badges";
import { newIdempotencyKey } from "../lib/idempotency";

type Mode = "start" | "correct" | "finish";

export function TripFormScreen() {
  const navigate = useNavigate();
  const { entryId } = useParams();
  const [params] = useSearchParams();
  const boatIdParam = params.get("boatId") ?? "";
  const variantParam = params.get("variant") ?? "1";

  const isExisting = !!entryId && entryId !== "new";
  const [mode, setMode] = useState<Mode>(isExisting ? "correct" : "start");
  const [trip, setTrip] = useState<Trip | null>(null);
  const [detail, setDetail] = useState<BoatDetailResponse | null>(null);
  const [destinations, setDestinations] = useState<Destination[]>([]);
  const [config, setConfig] = useState<Record<string, unknown>>({});

  const [boatId, setBoatId] = useState(boatIdParam);
  const [variant, setVariant] = useState(variantParam);
  const [crew, setCrew] = useState<Array<Person | null>>([null]);
  const [cox, setCox] = useState<Person | null>(null);
  const [boatCaptain, setBoatCaptain] = useState("");
  const [destinationId, setDestinationId] = useState("");
  const [distance, setDistance] = useState("");
  const [endTime, setEndTime] = useState("");
  const [endDate, setEndDate] = useState("");

  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [ackTokens, setAckTokens] = useState<string[]>([]);
  const [ackQueue, setAckQueue] = useState<AckCheck[]>([]);
  const [currentAck, setCurrentAck] = useState<AckCheck | null>(null);
  const [pendingAction, setPendingAction] = useState<"start" | "correct" | null>(null);
  const [idemKey] = useState(() => newIdempotencyKey());
  const [abortOpen, setAbortOpen] = useState(false);
  const [abortWithDamage, setAbortWithDamage] = useState(false);
  const [abortSeverity, setAbortSeverity] = useState<DamageSeverity>("LIMITEDUSEABLE");
  const [abortDesc, setAbortDesc] = useState("");

  const selectedVariant = useMemo(() => {
    return detail?.variants.find((v) => String(v.variant) === String(variant)) ?? detail?.variants[0];
  }, [detail, variant]);

  const coxNeeded = selectedVariant ? isCoxed(selectedVariant.typeCoxing) : false;
  const seatCount = selectedVariant
    ? seatCountFromCategory(selectedVariant.seatCategory)
    : crew.length;

  useEffect(() => {
    setCrew((prev) => {
      const next = [...prev];
      while (next.length < seatCount) next.push(null);
      return next.slice(0, Math.max(seatCount, 1));
    });
  }, [seatCount]);

  useEffect(() => {
    if (!coxNeeded) setCox(null);
  }, [coxNeeded]);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const [cfg, dests] = await Promise.all([api.getConfig(), api.listDestinations()]);
        if (cancelled) return;
        setConfig(cfg.config);
        setDestinations(dests.destinations);

        if (isExisting && entryId) {
          const { trip: t } = await api.getTrip(entryId);
          if (cancelled) return;
          setTrip(t);
          setBoatId(t.boatId);
          setVariant(t.boatVariant || "1");
          setDestinationId(t.destinationId || "");
          setDistance(t.distance || "");
          setBoatCaptain(t.boatCaptain || "");
          setCox(t.cox?.id ? { id: t.cox.id, firstName: "", lastName: "", displayName: t.cox.name } : null);
          const crewPersons: Array<Person | null> = t.crew.map((c) =>
            c.id
              ? { id: c.id, firstName: "", lastName: "", displayName: c.name }
              : null
          );
          setCrew(crewPersons.length ? crewPersons : [null]);
          const d = await api.getBoat(t.boatId);
          if (!cancelled) setDetail(d);
        } else if (boatIdParam) {
          const d = await api.getBoat(boatIdParam);
          if (cancelled) return;
          setDetail(d);
          setBoatId(boatIdParam);
          const defaultDest = String(d.boat?.DefaultDestinationId ?? "");
          if (defaultDest) setDestinationId(defaultDest);
        }
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof PortalApiError ? err.message : "Daten konnten nicht geladen werden.");
        }
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [boatIdParam, entryId, isExisting]);

  const beginAckFlow = (err: PortalApiError, action: "start" | "correct") => {
    const { checks, required, stale } = extractAckChecks(err);
    let tokens = ackTokens;
    if (err.isAckStale) {
      tokens = clearTokensForStale(tokens, stale);
      setAckTokens(tokens);
    }
    const kinds = err.isAckStale && stale.length ? stale : required;
    const dialog = nextAckDialog(checks, kinds.length ? kinds : undefined, stale);
    if (!dialog) {
      setError(err.message);
      return;
    }
    setPendingAction(action);
    setCurrentAck(dialog.check);
    setAckQueue(dialog.remaining);
  };

  const runStart = useCallback(
    async (tokens: string[]) => {
      setBusy(true);
      setError(null);
      setSuccess(null);
      try {
        const body = {
          boatId,
          boatVariant: variant,
          crew: crew.filter((c): c is Person => !!c?.id).map((c) => ({ id: c.id, name: c.displayName })),
          coxId: coxNeeded && cox?.id ? cox.id : undefined,
          boatCaptain: boatCaptain || undefined,
          destinationId: destinationId || undefined,
          destinationName: destinations.find((d) => d.id === destinationId)?.name,
          distance: distance || undefined,
          acknowledgmentTokens: tokens,
          idempotencyKey: idemKey,
        };
        const res = await api.startTrip(body);
        setSuccess("Fahrt gestartet.");
        setTrip(res.trip);
        navigate(`/trips/${encodeURIComponent(res.trip.entryId)}`, { replace: true });
      } catch (err) {
        if (isAckFlowError(err)) {
          beginAckFlow(err, "start");
        } else if (err instanceof PortalApiError) {
          setError(err.message);
        } else {
          setError("Start fehlgeschlagen.");
        }
      } finally {
        setBusy(false);
      }
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [boatId, variant, crew, cox, coxNeeded, boatCaptain, destinationId, destinations, distance, idemKey, navigate]
  );

  const runCorrect = useCallback(
    async (tokens: string[]) => {
      if (!trip) return;
      setBusy(true);
      setError(null);
      try {
        const res = await api.correctTrip(trip.entryId, {
          expectedChangeCount: trip.changeCount,
          boatId,
          boatVariant: variant,
          crew: crew.filter((c): c is Person => !!c?.id).map((c) => ({ id: c.id, name: c.displayName })),
          coxId: coxNeeded && cox?.id ? cox.id : "",
          boatCaptain: boatCaptain || "",
          destinationId: destinationId || "",
          destinationName: destinations.find((d) => d.id === destinationId)?.name ?? "",
          distance: distance || "",
          acknowledgmentTokens: tokens,
          idempotencyKey: newIdempotencyKey(),
        });
        setTrip(res.trip);
        setSuccess("Fahrt korrigiert.");
        setAckTokens([]);
      } catch (err) {
        if (isAckFlowError(err)) {
          beginAckFlow(err, "correct");
        } else if (err instanceof PortalApiError) {
          setError(err.message);
        } else {
          setError("Korrektur fehlgeschlagen.");
        }
      } finally {
        setBusy(false);
      }
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [trip, boatId, variant, crew, cox, coxNeeded, boatCaptain, destinationId, destinations, distance]
  );

  const onConfirmAck = async () => {
    if (!currentAck) return;
    setBusy(true);
    try {
      const tokens = await confirmAcknowledgment(currentAck.kind as AckKind, boatId, ackTokens);
      setAckTokens(tokens);
      if (ackQueue.length > 0) {
        const [next, ...rest] = ackQueue;
        setCurrentAck(next);
        setAckQueue(rest);
        setBusy(false);
        return;
      }
      setCurrentAck(null);
      const action = pendingAction;
      setPendingAction(null);
      if (action === "start") await runStart(tokens);
      else if (action === "correct") await runCorrect(tokens);
    } catch (err) {
      setCurrentAck(null);
      setError(err instanceof PortalApiError ? err.message : "Bestätigung fehlgeschlagen.");
      setBusy(false);
    }
  };

  const onCancelAck = () => {
    setCurrentAck(null);
    setAckQueue([]);
    setPendingAction(null);
    setError("Start abgebrochen.");
  };

  const onSubmitStart = (e: FormEvent) => {
    e.preventDefault();
    void runStart(ackTokens);
  };

  const onFinish = async () => {
    if (!trip) return;
    setBusy(true);
    setError(null);
    try {
      const res = await api.finishTrip(trip.entryId, {
        expectedChangeCount: trip.changeCount,
        distance: distance || undefined,
        endTime: endTime || undefined,
        endDate: endDate || undefined,
        destinationId: destinationId || undefined,
        destinationName: destinations.find((d) => d.id === destinationId)?.name,
        idempotencyKey: newIdempotencyKey(),
      });
      setTrip(res.trip);
      setSuccess("Fahrt beendet.");
      setTimeout(() => navigate("/"), 800);
    } catch (err) {
      setError(err instanceof PortalApiError ? err.message : "Beenden fehlgeschlagen.");
    } finally {
      setBusy(false);
    }
  };

  const onAbort = async () => {
    if (!trip) return;
    setBusy(true);
    setError(null);
    try {
      await api.abortTrip(trip.entryId, {
        expectedChangeCount: trip.changeCount,
        idempotencyKey: newIdempotencyKey(),
        withDamage: abortWithDamage
          ? { severity: abortSeverity, description: abortDesc }
          : undefined,
      });
      setAbortOpen(false);
      setSuccess(
        abortWithDamage
          ? "Fahrt abgebrochen und Schaden gemeldet."
          : "Fahrt abgebrochen (hat nicht stattgefunden)."
      );
      setTimeout(() => navigate("/"), 800);
    } catch (err) {
      setError(err instanceof PortalApiError ? err.message : "Abbruch fehlgeschlagen.");
    } finally {
      setBusy(false);
    }
  };

  const boatName = String(detail?.boat?.Name ?? trip?.boatName ?? "Fahrt");
  const mustDest = !!config.StartSessionMustSelectDestination;
  const showCaptain = config.BoatCaptainShow !== false;

  const captainOptions = useMemo(() => {
    const opts: Array<{ value: string; label: string }> = [{ value: "", label: "— keiner —" }];
    if (cox?.id) opts.push({ value: "0", label: `Stm. ${cox.displayName}` });
    crew.forEach((c, i) => {
      if (c?.id) opts.push({ value: String(i + 1), label: `${i + 1}. ${c.displayName}` });
    });
    return opts;
  }, [cox, crew]);

  return (
    <div className="screen">
      <ScreenHeader
        title={
          !isExisting
            ? "Fahrt starten"
            : mode === "finish"
              ? "Fahrt beenden"
              : "Fahrt korrigieren"
        }
        backTo="/"
      />
      <p className="muted" style={{ margin: 0 }}>
        {boatName}
        {selectedVariant ? ` · ${selectedVariant.seatCategoryLabel}` : ""}
        {variant ? ` · Var. ${variant}` : ""}
      </p>

      {(detail?.openDamages?.length ?? 0) > 0 && (
        <div className="error-box">
          Offene Schäden ({detail!.openDamages.length}):{" "}
          {detail!.openDamages[0].severityLabel} — {detail!.openDamages[0].description}
          <div style={{ marginTop: 8 }}>
            <button
              type="button"
              className="btn btn-secondary"
              onClick={() => navigate(`/boats/${encodeURIComponent(boatId)}/damages`)}
            >
              Schäden ansehen
            </button>
          </div>
        </div>
      )}

      {error && <div className="error-box">{error}</div>}
      {success && <div className="success-box">{success}</div>}

      {isExisting && trip && (
        <div className="chip-row">
          <button
            type="button"
            className={`chip${mode === "correct" ? " active" : ""}`}
            onClick={() => setMode("correct")}
          >
            Korrigieren
          </button>
          <button
            type="button"
            className={`chip${mode === "finish" ? " active" : ""}`}
            onClick={() => setMode("finish")}
          >
            Beenden
          </button>
        </div>
      )}

      <form
        className="stack"
        onSubmit={(e) => {
          e.preventDefault();
          if (!isExisting) onSubmitStart(e);
          else if (mode === "correct") void runCorrect(ackTokens);
          else void onFinish();
        }}
      >
        {coxNeeded && <PersonPicker label="Steuermann" value={cox} onChange={setCox} />}

        {crew.map((c, i) => (
          <PersonPicker
            key={i}
            label={coxNeeded ? `Mannschaft ${i + 1}` : i === 0 ? "Name" : `Mannschaft ${i + 1}`}
            value={c}
            onChange={(p) => {
              setCrew((prev) => {
                const next = [...prev];
                next[i] = p;
                return next;
              });
            }}
          />
        ))}

        {showCaptain && (
          <div className="field">
            <label htmlFor="captain">Obmann</label>
            <select
              id="captain"
              value={boatCaptain}
              onChange={(e) => setBoatCaptain(e.target.value)}
            >
              {captainOptions.map((o) => (
                <option key={o.value || "none"} value={o.value}>
                  {o.label}
                </option>
              ))}
            </select>
          </div>
        )}

        <div className="field">
          <label htmlFor="dest">Ziel{mustDest && !isExisting ? " *" : ""}</label>
          <select
            id="dest"
            value={destinationId}
            required={mustDest && !isExisting}
            onChange={(e) => {
              setDestinationId(e.target.value);
              const d = destinations.find((x) => x.id === e.target.value);
              if (d?.distance) setDistance(String(d.distance));
            }}
          >
            <option value="">— wählen —</option>
            {destinations.map((d) => (
              <option key={d.id} value={d.id}>
                {d.name}
              </option>
            ))}
          </select>
        </div>

        {(mode === "finish" || isExisting) && (
          <div className="field">
            <label htmlFor="distance">Kilometer</label>
            <input
              id="distance"
              inputMode="decimal"
              value={distance}
              onChange={(e) => setDistance(e.target.value)}
            />
          </div>
        )}

        {mode === "finish" && (
          <>
            <div className="field">
              <label htmlFor="endTime">Ende (Uhrzeit)</label>
              <input
                id="endTime"
                type="time"
                value={endTime}
                onChange={(e) => setEndTime(e.target.value)}
              />
            </div>
            {!!config.AllowEnterEndDate && (
              <div className="field">
                <label htmlFor="endDate">Endedatum (Mehrtagsfahrt)</label>
                <input
                  id="endDate"
                  type="date"
                  value={endDate}
                  onChange={(e) => setEndDate(e.target.value)}
                />
              </div>
            )}
          </>
        )}

        <div className="row-actions">
          {!isExisting && (
            <button type="submit" className="btn btn-primary btn-block" disabled={busy}>
              Fahrt starten
            </button>
          )}
          {isExisting && mode === "correct" && (
            <button type="submit" className="btn btn-primary btn-block" disabled={busy}>
              Korrektur speichern
            </button>
          )}
          {isExisting && mode === "finish" && (
            <button type="submit" className="btn btn-primary btn-block" disabled={busy}>
              Fahrt beenden
            </button>
          )}
          {isExisting && trip?.open && (
            <button
              type="button"
              className="btn btn-danger btn-block"
              disabled={busy}
              onClick={() => setAbortOpen(true)}
            >
              Fahrt abbrechen…
            </button>
          )}
          <button
            type="button"
            className="btn btn-secondary btn-block"
            onClick={() => navigate(`/boats/${encodeURIComponent(boatId)}/damages/new`)}
          >
            Schaden melden
          </button>
        </div>
      </form>

      {currentAck && (
        <AckDialog
          check={currentAck}
          busy={busy}
          onConfirm={() => void onConfirmAck()}
          onCancel={onCancelAck}
        />
      )}

      {abortOpen && (
        <div className="dialog-backdrop" role="dialog" aria-modal="true">
          <div className="dialog">
            <h2>Fahrt abbrechen</h2>
            <p>
              Nur abbrechen, wenn die Fahrt nicht stattgefunden hat. Der offene Eintrag wird
              gelöscht.
            </p>
            <label style={{ display: "flex", gap: 10, alignItems: "center" }}>
              <input
                type="checkbox"
                checked={abortWithDamage}
                onChange={(e) => setAbortWithDamage(e.target.checked)}
              />
              Mit Bootsschaden melden
            </label>
            {abortWithDamage && (
              <>
                <SeverityPicker value={abortSeverity} onChange={setAbortSeverity} />
                <div className="field">
                  <label htmlFor="abort-desc">Beschreibung</label>
                  <textarea
                    id="abort-desc"
                    value={abortDesc}
                    onChange={(e) => setAbortDesc(e.target.value)}
                    required
                  />
                </div>
              </>
            )}
            <div className="dialog-actions">
              <button
                type="button"
                className="btn btn-danger btn-block"
                disabled={busy || (abortWithDamage && !abortDesc.trim())}
                onClick={() => void onAbort()}
              >
                {abortWithDamage ? "Fahrt abbrechen (Bootsschaden)" : "Fahrt abbrechen"}
              </button>
              <button
                type="button"
                className="btn btn-secondary btn-block"
                disabled={busy}
                onClick={() => setAbortOpen(false)}
              >
                Nichts
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
