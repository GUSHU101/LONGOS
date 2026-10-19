import type { PuckData } from "./types";

export type PreviewChange =
  | { kind: "none" }
  | { kind: "module"; item: PuckData["content"][number] }
  | { kind: "reload"; reason: "structure" | "root" | "multiple" };

const moduleIdentity = (item: PuckData["content"][number]): string => JSON.stringify({
  id: item.props.id,
  type: item.type,
  placement: item.props.placement || "home",
  visible: item.props._builder?.visible !== false,
});

/**
 * Distinguish a field edit from a page-structure edit. Only a single stable
 * module can be replaced safely in-place; everything else refreshes the real
 * storefront after the draft has been persisted.
 */
export const classifyPreviewChange = (previous: PuckData, next: PuckData): PreviewChange => {
  if (JSON.stringify(previous.root) !== JSON.stringify(next.root)) return { kind: "reload", reason: "root" };
  if (previous.content.length !== next.content.length) return { kind: "reload", reason: "structure" };
  for (let index = 0; index < next.content.length; index += 1) {
    if (moduleIdentity(previous.content[index]) !== moduleIdentity(next.content[index])) return { kind: "reload", reason: "structure" };
  }
  const changed = next.content.filter((item, index) => JSON.stringify(item) !== JSON.stringify(previous.content[index]));
  if (!changed.length) return { kind: "none" };
  if (changed.length > 1) return { kind: "reload", reason: "multiple" };
  return { kind: "module", item: changed[0] };
};

