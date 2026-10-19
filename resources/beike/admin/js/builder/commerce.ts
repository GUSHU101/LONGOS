export type CommerceResource = "product" | "category" | "brand" | "page" | "pageCategory";

export interface CommerceSearchItem {
  id: string | number;
  name?: string;
  sku?: string;
  price_format?: string;
  image_format?: string;
  image?: string;
  logo?: string;
  url?: string;
  status?: boolean | number;
}

export interface CommerceAdapter {
  supports(resource: CommerceResource): boolean;
  search(resource: CommerceResource, query: string, signal: AbortSignal): Promise<CommerceSearchItem[]>;
}

/** Admin API adapter: Builder fields never depend on Shopify runtime objects. */
export class LongosCommerceAdapter implements CommerceAdapter {
  public constructor(private readonly endpoints: Partial<Record<CommerceResource, string>>) {}

  public supports(resource: CommerceResource): boolean { return Boolean(this.endpoints[resource]); }

  public async search(resource: CommerceResource, query: string, signal: AbortSignal): Promise<CommerceSearchItem[]> {
    const endpoint = this.endpoints[resource];
    if (!endpoint || !query.trim()) return [];
    const url = new URL(endpoint, window.location.origin);
    url.searchParams.set("name", query.trim());
    const response = await fetch(url, { credentials: "same-origin", headers: { Accept: "application/json" }, signal });
    if (!response.ok) throw new Error(`商品内容搜索失败（${response.status}）`);
    const payload: unknown = await response.json();
    const data = payload && typeof payload === "object" ? (payload as { data?: unknown }).data : [];
    return Array.isArray(data)
      ? data.filter((item): item is CommerceSearchItem => Boolean(item && typeof item === "object" && (typeof (item as CommerceSearchItem).id === "string" || typeof (item as CommerceSearchItem).id === "number"))).slice(0, 20)
      : [];
  }
}
