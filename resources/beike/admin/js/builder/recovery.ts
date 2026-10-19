import type { BuilderChrome } from "./chrome-settings";
import type { PuckData } from "./types";

const RECOVERY_FORMAT = "longos-builder-browser-recovery";
const RECOVERY_VERSION = 1;
const RECOVERY_MAX_AGE = 24 * 60 * 60 * 1000;
const RECOVERY_MAX_BYTES = 2 * 1024 * 1024;

export interface BuilderBrowserRecovery {
  format: typeof RECOVERY_FORMAT;
  version: typeof RECOVERY_VERSION;
  baseDraftVersion: number;
  updatedAt: number;
  data: PuckData;
  chrome: BuilderChrome;
}

const objectRecord = (value: unknown): value is Record<string, unknown> =>
  Boolean(value && typeof value === "object" && !Array.isArray(value));

const validData = (value: unknown): value is PuckData => {
  if (!objectRecord(value) || !Array.isArray(value.content) || !objectRecord(value.root)) return false;
  if (!objectRecord(value.root.props)) return false;
  return value.content.every(item => objectRecord(item) && typeof item.type === "string" && objectRecord(item.props));
};

const validChrome = (value: unknown): value is BuilderChrome =>
  objectRecord(value) && objectRecord(value.header) && objectRecord(value.footer) && objectRecord(value.menu);

export const builderRecoveryKey = (updateUrl: string): string => {
  try {
    const base = typeof window === "undefined" ? "http://longos.local" : window.location.href;
    const url = new URL(updateUrl, base);
    return `longos:builder:recovery:${url.pathname}`;
  } catch {
    return "longos:builder:recovery:design";
  }
};

export const clearBuilderRecovery = (storage: Storage | null, key: string): void => {
  try { storage?.removeItem(key); } catch { /* Storage can be disabled by browser policy. */ }
};

export const readBuilderRecovery = (storage: Storage | null, key: string, draftVersion: number, now = Date.now()): BuilderBrowserRecovery | null => {
  try {
    const raw = storage?.getItem(key);
    if (!raw || raw.length > RECOVERY_MAX_BYTES) {
      if (raw) clearBuilderRecovery(storage, key);
      return null;
    }
    const parsed: unknown = JSON.parse(raw);
    if (!objectRecord(parsed)
      || parsed.format !== RECOVERY_FORMAT
      || parsed.version !== RECOVERY_VERSION
      || parsed.baseDraftVersion !== Math.max(0, draftVersion)
      || typeof parsed.updatedAt !== "number"
      || now - parsed.updatedAt < 0
      || now - parsed.updatedAt > RECOVERY_MAX_AGE
      || !validData(parsed.data)
      || !validChrome(parsed.chrome)) {
      clearBuilderRecovery(storage, key);
      return null;
    }
    return parsed as unknown as BuilderBrowserRecovery;
  } catch {
    clearBuilderRecovery(storage, key);
    return null;
  }
};

export const writeBuilderRecovery = (storage: Storage | null, key: string, draftVersion: number, data: PuckData, chrome: BuilderChrome, now = Date.now()): boolean => {
  try {
    if (!storage || !validData(data) || !validChrome(chrome)) return false;
    const payload: BuilderBrowserRecovery = {
      format: RECOVERY_FORMAT,
      version: RECOVERY_VERSION,
      baseDraftVersion: Math.max(0, draftVersion),
      updatedAt: now,
      data: structuredClone(data),
      chrome: structuredClone(chrome),
    };
    const serialized = JSON.stringify(payload);
    if (serialized.length > RECOVERY_MAX_BYTES) return false;
    storage.setItem(key, serialized);
    return true;
  } catch {
    return false;
  }
};
