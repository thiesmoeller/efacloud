import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from "react";
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
import { MutationAttempt } from "../lib/mutationAttempt";
import { DamageReportScreen } from "./DamageReportScreen";
import { Modal } from "../components/Modal";
import { useOnline } from "../hooks/useOnline";

type Mode = "start" | "correct" | "finish";

export function TripFormScreen() {
  const navigate = useNavigate();
  const online = useOnline();
  const attempts = useRef({ start: new MutationAttempt(), correct: new MutationAttempt(), finish: new MutationAttempt(), abort: new MutationAttempt() });
  const { entryId } = useParams();
  const [params] = useSearchParams();
  const logbookName = params.get("logbookName") ?? undefined;
  const [reload, setReload] = useState(0);
  const boatIdParam = params.get("boatId") ?? "";
  const variantParam = params.get("variant") ?? "1";

  const isExisting = !!entryId && entryId !== "new";
  const [mode, setMode] = useState<Mode>(isExisting ? "finish" : "start");
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
  const [destinationName, setDestinationName] = useState("");
  const [distance, setDistance] = useState("");
  const [startTime, setStartTime] = useState("");
  const [startDate, setStartDate] = useState("");
  const [endTime, setEndTime] = useState("");
  const [endDate, setEndDate] = useState("");

  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [ackTokens, setAckTokens] = useState<string[]>([]);
  const [ackQueue, setAckQueue] = useState<AckCheck[]>([]);
  const [currentAck, setCurrentAck] = useState<AckCheck | null>(null);
  const [pendingAction, setPendingAction] = useState<"start" | "correct" | null>(null);

  const [damageOpen, setDamageOpen] = useState(false);
  const [abortOpen, setAbortOpen] = useState(false);
  const [abortWithDamage, setAbortWithDamage] = useState(false);
  const [abortSeverity, setAbortSeverity] = useState<DamageSeverity>("LIMITEDUSEABLE");
  const [abortDesc, setAbortDesc] = useState("");

  const selectedVariant = useMemo(() => {
    return detail?.variants.find((v) => String(v.variant) === String(variant)) ?? detail?.variants[0];
  }, [detail, variant]);

  const coxNeeded = selectedVariant ? isCoxed(selectedVariant.typeCoxing) : false;
  const configuredSeats = selectedVariant ? seatCountFromCategory(selectedVariant.seatCategory) : crew.length;
  const seatCount = config.InputAllowOnlyMaxCrewNumber === false ? Math.max(configuredSeats, crew.length) : configuredSeats;

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
          const { trip: t } = await api.getTrip(entryId, logbookName);
          if (cancelled) return;
          setTrip(t);
          setStartTime(t.startTime);
          setStartDate(t.date);
          setBoatId(t.boatId);
          setVariant(t.boatVariant || "1");
          setDestinationId(t.destinationId || "");
          setDestinationName(t.destinationName || "");
          setDistance(t.distance || "");
          setBoatCaptain(t.boatCaptain || "");
          setCox(t.cox?.id || t.cox?.name ? { id: t.cox.id, firstName: "", lastName: "", displayName: t.cox.name } : null);
          const crewPersons: Array<Person | null> = Array.from({ length: Math.max(1, ...t.crew.map(c => c.position)) }, () => null);
          t.crew.forEach(c => { crewPersons[c.position - 1] = { id: c.id, firstName: "", lastName: "", displayName: c.name }; });
          setCrew(crewPersons);
          const d = await api.getBoat(t.boatId);
          if (!cancelled) setDetail(d);
        } else if (boatIdParam) {
          const d = await api.getBoat(boatIdParam);
          if (cancelled) return;
          setDetail(d);
          setBoatId(boatIdParam);
          const defaultDest = String(d.boat?.DefaultDestinationId ?? "");
          if (defaultDest) {
            setDestinationId(defaultDest);
            setDestinationName(dests.destinations.find(dest => dest.id === defaultDest)?.name ?? "");
            setDistance(String(dests.destinations.find(dest => dest.id === defaultDest)?.distance ?? ""));
          }
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
  }, [boatIdParam, entryId, isExisting, logbookName, reload]);

  const handleFailure = (err: unknown, fallback: string) => {
    const conflict = err instanceof PortalApiError && ["STALE_STATE", "TRIP_NOT_OPEN", "NOT_FOUND"].includes(err.code);
    setError((err instanceof Error ? err.message : fallback) + (conflict ? " Der aktuelle Stand wird neu geladen. Bitte prüfen." : ""));
    if (conflict) { setTrip(null); setReload(n => n + 1); setAckTokens([]); }
  };

  useEffect(() => {
    if (isExisting || boatCaptain !== "") return;
    if (config.BoatCaptainAutoSelect) {
      if (cox?.displayName) setBoatCaptain("0");
      else if (String(config.BoatCaptainDefault) === "BOW" && crew[0]?.id) setBoatCaptain("1");
      else if (String(config.BoatCaptainDefault) === "STROKE") {
        const last = crew.reduce((n, person, i) => person?.id ? i + 1 : n, 0);
        if (last) setBoatCaptain(String(last));
      }
    } else if (config.InputMustSelectBoatCaptain && seatCount === 1 && crew[0]?.id) setBoatCaptain("1");
  }, [isExisting, boatCaptain, config, cox, crew, seatCount]);

  useEffect(() => {
    if (boatCaptain === "") return;
    const aboard = boatCaptain === "0" ? cox?.displayName : crew[Number(boatCaptain) - 1]?.displayName;
    if (aboard) return;
    // Match the desktop fallback after removing the selected captain's seat.
    if (cox?.displayName) setBoatCaptain("0");
    else if (crew[0]?.displayName) setBoatCaptain("1");
    else setBoatCaptain("");
  }, [boatCaptain, cox, crew]);

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
          crew: crew.map(c => ({ id: c?.id ?? "", name: c?.displayName ?? "" })),
          coxId: coxNeeded && cox?.id ? cox.id : undefined,
          coxName: coxNeeded ? cox?.displayName ?? "" : "",
          boatCaptain: boatCaptain || undefined,
          destinationId: destinationId || undefined,
          destinationName,
          distance: distance || undefined,
          acknowledgmentTokens: tokens,
          date: startDate || undefined,
          startTime: startTime || undefined,
        };
        const res = await attempts.current.start.run(body, b => api.startTrip(b as unknown as Parameters<typeof api.startTrip>[0]));
        setSuccess("Fahrt gestartet.");
        setTrip(res.trip);

      } catch (err) {
        if (isAckFlowError(err)) {
          beginAckFlow(err, "start");
        } else if (err instanceof PortalApiError) {
          handleFailure(err, "Speichern fehlgeschlagen.");
        } else {
          setError("Start fehlgeschlagen.");
        }
      } finally {
        setBusy(false);
      }
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [boatId, variant, crew, cox, coxNeeded, boatCaptain, destinationId, destinationName, distance, startDate, startTime]
  );

  const runCorrect = useCallback(
    async (tokens: string[]) => {
      if (!trip) return;
      setBusy(true);
      setError(null);
      try {
        const res = await attempts.current.correct.run({
          logbookName: trip.logbookName, ecrid: trip.ecrid,
          date: startDate, startTime,
          expectedChangeCount: trip.changeCount,
          boatId,
          boatVariant: variant,
          crew: crew.map(c => ({ id: c?.id ?? "", name: c?.displayName ?? "" })),
          coxId: coxNeeded && cox?.id ? cox.id : "",
          coxName: coxNeeded ? cox?.displayName ?? "" : "",
          boatCaptain: boatCaptain || "",
          destinationId: destinationId || "",
          destinationName,
          distance: distance || "",
          acknowledgmentTokens: tokens,
        }, b => api.correctTrip(trip.entryId, b));
        setTrip(res.trip);
        setSuccess("Fahrt korrigiert.");
        setMode("finish");
        setAckTokens([]);
      } catch (err) {
        if (isAckFlowError(err)) {
          beginAckFlow(err, "correct");
        } else if (err instanceof PortalApiError) {
          handleFailure(err, "Speichern fehlgeschlagen.");
        } else {
          setError("Korrektur fehlgeschlagen.");
        }
      } finally {
        setBusy(false);
      }
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [trip, boatId, variant, crew, cox, coxNeeded, boatCaptain, destinationId, destinationName, distance, startDate, startTime]
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
      const res = await attempts.current.finish.run({
        logbookName: trip.logbookName, ecrid: trip.ecrid,
        expectedChangeCount: trip.changeCount,
        distance,
        endTime: endTime || undefined,
        endDate: endDate || undefined,
        destinationId,
        destinationName,
      }, b => api.finishTrip(trip.entryId, b as Parameters<typeof api.finishTrip>[1]));
      setTrip(res.trip);
      setSuccess("Fahrt beendet.");
      navigate("/", { replace: true });
    } catch (err) {
      handleFailure(err, "Beenden fehlgeschlagen.");
    } finally {
      setBusy(false);
    }
  };

  const onAbort = async () => {
    if (!trip) return;
    setBusy(true);
    setError(null);
    try {
      await attempts.current.abort.run({
        logbookName: trip.logbookName, ecrid: trip.ecrid,
        expectedChangeCount: trip.changeCount,
        withDamage: abortWithDamage
          ? { severity: abortSeverity, description: abortDesc }
          : undefined,
      }, b => api.abortTrip(trip.entryId, b as Parameters<typeof api.abortTrip>[1]));
      setAbortOpen(false);
      setSuccess(
        abortWithDamage
          ? "Fahrt abgebrochen und Schaden gemeldet."
          : "Fahrt abgebrochen (hat nicht stattgefunden)."
      );
      navigate("/", { replace: true });
    } catch (err) {
      handleFailure(err, "Abbruch fehlgeschlagen.");
    } finally {
      setBusy(false);
    }
  };

  const boatName = String(detail?.boat?.Name ?? trip?.boatName ?? "Fahrt");
  const mustDest = !!config.StartSessionMustSelectDestination;
  const showCaptain = config.BoatCaptainShow !== false || !!config.InputMustSelectBoatCaptain;

  const captainOptions = useMemo(() => {
    const opts: Array<{ value: string; label: string }> = [{ value: "", label: "— keiner —" }];
    if (cox?.displayName) opts.push({ value: "0", label: `Stm. ${cox.displayName}` });
    crew.forEach((c, i) => {
      if (c?.displayName) opts.push({ value: String(i + 1), label: `${i + 1}. ${c.displayName}` });
    });
    return opts;
  }, [cox, crew]);

  if (!isExisting && trip) return <main className="screen">
    <ScreenHeader title="Fahrt gestartet" />
    <div role="status" className="success-box">{trip.boatName} ist auf Fahrt. Der Start wurde gespeichert.</div>
    <button className="btn btn-primary" onClick={() => navigate("/boats", { replace: true })}>Weiteres Boot starten</button>
    <button className="btn btn-secondary" onClick={() => navigate("/", { replace: true })}>Meine Fahrten</button>
  </main>;

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
      <p className="boat-name" style={{ margin: 0 }}>
        {boatName}
        {selectedVariant ? ` · ${selectedVariant.seatCategoryLabel}` : ""}
        {variant ? ` · Var. ${variant}` : ""}
      </p>

      {(detail?.openDamages?.length ?? 0) > 0 && (
        <div className="error-box">
          Offene Schäden ({detail!.openDamages.length}):{" "}
          {detail!.openDamages[0].severityLabel} — {detail!.openDamages[0].description}
          <details><summary>Schäden ansehen</summary>{detail!.openDamages.map(d => <p key={d.damage}>{d.severityLabel}: {d.description}</p>)}</details>
        </div>
      )}

      {error && <div role="alert" className="error-box">{error}</div>}
      {success && <div role="status" className="success-box">{success}</div>}

      {isExisting && trip && !trip.open && <div role="status" className="success-box">Diese Fahrt ist bereits beendet. <button type="button" className="btn btn-secondary" onClick={() => navigate("/")}>Meine Fahrten</button></div>}
      {isExisting && trip?.open && (
        <div className="chip-row">
          <button
            type="button"
            className={`chip${mode === "correct" ? " active" : ""}`}
            onClick={() => setMode("correct")}
          >
            Fahrt korrigieren
          </button>
          <button
            type="button"
            className={`chip${mode === "finish" ? " active" : ""}`}
            onClick={() => setMode("finish")}
          >
            Zur Rückgabe
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
        <fieldset disabled={busy || (isExisting && (!trip || !trip.open))} className="stack form-fields">
        {mode === "finish" && trip && <section className="trip-card"><h2>Mannschaft</h2><p>{trip.crew.map(c => `${c.position}. ${c.name}`).join(" · ")}{trip.cox.name && ` · Stm. ${trip.cox.name}`}</p><p>Obmann: {trip.boatCaptain === "0" ? trip.cox.name : trip.crew.find(c => String(c.position) === trip.boatCaptain)?.name || "—"}</p></section>}
        {mode !== "finish" && <>
        {detail && detail.variants.length > 1 && <div className="field"><label htmlFor="variant">Konfiguration</label><select id="variant" value={variant} onChange={e => setVariant(e.target.value)}>{detail.variants.map(v => <option key={v.variant} value={v.variant}>{v.seatCategoryLabel} · {v.typeDescription || v.typeRigging} · {isCoxed(v.typeCoxing) ? "mit Stm." : "ohne Stm."}</option>)}</select></div>}
        {coxNeeded && <PersonPicker label="Steuermann" value={cox} onChange={setCox} />}

        {crew.map((c, i) => (
          <PersonPicker
            key={i}
            label={`Sitz ${i + 1}`}
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

        {config.InputAllowOnlyMaxCrewNumber === false && crew.length < 23 && <button type="button" className="btn btn-secondary" onClick={() => setCrew(previous => [...previous, null])}>Weiteren Sitz hinzufügen</button>}
        {showCaptain && (
          <div className="field">
            <label htmlFor="captain">Obmann</label>
            <select
              id="captain"
              required={!!config.InputMustSelectBoatCaptain}
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

        <div className="field"><label htmlFor="start-time">Abfahrt (leer: aktuelle Zeit)</label><input id="start-time" type="time" value={startTime} onChange={e => setStartTime(e.target.value)} /></div>
        <div className="field"><label htmlFor="start-date">Startdatum (leer: heute)</label><input id="start-date" type="date" value={startDate} onChange={e => setStartDate(e.target.value)} /></div>
        </>}
        <div className="field">
          <label htmlFor="dest">Ziel{mustDest && !isExisting ? " *" : ""}</label>
          <select
            id="dest"
            value={destinationId}
            required={mustDest && !isExisting && !destinationName}
            onChange={(e) => {
              setDestinationId(e.target.value);
              const d = destinations.find((x) => x.id === e.target.value);
              setDestinationName(d?.name ?? "");
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

        {!destinationId && <div className="field">
          <label htmlFor="custom-destination">Individuelles Fahrtziel</label>
          <input id="custom-destination" value={destinationName} required={mustDest && !isExisting} onChange={e => setDestinationName(e.target.value)} />
        </div>}

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
            <button type="submit" className="btn btn-primary btn-block" disabled={busy || !online}>
              Fahrt starten
            </button>
          )}
          {isExisting && mode === "correct" && (
            <button type="submit" className="btn btn-primary btn-block" disabled={busy || !online}>
              Korrektur speichern
            </button>
          )}
          {isExisting && mode === "finish" && (
            <button type="submit" className="btn btn-primary btn-block" disabled={busy || !online}>
              Fahrt beenden
            </button>
          )}
          {isExisting && trip?.open && (
            <button
              type="button"
              className="btn btn-danger btn-block"
              disabled={busy || !online}
              onClick={() => setAbortOpen(true)}
            >
              Fahrt abbrechen…
            </button>
          )}
          <button
            type="button"
            className="btn btn-secondary btn-block"
            onClick={() => setDamageOpen(true)}
          >
            Schaden melden
          </button>
        </div>
        </fieldset>
      </form>

      {currentAck && (
        <AckDialog
          check={currentAck}
          busy={busy}
          onConfirm={() => void onConfirmAck()}
          onCancel={onCancelAck}
        />
      )}

      {damageOpen && <Modal titleId="damage-title" busy={busy} onCancel={() => setDamageOpen(false)}><h2 id="damage-title">Schaden melden</h2><DamageReportScreen forBoat={boatId} onBusyChange={setBusy} onDone={() => setDamageOpen(false)} /></Modal>}
      {abortOpen && (
        <Modal titleId="abort-title" onCancel={() => setAbortOpen(false)} busy={busy}>
            <h2 id="abort-title">Fahrt abbrechen</h2>
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
                disabled={busy || !online || (abortWithDamage && !abortDesc.trim())}
                onClick={() => void onAbort()}
              >
                {abortWithDamage ? "Fahrt abbrechen (Bootsschaden)" : "Fahrt abbrechen"}
              </button>
              <button
                type="button"
                className="btn btn-secondary btn-block"
                disabled={busy || !online}
                onClick={() => setAbortOpen(false)}
              >
                Nichts
              </button>
            </div>
        </Modal>
      )}
    </div>
  );
}
