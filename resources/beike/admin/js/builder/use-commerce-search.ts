import { useEffect, useState } from "react";
import type { CommerceAdapter, CommerceResource, CommerceSearchItem } from "./commerce";

/** Shared debounce and stale-response protection for every resource picker. */
export function useCommerceSearch(adapter: CommerceAdapter | null | undefined, resource: CommerceResource | null, query: string) {
  const [results, setResults] = useState<CommerceSearchItem[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const term = query.trim();
  useEffect(() => {
    setResults([]);
    setError("");
    if (!resource || !adapter?.supports(resource) || !term) { setLoading(false); return; }
    const controller = new AbortController();
    setLoading(true);
    const timer = window.setTimeout(() => {
      adapter.search(resource, term, controller.signal).then(items => {
        if (!controller.signal.aborted) setResults(items);
      }).catch(reason => {
        if (!controller.signal.aborted) setError(reason instanceof Error ? reason.message : "搜索失败，请重新输入后重试");
      }).finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    }, 220);
    return () => { window.clearTimeout(timer); controller.abort(); };
  }, [adapter, resource, term]);
  return { results, loading, error, empty: Boolean(term && !loading && !error && !results.length) };
}
