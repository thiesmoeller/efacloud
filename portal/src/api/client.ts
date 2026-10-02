import { germanNetworkMessage, parsePortalError, PortalApiError } from "./errors";
import type {
  BoatDetailResponse,
  BoatsListResponse,
  BoatView,
  ClubConfigResponse,
  DamageRecord,
  DamageSeverity,
  Destination,
  Person,
  SessionResponse,
  StartTripBody,
  Trip,
} from "./types";

const API_BASE = "/api/portal/v1";

let csrfToken: string | null = null;

export function getCsrfToken(): string | null {
  return csrfToken;
}

export function setCsrfToken(token: string | null): void {
  csrfToken = token;
}

type FetchOptions = {
  method?: string;
  body?: unknown;
  signal?: AbortSignal;
};

async function portalFetch<T>(path: string, options: FetchOptions = {}): Promise<T> {
  const method = (options.method || "GET").toUpperCase();
  const headers: Record<string, string> = {
    Accept: "application/json",
  };

  const init: RequestInit = {
    method,
    credentials: "include",
    headers,
    signal: options.signal,
  };

  if (method !== "GET" && method !== "HEAD") {
    headers["Content-Type"] = "application/json; charset=utf-8";
    if (csrfToken) {
      headers["X-CSRF-Token"] = csrfToken;
    }
    init.body = JSON.stringify(options.body ?? {});
  }

  let response: Response;
  try {
    response = await fetch(`${API_BASE}${path}`, init);
  } catch {
    throw new PortalApiError("NETWORK_ERROR", germanNetworkMessage(), 0);
  }

  let data: unknown = null;
  const text = await response.text();
  if (text) {
    try {
      data = JSON.parse(text);
    } catch {
      // Strip accidental PHP warning HTML before JSON (defense in depth).
      const startObj = text.indexOf("{");
      const startArr = text.indexOf("[");
      let start = -1;
      if (startObj >= 0 && (startArr < 0 || startObj <= startArr)) start = startObj;
      else if (startArr >= 0) start = startArr;
      if (start >= 0) {
        try {
          data = JSON.parse(text.slice(start));
        } catch {
          data = null;
        }
      }
    }
  }

  if (!response.ok) {
    throw parsePortalError(
      response.status,
      data,
      `Anfrage fehlgeschlagen (${response.status}).`
    );
  }

  // Success only after 2xx with a parsed body — never invent success.
  if (data === null) {
    throw new PortalApiError(
      "INVALID_RESPONSE",
      "Ungültige Serverantwort. Bitte erneut versuchen.",
      response.status
    );
  }
  return data as T;
}

export const api = {
  getSession(): Promise<SessionResponse> {
    return portalFetch<SessionResponse>("/session").then((s) => {
      if (s.csrfToken) setCsrfToken(s.csrfToken);
      return s;
    });
  },

  login(account: string, password: string): Promise<SessionResponse> {
    return portalFetch<SessionResponse>("/session/login", {
      method: "POST",
      body: { account, password },
    }).then((s) => {
      if (s.csrfToken) setCsrfToken(s.csrfToken);
      return { ...s, authenticated: true };
    });
  },

  logout(): Promise<void> {
    return portalFetch<{ ok?: boolean }>("/session/logout", { method: "POST", body: {} }).then(
      () => {
        setCsrfToken(null);
      }
    );
  },

  listBoats(params: {
    view?: BoatView;
    seatCategory?: string;
    search?: string;
  }): Promise<BoatsListResponse> {
    const q = new URLSearchParams();
    if (params.view) q.set("view", params.view);
    if (params.seatCategory && params.seatCategory !== "ALL") {
      q.set("seatCategory", params.seatCategory);
    }
    if (params.search) q.set("search", params.search);
    const qs = q.toString();
    return portalFetch(`/boats${qs ? `?${qs}` : ""}`);
  },

  getBoat(boatId: string): Promise<BoatDetailResponse> {
    return portalFetch(`/boats/${encodeURIComponent(boatId)}`);
  },

  listDamages(boatId: string, onlyOpen = true): Promise<{ damages: DamageRecord[] }> {
    const q = onlyOpen ? "" : "?onlyOpen=0";
    return portalFetch(`/boats/${encodeURIComponent(boatId)}/damages${q}`);
  },

  reportDamage(
    boatId: string,
    body: { severity: DamageSeverity; description: string; logbookText?: string }
  ): Promise<{ damage: DamageRecord; tripAborted: boolean; message?: string }> {
    return portalFetch(`/boats/${encodeURIComponent(boatId)}/damages`, {
      method: "POST",
      body,
    });
  },

  searchPersons(search: string): Promise<{ persons: Person[] }> {
    const q = search ? `?search=${encodeURIComponent(search)}` : "";
    return portalFetch(`/persons${q}`);
  },

  listDestinations(): Promise<{ destinations: Destination[] }> {
    return portalFetch("/destinations");
  },

  getConfig(): Promise<ClubConfigResponse> {
    return portalFetch("/config");
  },

  createAcknowledgment(kind: string, boatId: string): Promise<{ token: string; kind: string; boatId: string; snapshotHash: string }> {
    return portalFetch("/acknowledgments", {
      method: "POST",
      body: { kind, boatId },
    });
  },

  startTrip(body: StartTripBody): Promise<{ trip: Trip; boatStatus: Record<string, unknown> }> {
    return portalFetch("/trips", { method: "POST", body });
  },

  getTrip(entryId: string): Promise<{ trip: Trip }> {
    return portalFetch(`/trips/${encodeURIComponent(entryId)}`);
  },

  correctTrip(
    entryId: string,
    body: Record<string, unknown>
  ): Promise<{ trip: Trip }> {
    return portalFetch(`/trips/${encodeURIComponent(entryId)}`, {
      method: "PATCH",
      body,
    });
  },

  finishTrip(
    entryId: string,
    body: {
      expectedChangeCount: number;
      endTime?: string;
      endDate?: string;
      distance?: string;
      destinationId?: string;
      destinationName?: string;
      idempotencyKey: string;
    }
  ): Promise<{ trip: Trip }> {
    return portalFetch(`/trips/${encodeURIComponent(entryId)}/finish`, {
      method: "POST",
      body,
    });
  },

  abortTrip(
    entryId: string,
    body: {
      expectedChangeCount: number;
      idempotencyKey: string;
      withDamage?: { severity: DamageSeverity; description: string };
    }
  ): Promise<{ aborted: boolean; damage?: DamageRecord; message?: string }> {
    return portalFetch(`/trips/${encodeURIComponent(entryId)}/abort`, {
      method: "POST",
      body,
    });
  },
};
