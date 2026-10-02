import type { AckCheck, AckKind } from "../api/types";
import { PortalApiError } from "../api/errors";
import { api } from "../api/client";

export type AckDialogState = {
  check: AckCheck;
  /** Remaining checks after this one (same ACK_REQUIRED payload order). */
  remaining: AckCheck[];
};

/**
 * EFA order is already encoded in details.checks[].
 * Only kinds still required (or stale) need a dialog.
 * Pass `requiredKinds: []` when the server says nothing more is required.
 * Omit / pass undefined to walk all checks (fallback).
 */
export function nextAckDialog(
  checks: AckCheck[] | undefined,
  requiredKinds: AckKind[] | undefined,
  staleKinds?: AckKind[]
): AckDialogState | null {
  if (!checks || checks.length === 0) return null;
  const hasRequiredList = requiredKinds !== undefined;
  const needed = new Set<AckKind>([
    ...(requiredKinds ?? []),
    ...(staleKinds ?? []),
  ]);
  if (hasRequiredList && needed.size === 0) return null;
  const pending = needed.size > 0 ? checks.filter((c) => needed.has(c.kind)) : [...checks];
  if (pending.length === 0) return null;
  const [check, ...remaining] = pending;
  return { check, remaining };
}

/**
 * After user confirms one check: POST /acknowledgments, append token, continue.
 */
export async function confirmAcknowledgment(
  kind: AckKind,
  boatId: string,
  existingTokens: string[]
): Promise<string[]> {
  const ack = await api.createAcknowledgment(kind, boatId);
  return [...existingTokens, ack.token];
}

/**
 * Drop tokens that correspond to stale kinds so they are re-confirmed.
 * Tokens are opaque; we re-fetch all needed kinds from scratch for stale set.
 */
export function clearTokensForStale(
  tokens: string[],
  _staleKinds: AckKind[]
): string[] {
  // Server returns ACK_STALE when any presented token's snapshot mismatches.
  // Safest client policy: clear all tokens and re-walk dialogs.
  void _staleKinds;
  void tokens;
  return [];
}

export function isAckFlowError(err: unknown): err is PortalApiError {
  return err instanceof PortalApiError && (err.isAckRequired || err.isAckStale);
}

export function extractAckChecks(err: PortalApiError): {
  checks: AckCheck[];
  required: AckKind[];
  stale: AckKind[];
} {
  const details = err.details || {};
  return {
    checks: (details.checks as AckCheck[]) || [],
    required: (details.requiredAcknowledgments as AckKind[]) || [],
    stale: (details.staleKinds as AckKind[]) || [],
  };
}
