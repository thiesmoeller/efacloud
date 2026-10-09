import type { Fleet, Metadata, Overview, Personal, Session } from "./types";

export class ApiError extends Error {
  constructor(message: string, readonly status: number) {
    super(message);
  }
}

let csrfToken = "";

async function request<T>(base: string, path: string, init: RequestInit = {}): Promise<T> {
  const response = await fetch(`${base}${path}`, {
    credentials: "include",
    ...init,
    headers: { Accept: "application/json", ...(init.headers || {}) },
  });
  const data = response.status === 304 ? null : await response.json().catch(() => null);
  if (!response.ok || data === null) {
    const message = data?.error?.message || "Die Daten konnten nicht geladen werden.";
    throw new ApiError(message, response.status);
  }
  return data as T;
}

function rangeQuery(from: string, to: string): string {
  const query = new URLSearchParams({ from, to });
  return `?${query.toString()}`;
}

export const api = {
  async session(): Promise<Session> {
    const value = await request<Session>("/api/portal/v1", "/session");
    csrfToken = value.csrfToken || "";
    return value;
  },
  async login(account: string, password: string): Promise<Session> {
    const value = await request<Session>("/api/portal/v1", "/session/login", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ account, password }),
    });
    csrfToken = value.csrfToken || "";
    return value;
  },
  logout(): Promise<unknown> {
    return request("/api/portal/v1", "/session/logout", {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfToken },
      body: "{}",
    });
  },
  metadata: () => request<Metadata>("/api/stats/v1", "/metadata"),
  overview: (from: string, to: string) => request<Overview>("/api/stats/v1", `/overview${rangeQuery(from, to)}`),
  fleet: (from: string, to: string) => request<Fleet>("/api/stats/v1", `/fleet${rangeQuery(from, to)}`),
  personal: (from: string, to: string) => request<Personal>("/api/stats/v1", `/me${rangeQuery(from, to)}`),
};
