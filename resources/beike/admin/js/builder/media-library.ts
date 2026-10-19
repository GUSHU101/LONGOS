export type MediaKind = "image" | "video";

export interface MediaLibraryFile {
  path: string;
  url: string;
  origin_url: string;
  name: string;
  mime: string;
  selected: boolean;
  [key: string]: unknown;
}

const imageExtensions = new Set(["jpg", "jpeg", "png", "gif", "webp"]);
const videoExtensions = new Set(["mp4"]);

const recordValue = (value: unknown): Record<string, unknown> | null => value && typeof value === "object" && !Array.isArray(value)
  ? value as Record<string, unknown>
  : null;

/** Normalize media values emitted by old forms, the media manager and plugins. */
export const normalizeMediaUrl = (value: unknown): string => {
  if (typeof value !== "string") return "";
  const candidate = value.trim().replace(/\\/g, "/");
  if (!candidate || /[\u0000-\u001f\u007f]/.test(candidate)) return "";
  if (/^https?:\/\//i.test(candidate)) {
    try {
      const url = new URL(candidate);
      return url.protocol === "http:" || url.protocol === "https:" ? url.toString() : "";
    } catch {
      return "";
    }
  }
  if (/^\/(?!\/)/.test(candidate)) return candidate;
  if (/^(?:image|upload)\//i.test(candidate)) return `/${candidate}`;
  return "";
};

export const normalizeMediaFile = (value: unknown): MediaLibraryFile | null => {
  const record = recordValue(value);
  if (!record) return null;
  const path = normalizeMediaUrl(record.path);
  const originUrl = normalizeMediaUrl(record.origin_url);
  const url = normalizeMediaUrl(record.url);
  const preferred = path || originUrl || url;
  if (!preferred) return null;

  return {
    ...record,
    path: path || preferred,
    url: url || originUrl || preferred,
    origin_url: originUrl || url || preferred,
    name: typeof record.name === "string" ? record.name.trim().slice(0, 255) : "",
    mime: typeof record.mime === "string" ? record.mime.trim().toLowerCase() : "",
    selected: record.selected === true,
  };
};

export const normalizeMediaFiles = (value: unknown, kind?: MediaKind | ""): MediaLibraryFile[] => {
  if (!Array.isArray(value)) return [];
  const files: MediaLibraryFile[] = [];
  const paths = new Set<string>();
  for (const item of value) {
    const file = normalizeMediaFile(item);
    if (!file || !mediaFileMatchesKind(file, kind) || paths.has(file.path)) continue;
    paths.add(file.path);
    files.push(file);
  }
  return files;
};

const extensionOf = (file: MediaLibraryFile): string => {
  const source = file.path || file.origin_url || file.url;
  try {
    const pathname = /^https?:\/\//i.test(source) ? new URL(source).pathname : source.split(/[?#]/, 1)[0];
    const match = pathname.toLowerCase().match(/\.([a-z0-9]+)$/);
    return match?.[1] ?? "";
  } catch {
    return "";
  }
};

export const mediaFileMatchesKind = (file: MediaLibraryFile, kind?: MediaKind | ""): boolean => {
  if (!kind) return true;
  if (file.mime) return file.mime.startsWith(`${kind}/`);
  const extension = extensionOf(file);
  return kind === "image" ? imageExtensions.has(extension) : videoExtensions.has(extension);
};

export const mediaSelectionFromMessage = (value: unknown, kind: MediaKind): MediaLibraryFile | null => {
  const record = recordValue(value);
  if (record?.type !== "beikeshop:file-manager-selection" || !Array.isArray(record.files)) return null;
  for (const item of record.files) {
    const file = normalizeMediaFile(item);
    if (file && mediaFileMatchesKind(file, kind)) return file;
  }
  return null;
};

interface SelectionOptions {
  multiple: boolean;
  shift: boolean;
  toggle: boolean;
}

/** Return a deterministic selection for normal, Ctrl/Command and Shift clicks. */
export const nextMediaSelection = (selected: number[], clicked: number, length: number, options: SelectionOptions): number[] => {
  if (!Number.isInteger(clicked) || clicked < 0 || clicked >= length) return selected.filter(index => index >= 0 && index < length);
  if (!options.multiple) return [clicked];
  const current = [...new Set(selected.filter(index => Number.isInteger(index) && index >= 0 && index < length))].sort((a, b) => a - b);
  if (options.shift && current.length) {
    const anchor = current[current.length - 1];
    const start = Math.min(anchor, clicked);
    const end = Math.max(anchor, clicked);
    return [...new Set([...current, ...Array.from({ length: end - start + 1 }, (_, index) => start + index)])].sort((a, b) => a - b);
  }
  if (options.toggle) return current.includes(clicked) ? current.filter(index => index !== clicked) : [...current, clicked].sort((a, b) => a - b);
  return [clicked];
};
