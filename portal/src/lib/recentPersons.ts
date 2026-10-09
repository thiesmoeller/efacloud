import type { Person } from "../api/types";

const MAX_RECENT_PERSONS = 20;

export function recentPersonsStorageKey(accountId: number): string {
  return `efaPortal:recent-persons:${accountId}`;
}

export function readRecentPersonIds(storage: Storage, accountId: number): string[] {
  try {
    const parsed = JSON.parse(storage.getItem(recentPersonsStorageKey(accountId)) ?? "[]");
    return Array.isArray(parsed) ? parsed.filter((id): id is string => typeof id === "string") : [];
  } catch {
    return [];
  }
}

export function rememberPerson(storage: Storage, accountId: number, personId: string): void {
  try {
    const ids = readRecentPersonIds(storage, accountId).filter(id => id !== personId);
    storage.setItem(recentPersonsStorageKey(accountId), JSON.stringify([personId, ...ids].slice(0, MAX_RECENT_PERSONS)));
  } catch {
    // Browsers may block storage in private/restricted contexts. Search still works.
  }
}

export function rankPersonsByRecent(persons: Person[], recentIds: string[]): Person[] {
  const rank = new Map(recentIds.map((id, index) => [id, index]));
  return persons
    .map((person, index) => ({ person, index, recent: rank.get(person.id) }))
    .sort((a, b) => {
      if (a.recent !== undefined && b.recent !== undefined) return a.recent - b.recent;
      if (a.recent !== undefined) return -1;
      if (b.recent !== undefined) return 1;
      return a.index - b.index;
    })
    .map(item => item.person);
}
