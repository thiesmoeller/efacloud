import { PortalApiError } from "../api/errors";
import { newIdempotencyKey } from "./idempotency";

/** Preserve both payload and key when a response might have been lost. */
export class MutationAttempt {
  private body: Record<string, unknown> | null = null;
  private key = newIdempotencyKey();
  private pending: Promise<unknown> | null = null;
  run<T>(body: Record<string, unknown>, send: (body: Record<string, unknown>) => Promise<T>): Promise<T> {
    if (this.pending) return this.pending as Promise<T>;
    this.body ??= { ...body, idempotencyKey: this.key };
    this.pending = send(this.body).then(result => {
      this.body = null;
      this.key = newIdempotencyKey();
      return result;
    }).catch(error => {
      // A definite rejection permits editing; transport/server failures replay exactly.
      if (error instanceof PortalApiError && error.httpStatus >= 400 && error.httpStatus < 500) {
        this.body = null;
      }
      throw error;
    }).finally(() => { this.pending = null; });
    return this.pending as Promise<T>;
  }
}
