import type { AckCheck } from "../api/types";

type Props = {
  check: AckCheck;
  busy?: boolean;
  onConfirm: () => void;
  onCancel: () => void;
};

/** EFA-style yes/no (damage) or yes/no/cancel (status & reservation) dialog. */
export function AckDialog({ check, busy, onConfirm, onCancel }: Props) {
  const showCancelVariant =
    check.kind === "status_notavailable" || check.kind === "reservation";

  return (
    <div className="dialog-backdrop" role="dialog" aria-modal="true" aria-labelledby="ack-title">
      <div className="dialog">
        <h2 id="ack-title">{check.title}</h2>
        <p>{check.message}</p>
        <div className="dialog-actions">
          <button
            type="button"
            className="btn btn-primary btn-block"
            disabled={busy}
            onClick={onConfirm}
          >
            Ja
          </button>
          <button
            type="button"
            className="btn btn-secondary btn-block"
            disabled={busy}
            onClick={onCancel}
          >
            Nein
          </button>
          {showCancelVariant && (
            <button
              type="button"
              className="btn btn-ghost btn-block"
              disabled={busy}
              onClick={onCancel}
            >
              Abbrechen
            </button>
          )}
        </div>
      </div>
    </div>
  );
}
