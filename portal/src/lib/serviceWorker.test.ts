import serviceWorker from "../../public/sw.js?raw";
import { describe, it, expect, vi } from "vitest";

describe("service worker update and write safety", () => {
  it("waits for explicit activation and never handles API or write requests", async () => {
    const handlers: Record<string, (event: any) => void> = {};
    const skipWaiting = vi.fn();
    const cache = { addAll: vi.fn().mockResolvedValue(undefined) };
    new Function("self", "caches", serviceWorker)(
      { addEventListener: (event: string, fn: (event: any) => void) => { handlers[event] = fn; }, skipWaiting },
      { open: vi.fn().mockResolvedValue(cache) }
    );
    let installed!: Promise<void>;
    handlers.install({ waitUntil: (promise: Promise<void>) => { installed = promise; } });
    await installed;
    expect(skipWaiting).not.toHaveBeenCalled();
    const respondWith = vi.fn();
    handlers.fetch({ request: { url: "https://club.test/api/portal/v1/trips", method: "GET" }, respondWith });
    handlers.fetch({ request: { url: "https://club.test/portal/", method: "POST" }, respondWith });
    expect(respondWith).not.toHaveBeenCalled();
    handlers.message({ data: { type: "SKIP_WAITING" } });
    expect(skipWaiting).toHaveBeenCalledOnce();
  });
});
