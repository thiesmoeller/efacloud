import { describe, it, expect, vi } from "vitest";
import { MutationAttempt } from "./mutationAttempt";
import { PortalApiError } from "../api/errors";

describe("mutation retries", () => {
  it("replays the exact payload and key after a lost response", async () => {
    const attempt = new MutationAttempt();
    const send = vi.fn().mockRejectedValueOnce(new PortalApiError("NETWORK_ERROR", "lost", 0)).mockResolvedValue({ saved: true });
    await expect(attempt.run({ distance: "5" }, send)).rejects.toThrow("lost");
    await attempt.run({ distance: "9" }, send);
    expect(send.mock.calls[1][0]).toEqual(send.mock.calls[0][0]);
    await attempt.run({ distance: "9" }, send);
    expect(send.mock.calls[2][0].idempotencyKey).not.toEqual(send.mock.calls[0][0].idempotencyKey);
    expect(send.mock.calls[2][0].distance).toBe("9");
  });
  it("shares one pending request across duplicate taps", async () => {
    const attempt = new MutationAttempt();
    let finish!: (value: unknown) => void;
    const send = vi.fn(() => new Promise(resolve => { finish = resolve; }));
    const first = attempt.run({ boatId: "one" }, send);
    const second = attempt.run({ boatId: "one" }, send);
    expect(send).toHaveBeenCalledTimes(1);
    finish({ saved: true });
    expect(await second).toEqual(await first);
  });
  it("permits new acknowledgments after a definite rejection", async () => {
    const attempt = new MutationAttempt();
    const send = vi.fn().mockRejectedValueOnce(new PortalApiError("ACK_REQUIRED", "confirm", 409)).mockResolvedValue({ saved: true });
    await expect(attempt.run({ acknowledgmentTokens: [] }, send)).rejects.toThrow();
    await attempt.run({ acknowledgmentTokens: ["confirmed"] }, send);
    expect(send.mock.calls[1][0].acknowledgmentTokens).toEqual(["confirmed"]);
    expect(send.mock.calls[1][0].idempotencyKey).toEqual(send.mock.calls[0][0].idempotencyKey);
  });
});
