export const languageMap = (value: unknown): value is Record<string, string> => Boolean(value && typeof value === "object" && !Array.isArray(value) && Object.values(value).every(item => typeof item === "string"));

export const readLocalized = (value: unknown, locale: string): string => {
  if (typeof value === "string") return value;
  if (!languageMap(value)) return "";
  return value[locale] ?? value.en ?? Object.values(value)[0] ?? "";
};

export const writeLocalized = (value: unknown, locale: string, text: string): string | Record<string, string> => languageMap(value) ? { ...value, [locale]: text } : text;

import { normalizeMediaUrl } from "./media-library";

export const readMediaUrl = (value: unknown, locale: string): string => {
  const media = value && typeof value === "object" && !Array.isArray(value) ? value as Record<string, unknown> : null;
  const candidate = readLocalized(media?.src ?? media?.path ?? media?.origin_url ?? media?.url ?? value, locale);
  return normalizeMediaUrl(candidate);
};
