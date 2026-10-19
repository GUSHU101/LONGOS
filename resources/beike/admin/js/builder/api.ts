import type { PuckData } from "./types";
import type { BuilderChrome } from "./chrome-settings";

export interface BuilderRevision { id: number; created_at: string; engine: string; module_count: number; }
export interface BuilderMutationResult {
  draftVersion: number;
  hasUnpublishedChanges: boolean;
  published: boolean;
  revisionCreated: boolean;
  cacheRefreshed: boolean;
}
export interface BuilderModulePreview { moduleId: string; html: string; }

export class BuilderConflictError extends Error {
  public constructor(message: string, public readonly serverVersion: number) {
    super(message);
    this.name = "BuilderConflictError";
  }
}

export interface BuilderApi {
  save(data: PuckData, chrome: BuilderChrome, draftVersion: number, publish?: boolean, force?: boolean): Promise<BuilderMutationResult>;
  revisions(): Promise<BuilderRevision[]>;
  restoreRevision(id: number, draftVersion: number, force?: boolean): Promise<BuilderMutationResult>;
  importBackup(payload: unknown, draftVersion: number, force?: boolean): Promise<BuilderMutationResult>;
  previewModule(item: PuckData["content"][number], page: string, signal?: AbortSignal): Promise<BuilderModulePreview>;
}

/** Laravel boundary for Builder persistence; editor UI never owns endpoint details. */
export class LongosBuilderApi implements BuilderApi {
  public constructor(private readonly urls: { update: string; revisions: string; restoreRevision: string; importBackup: string; previewModule?: string }, private readonly csrfToken: string) {}

  public async save(data: PuckData, chrome: BuilderChrome, draftVersion: number, publish = false, force = false): Promise<BuilderMutationResult> {
    const response = await this.request(this.urls.update, {
      method: "PUT", credentials: "same-origin",
      headers: this.writeHeaders(draftVersion, force),
      body: JSON.stringify({ puck: data, header: chrome.header, footer: chrome.footer, menu: chrome.menu, publish }),
    }, publish ? "发布请求超时，请检查网络后重试" : "草稿保存超时，请检查网络后重试");
    return this.readMutation(response, `保存失败（${response.status}）`);
  }

  public async revisions(): Promise<BuilderRevision[]> {
    const response = await this.request(this.urls.revisions, { credentials: "same-origin", headers: { Accept: "application/json" } }, "发布记录加载超时，请稍后重试");
    if (!response.ok) throw new Error(`发布记录加载失败（${response.status}）`);
    const payload: unknown = await response.json();
    const data = payload && typeof payload === "object" ? (payload as { data?: unknown }).data : [];
    return Array.isArray(data) ? data.filter((item): item is BuilderRevision => Boolean(item && typeof item === "object" && typeof (item as BuilderRevision).id === "number")) : [];
  }

  public async restoreRevision(id: number, draftVersion: number, force = false): Promise<BuilderMutationResult> {
    const response = await this.request(this.urls.restoreRevision.replace("__revision__", String(id)), {
      method: "POST", credentials: "same-origin", headers: this.writeHeaders(draftVersion, force),
    }, "版本恢复超时，请稍后重试");
    return this.readMutation(response, `版本恢复失败（${response.status}）`);
  }

  public async importBackup(payload: unknown, draftVersion: number, force = false): Promise<BuilderMutationResult> {
    const response = await this.request(this.urls.importBackup, {
      method: "POST", credentials: "same-origin", headers: this.writeHeaders(draftVersion, force), body: JSON.stringify(payload),
    }, "备份导入超时，请稍后重试");
    return this.readMutation(response, `备份导入失败（${response.status}）`);
  }

  public async previewModule(item: PuckData["content"][number], page: string, signal?: AbortSignal): Promise<BuilderModulePreview> {
    if (!this.urls.previewModule) throw new Error("当前版本未配置实时预览端点");
    const response = await this.request(this.urls.previewModule, {
      method: "POST", credentials: "same-origin",
      headers: { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-TOKEN": this.csrfToken },
      body: JSON.stringify({ item, page }),
      signal,
    }, "实时预览超时，草稿仍会正常保存");
    const result: { status?: string; message?: string; data?: { module_id?: unknown; html?: unknown } } = await response.json().catch(() => ({}));
    if (!response.ok || result.status !== "success" || typeof result.data?.module_id !== "string" || typeof result.data.html !== "string") {
      throw new Error(result.message || `实时预览失败（${response.status}）`);
    }
    return { moduleId: result.data.module_id, html: result.data.html };
  }

  private writeHeaders(draftVersion: number, force: boolean): Record<string, string> {
    return {
      "Content-Type": "application/json", Accept: "application/json", "X-CSRF-TOKEN": this.csrfToken,
      "X-Builder-Draft-Version": String(Math.max(0, draftVersion)), "X-Builder-Force": force ? "1" : "0",
    };
  }

  private async request(url: string, init: RequestInit, timeoutMessage: string): Promise<Response> {
    const controller = new AbortController();
    const externalSignal = init.signal;
    const cancel = (): void => controller.abort(externalSignal?.reason);
    if (externalSignal?.aborted) cancel();
    else externalSignal?.addEventListener("abort", cancel, { once: true });
    const timeout = globalThis.setTimeout(() => controller.abort(), 30_000);
    try {
      const response = await fetch(url, { ...init, signal: controller.signal });
      const redirectedToLogin = response.redirected && /\/(?:admin\/)?login\/?$/i.test(new URL(response.url, window.location.href).pathname);
      if (response.status === 401 || response.status === 419 || redirectedToLogin) {
        throw new Error("登录会话已失效，请在保留当前页面的情况下重新登录，然后重试保存");
      }
      return response;
    } catch (error) {
      if (externalSignal?.aborted) throw new DOMException("Request cancelled", "AbortError");
      if (controller.signal.aborted) throw new Error(timeoutMessage);
      throw new Error(error instanceof Error ? `网络请求失败：${error.message}` : "网络请求失败，请检查连接后重试");
    } finally {
      globalThis.clearTimeout(timeout);
      externalSignal?.removeEventListener("abort", cancel);
    }
  }

  private async readMutation(response: Response, fallback: string): Promise<BuilderMutationResult> {
    const result: { status?: string; message?: string; data?: { draft_version?: unknown; has_unpublished_changes?: unknown; published?: unknown; revision_created?: unknown; cache_refreshed?: unknown } } = await response.json().catch(() => ({}));
    const serverVersion = typeof result.data?.draft_version === "number" ? result.data.draft_version : 0;
    if (response.status === 409) throw new BuilderConflictError(result.message || "草稿已在其他窗口更新", serverVersion);
    if (!response.ok || result.status !== "success") throw new Error(result.message || fallback);
    if (!Number.isSafeInteger(serverVersion) || serverVersion < 1 || typeof result.data?.has_unpublished_changes !== "boolean") {
      throw new Error("服务器返回的编辑器保存结果不完整；本地修改仍已保留，请重试");
    }
    return {
      draftVersion: serverVersion,
      hasUnpublishedChanges: result.data?.has_unpublished_changes === true,
      published: result.data?.published === true,
      revisionCreated: result.data?.revision_created === true,
      cacheRefreshed: result.data?.cache_refreshed !== false,
    };
  }
}
