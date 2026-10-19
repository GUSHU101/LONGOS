export const appTimezone = (): string =>
  document.querySelector<HTMLMetaElement>('meta[name="app-timezone"]')?.content || "UTC";

const partsInTimezone = (date: Date): Record<string, string> => {
  try {
    return Object.fromEntries(new Intl.DateTimeFormat("en-CA", {
      timeZone: appTimezone(),
      year: "numeric",
      month: "2-digit",
      day: "2-digit",
      hour: "2-digit",
      minute: "2-digit",
      hourCycle: "h23",
    }).formatToParts(date).map(part => [part.type, part.value]));
  } catch {
    return {};
  }
};

/** Convert an absolute instant to a datetime-local value in the store zone. */
export const datetimeLocalValue = (value: unknown): string => {
  if (typeof value !== "string" || !value.trim()) return "";
  const trimmed = value.trim();
  const plain = trimmed.match(/^(\d{4}-\d{2}-\d{2})[T\s](\d{2}:\d{2})/);
  if (plain && !/[zZ]|[+-]\d{2}:?\d{2}$/.test(trimmed)) return `${plain[1]}T${plain[2]}`;

  const date = new Date(trimmed);
  if (Number.isNaN(date.getTime())) return "";
  const parts = partsInTimezone(date);
  if (!parts.year || !parts.month || !parts.day || !parts.hour || !parts.minute) return "";

  return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
};

export const formatAppDateTime = (value: string): string => {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  try {
    return new Intl.DateTimeFormat(document.documentElement.lang || "zh-CN", {
      timeZone: appTimezone(),
      year: "numeric",
      month: "short",
      day: "numeric",
      hour: "2-digit",
      minute: "2-digit",
    }).format(date);
  } catch {
    return date.toLocaleString();
  }
};
