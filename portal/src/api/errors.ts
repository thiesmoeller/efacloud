import type { AckRequiredDetails, PortalErrorBody } from "./types";

export class PortalApiError extends Error {
  readonly code: string;
  readonly httpStatus: number;
  readonly details: AckRequiredDetails & Record<string, unknown>;

  constructor(
    code: string,
    message: string,
    httpStatus: number,
    details: AckRequiredDetails & Record<string, unknown> = {}
  ) {
    super(message);
    this.name = "PortalApiError";
    this.code = code;
    this.httpStatus = httpStatus;
    this.details = details;
  }

  get isNetwork(): boolean {
    return this.code === "NETWORK_ERROR";
  }

  get isAckRequired(): boolean {
    return this.code === "ACK_REQUIRED";
  }

  get isAckStale(): boolean {
    return this.code === "ACK_STALE";
  }

  get isAuth(): boolean {
    return this.code === "AUTH_REQUIRED" || this.code === "ACCOUNT_REVOKED";
  }
}

export function parsePortalError(
  status: number,
  body: unknown,
  fallbackMessage: string
): PortalApiError {
  const envelope = body as PortalErrorBody | null;
  if (envelope && typeof envelope === "object" && envelope.error) {
    const details = {
      ...((envelope.error.details as AckRequiredDetails & Record<string, unknown>) || {}),
    };
    return new PortalApiError(
      envelope.error.code || "UNKNOWN",
      envelope.error.message || fallbackMessage,
      status,
      details
    );
  }
  return new PortalApiError("UNKNOWN", fallbackMessage, status);
}

export function germanNetworkMessage(): string {
  return "Keine Verbindung zum Server. Bitte Netz prüfen und erneut versuchen. Fahrten werden nur online gespeichert.";
}
