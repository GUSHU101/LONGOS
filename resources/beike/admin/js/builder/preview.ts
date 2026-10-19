/** DOM nodes from an iframe belong to a different JavaScript realm. */
export const previewEventElement = (target: EventTarget | null): Element | null => {
  if (!target || !("nodeType" in target)) return null;
  const node = target as Node;
  return node.nodeType === 1 ? node as Element : node.parentElement;
};

export const previewFrameUrl = (src: string, revision: number): string => {
  const url = new URL(src, window.location.href);
  url.searchParams.set("design", "1");
  url.searchParams.set("builder_preview", String(revision));
  return url.href;
};
