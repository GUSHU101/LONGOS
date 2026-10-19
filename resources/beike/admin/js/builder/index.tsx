import "@puckeditor/core/puck.css";
import { createRoot } from "react-dom/client";
import { Component, createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ComponentProps, type ErrorInfo, type ReactNode } from "react";
import { Puck, usePuck, type Data, type Overrides, type Plugin, type Viewports } from "@puckeditor/core";
import { createPuckConfig, MediaField } from "./config";
import { LongosBuilderApi, BuilderConflictError, type BuilderMutationResult, type BuilderRevision } from "./api";
import { BuilderWriteQueue } from "./save-queue";
import { previewEventElement, previewFrameUrl } from "./preview";
import { classifyPreviewChange } from "./live-preview";
import { puckZhCnDictionary, zhComponentLabel } from "./i18n";
import { ChromeSettings, type BuilderChrome } from "./chrome-settings";
import { LongosCommerceAdapter, type CommerceSearchItem } from "./commerce";
import { useCommerceSearch } from "./use-commerce-search";
import { builderRecoveryKey, clearBuilderRecovery, readBuilderRecovery, writeBuilderRecovery } from "./recovery";
import type { BuilderBootstrap, BuilderModuleProps, BuilderRootProps, PuckData } from "./types";
import { formatAppDateTime } from "./timezone";

declare global { interface Window { BeikePuckBootstrap?: BuilderBootstrap; } }

const bootstrap = window.BeikePuckBootstrap;
const mount = document.getElementById("beike-puck-builder");

if (!bootstrap || !mount) {
  throw new Error("Puck Builder bootstrap data is missing.");
}

let recoveryStorage: Storage | null = null;
try { recoveryStorage = window.sessionStorage; } catch { /* Browser policy may disable session storage. */ }
const recoveryKey = builderRecoveryKey(bootstrap.updateUrl);
const browserRecovery = readBuilderRecovery(recoveryStorage, recoveryKey, bootstrap.draftVersion);
const initialData = (browserRecovery?.data || bootstrap.puckData) as Data;
const knownTypes = new Set(bootstrap.catalog.map(item => item.code));
const unknownTypes = (initialData.content || []).map(item => item.type).filter(type => !knownTypes.has(type));
const config = createPuckConfig(bootstrap.catalog, bootstrap.shopifyUnits, [], bootstrap.mediaLibraryUrl, bootstrap.videoMediaLibraryUrl, bootstrap.entityAutocompleteUrls, unknownTypes, bootstrap.locale, bootstrap.appEmbeds, bootstrap.editorLinkTypes);
const previewDevices = [
  { label: "小屏手机", shortLabel: "小屏", width: 320, icon: "Smartphone" },
  { label: "平板端", shortLabel: "平板", width: 768, icon: "Tablet" },
  { label: "桌面端", shortLabel: "桌面", width: 1024, icon: "Monitor" },
  { label: "宽屏端", shortLabel: "宽屏", width: 1440, icon: "Monitor" },
] as const;

const viewports: Viewports = previewDevices.map(device => ({
  label: `${device.label} (${device.width}px)`,
  width: device.width,
  icon: device.icon,
}));
const builderApi = new LongosBuilderApi({ update: bootstrap.updateUrl, revisions: bootstrap.revisionsUrl, restoreRevision: bootstrap.restoreRevisionUrl, importBackup: bootstrap.importUrl, previewModule: bootstrap.previewModuleUrl }, bootstrap.csrfToken);
const previewCommerce = new LongosCommerceAdapter(bootstrap.entityAutocompleteUrls);
const initialChrome: BuilderChrome = browserRecovery?.chrome || { header: bootstrap.headerSettings, footer: bootstrap.footerSettings, menu: bootstrap.menuSettings };
const puckRootZone = "root:default-zone";

type InsertableComponent = { type: string; label: string; description: string; category: string; keywords: string[]; availableOn: PreviewPage[]; maxInstances: number };
const categoryLabels: Record<string, string> = {
  hero: "首屏与横幅", commerce: "商品与分类", content: "内容模块", marketing: "营销转化", trust: "信任与保障", extension: "扩展模块",
};
const starterLayouts: Record<PreviewPage, Array<{ name: string; description: string; types: string[] }>> = {
  home: [
    { name: "高转化首页", description: "首屏、信任、商品、优惠与评价", types: ["img_text_slideshow_2", "trust_cards", "tab_product", "campaign_offer", "testimonials"] },
    { name: "品牌叙事首页", description: "首屏、品牌故事、商品与数据背书", types: ["img_text_slideshow", "story_block", "product", "social_proof", "icons"] },
  ],
  product: [
    { name: "商品转化组合", description: "活动优惠、支付保障与服务承诺", types: ["campaign_offer", "payment_trust", "icons"] },
  ],
  category: [
    { name: "分类营销组合", description: "活动入口、商品陈列与信任内容", types: ["promo_bar", "product", "trust_cards", "story_block"] },
  ],
  page: [
    { name: "内容故事组合", description: "品牌故事、内容列表与数据背书", types: ["story_block", "page", "social_proof"] },
  ],
};
const insertableComponents = Array.from(new Map<string, InsertableComponent>([
  ...bootstrap.catalog.map(item => [item.code, { type: item.code, label: item.name, description: item.description || "", category: item.category, keywords: item.keywords || [], availableOn: item.available_on || ["home"], maxInstances: item.max_instances || 12 }] as const),
]).values()).sort((left, right) => left.category.localeCompare(right.category, "zh-CN") || left.label.localeCompare(right.label, "zh-CN"));
const catalogByType = new Map(insertableComponents.map(item => [item.type, item]));
const newModuleId = (type: string): string => {
  const token = typeof crypto.randomUUID === "function"
    ? crypto.randomUUID().replaceAll("-", "").slice(0, 24)
    : `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 14)}`;
  // Keep the id inside the server's durable 96-character module-id limit.
  return `builder-${type.slice(0, 40)}-${token}`;
};

type SaveState = "saved" | "saving" | "publishing" | "unsaved" | "error" | "conflict" | "offline";
type PreviewPage = "home" | "product" | "category" | "page";
type StructureContext = "page" | "chrome";
interface PreviewTarget { id: string; name: string; url?: string; }
interface LivePreviewPatch { sequence: number; moduleId: string; html: string; }
const previewPageLabels: Record<PreviewPage, string> = { home: "首页", product: "商品详情页", category: "商品分类页", page: "内容页" };
const previewPageFor = (pageType: unknown): PreviewPage | null => {
  if (pageType === "home" || pageType === "product" || pageType === "category" || pageType === "page") return pageType;
  return null;
};
const previewMatchesPlacement = (placement: unknown, page: PreviewPage): boolean => {
  const normalized = typeof placement === "string" ? placement : "home";
  return normalized === page || normalized === "all" || (normalized === "both" && (page === "home" || page === "product"));
};

class BuilderErrorBoundary extends Component<{ children: ReactNode }, { error: Error | null }> {
  public state: { error: Error | null } = { error: null };
  public static getDerivedStateFromError(error: Error): { error: Error } { return { error }; }
  public componentDidCatch(error: Error, info: ErrorInfo): void { console.error("Puck Builder render failure", error, info); }
  public render(): ReactNode {
    if (!this.state.error) return this.props.children;
    return <main className="beike-puck-failure" role="alert"><h1>LONGOS编辑器暂时无法加载</h1><p>{this.state.error.message}</p><p>请刷新页面后重试；你的线上店铺不会受到影响。</p></main>;
  }
}

/**
 * Replaces Puck's visual block-card drawer. The center canvas is the real
 * storefront, so verified components are inserted through this compact
 * command list rather than through generic placeholder cards.
 */
const ExistingComponentInsertPanel = ({ page, onInserted }: { page: PreviewPage; onInserted: () => void }): React.JSX.Element => {
  const { appState, config: puckConfig, dispatch, selectedItem } = usePuck();
  const [query, setQuery] = useState("");
  const [category, setCategory] = useState("all");
  const normalizedQuery = query.trim().toLocaleLowerCase("zh-CN");
  const available = insertableComponents.filter(item => puckConfig.components[item.type] && item.availableOn.includes(page));
  const searched = available.filter(item => {
    return !normalizedQuery || `${item.label} ${item.description} ${item.type} ${item.keywords.join(" ")} ${categoryLabels[item.category] || item.category}`.toLocaleLowerCase("zh-CN").includes(normalizedQuery);
  });
  const categoryCounts = searched.reduce<Record<string, number>>((counts, item) => ({ ...counts, [item.category]: (counts[item.category] || 0) + 1 }), {});
  const categories = Object.keys(categoryLabels).filter(key => available.some(item => item.category === key));
  const candidates = searched.filter(item => category === "all" || item.category === category);
  const groups = candidates.reduce<Record<string, InsertableComponent[]>>((result, item) => {
    (result[item.category] ||= []).push(item);
    return result;
  }, {});
  const canInsert = (item: InsertableComponent): boolean => appState.data.content.filter(node => node.type === item.type).length < item.maxInstances;
  const makeNode = (item: InsertableComponent): Data["content"][number] => {
    const id = newModuleId(item.type);
    const defaults = structuredClone(puckConfig.components[item.type].defaultProps || {});
    return { type: item.type, props: { ...defaults, id, placement: page, _builder: { ...(defaults._builder as Record<string, unknown> || {}), module_id: id } } };
  };
  const destination = (): number => {
    const selectedIndex = appState.data.content.findIndex(node => node.props.id === selectedItem?.props.id && previewMatchesPlacement(node.props.placement, page));
    return selectedIndex >= 0 ? selectedIndex + 1 : appState.data.content.length;
  };
  const insert = (item: InsertableComponent): void => {
    if (!canInsert(item)) return;
    const destinationIndex = destination();
    // Page ownership is assigned from the page the merchant is actually
    // viewing. It remains a durable renderer value, but is deliberately not
    // another per-module form choice.
    dispatch({ type: "setData", recordHistory: true, data: previous => ({
      ...previous,
      content: [...previous.content.slice(0, destinationIndex), makeNode(item), ...previous.content.slice(destinationIndex)],
    }) });
    dispatch({ type: "setUi", ui: { itemSelector: { index: destinationIndex, zone: puckRootZone } } });
    onInserted();
  };
  const insertLayout = (types: string[]): void => {
    const items = types.map(type => catalogByType.get(type)).filter((item): item is InsertableComponent => Boolean(item && puckConfig.components[item.type] && item.availableOn.includes(page) && canInsert(item)));
    if (!items.length) return;
    const destinationIndex = destination();
    const nodes = items.map(makeNode);
    dispatch({ type: "setData", recordHistory: true, data: previous => ({ ...previous, content: [...previous.content.slice(0, destinationIndex), ...nodes, ...previous.content.slice(destinationIndex)] }) });
    dispatch({ type: "setUi", ui: { itemSelector: { index: destinationIndex, zone: puckRootZone } } });
    onInserted();
  };
  return <section className="beike-puck-existing-insert" aria-label="插入已有组件">
    <header><strong>添加模块</strong><p>添加到当前选中模块之后；未选中时放在{previewPageLabels[page]}末尾。</p></header>
    <section className="beike-puck-starter-layouts" aria-labelledby="longos-layout-title"><div><h2 id="longos-layout-title">页面模板</h2><span>添加一组常用模块，添加后可分别编辑、排序或删除</span></div>{starterLayouts[page].map(layout => {
      const addableCount = layout.types.map(type => catalogByType.get(type)).filter((item): item is InsertableComponent => Boolean(item && puckConfig.components[item.type] && item.availableOn.includes(page) && canInsert(item))).length;
      return <button type="button" key={layout.name} disabled={addableCount === 0} onClick={() => insertLayout(layout.types)} aria-label={addableCount ? `添加${layout.name}，包含${addableCount}个可用模块` : `${layout.name}中的模块均已达到数量上限`}><span><strong>{layout.name}</strong><small>{layout.description}</small></span><em>{addableCount ? `添加 ${addableCount}` : "已满"}</em></button>;
    })}</section>
    <div className="beike-puck-existing-insert__search"><label htmlFor="longos-component-search">搜索组件</label><div><input id="longos-component-search" value={query} onChange={event => setQuery(event.target.value)} placeholder="搜索名称、用途或关键词" />{query ? <button type="button" onClick={() => setQuery("")} aria-label="清除组件搜索">清除</button> : null}</div></div>
    <div className="beike-puck-existing-insert__filters" aria-label="按组件用途筛选"><button type="button" className={category === "all" ? "is-active" : ""} aria-pressed={category === "all"} onClick={() => setCategory("all")}>全部 <span>{searched.length}</span></button>{categories.map(key => <button type="button" key={key} className={category === key ? "is-active" : ""} aria-pressed={category === key} onClick={() => setCategory(key)}>{categoryLabels[key]} <span>{categoryCounts[key] || 0}</span></button>)}</div>
    <p className="beike-puck-existing-insert__result" role="status">当前显示 {candidates.length} 个适用于{previewPageLabels[page]}的模块</p>
    {!candidates.length ? <p className="beike-puck-existing-insert__empty">当前页面没有匹配的可用组件。请按用途、名称或功能关键词搜索。</p> : Object.entries(groups).map(([category, items]) => <section className="beike-puck-existing-insert__group" key={category}><h2>{categoryLabels[category] || category}</h2><div>{items.map(item => {
      const instanceCount = appState.data.content.filter(node => node.type === item.type).length;
      const limitReached = instanceCount >= item.maxInstances;
      return <button key={item.type} type="button" disabled={limitReached} onClick={() => insert(item)} aria-label={limitReached ? `${item.label}已达到${item.maxInstances}个上限` : `插入${item.label}：${item.description}`}><span><strong>{item.label}<i>{instanceCount}/{item.maxInstances}</i></strong><small>{limitReached ? `已达到 ${item.maxInstances} 个上限` : item.description}</small></span><em>{limitReached ? "已满" : "插入"}</em></button>;
    })}</div></section>)}
  </section>;
};

/** Keeps the left structure tab aligned with the actual preview context. */
const PageStructurePanel = ({ page, context, onEditChrome, onReturnToPage }: { page: PreviewPage; context: StructureContext; onEditChrome: (tab: "header" | "footer") => void; onReturnToPage: () => void }): React.JSX.Element => {
  const { appState, config: puckConfig, dispatch, getSelectorForId, selectedItem } = usePuck();
  const [draggedIndex, setDraggedIndex] = useState<number | null>(null);
  const [dropIndex, setDropIndex] = useState<number | null>(null);
  const [deletedModule, setDeletedModule] = useState<{ item: PuckData["content"][number]; index: number; label: string; token: number } | null>(null);
  useEffect(() => {
    if (!deletedModule) return;
    const timeout = window.setTimeout(() => setDeletedModule(current => current?.token === deletedModule.token ? null : current), 7000);
    return () => window.clearTimeout(timeout);
  }, [deletedModule?.token]);
  if (context === "chrome") {
    return <section className="beike-puck-structure" aria-label="页头与页尾结构">
      <header><strong>页头与页尾</strong><p>此结构适用于当前预览的所有页面。</p></header>
      <div className="beike-puck-structure__chrome">
        <button type="button" onClick={() => onEditChrome("header")}><span>页头</span><small>公告栏与主导航</small></button>
        <button type="button" onClick={() => onEditChrome("footer")}><span>页尾</span><small>品牌信息、联系方式与服务保障</small></button>
      </div>
      <button className="beike-puck-structure__back" type="button" onClick={onReturnToPage}>返回{previewPageLabels[page]}结构</button>
    </section>;
  }
  const modules = appState.data.content.map((item, index) => ({ item, index })).filter(({ item }) => previewMatchesPlacement(item.props?.placement, page));
  const select = (id: unknown, index: number): void => {
    const selector = typeof id === "string" ? getSelectorForId(id) : null;
    dispatch({ type: "setUi", ui: { itemSelector: selector || { index, zone: puckRootZone } } });
  };
  const moveModule = (sourceIndex: number, targetIndex: number, after = false): void => {
    if (sourceIndex === targetIndex) return;
    const pageIndexes = appState.data.content.map((item, index) => ({ item, index })).filter(({ item }) => previewMatchesPlacement(item.props?.placement, page)).map(({ index }) => index);
    const sourcePosition = pageIndexes.indexOf(sourceIndex);
    const targetPosition = pageIndexes.indexOf(targetIndex);
    if (sourcePosition < 0 || targetPosition < 0) return;
    const reordered = pageIndexes.map(index => appState.data.content[index]);
    const [moved] = reordered.splice(sourcePosition, 1);
    let destinationPosition = targetPosition - (sourcePosition < targetPosition ? 1 : 0) + (after ? 1 : 0);
    destinationPosition = Math.max(0, Math.min(destinationPosition, reordered.length));
    reordered.splice(destinationPosition, 0, moved);
    const reorderedByIndex = new Map(pageIndexes.map((index, position) => [index, reordered[position]]));
    dispatch({ type: "setData", recordHistory: true, data: previous => ({ ...previous, content: previous.content.map((item, index) => reorderedByIndex.get(index) || item) }) });
    const selectedPosition = reordered.findIndex(item => item.props.id === selectedItem?.props.id);
    if (selectedPosition >= 0) dispatch({ type: "setUi", ui: { itemSelector: { index: pageIndexes[selectedPosition], zone: puckRootZone } } });
  };
  const duplicateModule = (index: number): void => {
    const copy = structuredClone(appState.data.content[index]);
    const definition = catalogByType.get(copy.type);
    if (definition && appState.data.content.filter(item => item.type === copy.type).length >= definition.maxInstances) return;
    const id = newModuleId(copy.type);
    copy.props = { ...copy.props, id, _builder: { ...copy.props._builder, module_id: id } };
    dispatch({ type: "setData", recordHistory: true, data: previous => ({ ...previous, content: [...previous.content.slice(0, index + 1), copy, ...previous.content.slice(index + 1)] }) });
    dispatch({ type: "setUi", ui: { itemSelector: { index: index + 1, zone: puckRootZone } } });
  };
  const toggleModule = (index: number): void => {
    dispatch({ type: "setData", recordHistory: true, data: previous => ({ ...previous, content: previous.content.map((item, position) => position === index ? { ...item, props: { ...item.props, _builder: { ...item.props._builder, visible: item.props._builder?.visible === false } } } : item) }) });
  };
  const deleteModule = (index: number, label: string): void => {
    const item = appState.data.content[index];
    if (!item) return;
    setDeletedModule({ item: structuredClone(item), index, label, token: Date.now() });
    dispatch({ type: "remove", index, zone: puckRootZone });
    dispatch({ type: "setUi", ui: { itemSelector: null } });
  };
  const undoDelete = (): void => {
    if (!deletedModule) return;
    const restored = deletedModule;
    dispatch({ type: "setData", recordHistory: true, data: previous => {
      if (previous.content.some(item => item.props.id === restored.item.props.id)) return previous;
      const index = Math.max(0, Math.min(restored.index, previous.content.length));
      return { ...previous, content: [...previous.content.slice(0, index), restored.item, ...previous.content.slice(index)] };
    } });
    dispatch({ type: "setUi", ui: { itemSelector: { index: restored.index, zone: puckRootZone } } });
    setDeletedModule(null);
  };
  return <section className="beike-puck-structure" aria-label={`${previewPageLabels[page]}结构`}>
    <header><strong>{previewPageLabels[page]}结构 · {modules.length} 个模块</strong><p>拖动排序，或使用下方操作。隐藏模块会保留设置，随时可以重新显示。</p></header>
    <nav className="beike-puck-structure__global" aria-label="全店区域">
      <span><strong>全店区域</strong><small>所有页面共用</small></span>
      <button type="button" onClick={() => onEditChrome("header")}><span>页头</span><small>公告栏与导航</small></button>
      <button type="button" onClick={() => onEditChrome("footer")}><span>页尾</span><small>品牌、链接、付款与服务保障</small></button>
    </nav>
    {!modules.length ? <p className="beike-puck-structure__empty">当前页面还没有装修模块。打开“插入”选择一个模块开始编辑。</p> : <div className="beike-puck-structure__items">{modules.map(({ item, index }, position) => {
      const config = puckConfig.components[item.type];
      const label = typeof config?.label === "string" ? config.label : zhComponentLabel({ type: item.type, label: item.type });
      const hidden = item.props._builder?.visible === false;
      const selected = selectedItem?.props.id === item.props.id;
      const definition = catalogByType.get(item.type);
      const duplicateLimitReached = Boolean(definition && appState.data.content.filter(node => node.type === item.type).length >= definition.maxInstances);
      return <article key={typeof item.props?.id === "string" ? item.props.id : `${item.type}-${index}`} className={`beike-puck-structure__item ${selected ? "is-selected" : ""} ${hidden ? "is-hidden" : ""} ${draggedIndex === index ? "is-dragging" : ""} ${dropIndex === index ? "is-drop-target" : ""}`} draggable onDragStart={event => { event.dataTransfer.effectAllowed = "move"; event.dataTransfer.setData("text/plain", String(index)); setDraggedIndex(index); }} onDragOver={event => { event.preventDefault(); event.dataTransfer.dropEffect = "move"; }} onDragEnter={() => setDropIndex(index)} onDragLeave={event => { if (!event.currentTarget.contains(event.relatedTarget as Node | null)) setDropIndex(null); }} onDrop={event => { event.preventDefault(); const source = draggedIndex; if (source !== null && Number.isInteger(source)) { const bounds = event.currentTarget.getBoundingClientRect(); moveModule(source, index, event.clientY > bounds.top + bounds.height / 2); } setDraggedIndex(null); setDropIndex(null); }} onDragEnd={() => { setDraggedIndex(null); setDropIndex(null); }}>
        <button className="beike-puck-structure__item-select" type="button" onClick={() => select(item.props?.id, index)} aria-pressed={selected} aria-label={`编辑${label}`}><span aria-hidden="true">⠿</span><strong>{label}{hidden ? "（已隐藏）" : ""}</strong><small>{item.props?.placement === "all" ? "所有页面" : item.props?.placement === "both" ? "首页和商品详情页" : previewPageLabels[page]}</small></button>
        <div className="beike-puck-structure__item-actions" aria-label={`${label}操作`}><button type="button" disabled={duplicateLimitReached} title={duplicateLimitReached ? `最多添加 ${definition?.maxInstances} 个` : undefined} onClick={() => duplicateModule(index)} aria-label={duplicateLimitReached ? `${label}已达到数量上限` : `复制${label}`}>复制</button><button type="button" onClick={() => toggleModule(index)} aria-label={`${hidden ? "显示" : "隐藏"}${label}`}>{hidden ? "显示" : "隐藏"}</button><button type="button" disabled={position === 0} onClick={() => moveModule(index, modules[position - 1].index)} aria-label={`上移${label}`}>上移</button><button type="button" disabled={position === modules.length - 1} onClick={() => moveModule(index, modules[position + 1].index, true)} aria-label={`下移${label}`}>下移</button><button className="is-danger" type="button" onClick={() => deleteModule(index, label)} aria-label={`删除${label}`}>删除</button></div>
      </article>;
    })}</div>}
    {deletedModule ? <div className="beike-puck-undo" role="status" aria-live="polite"><span>已删除“{deletedModule.label}”</span><button type="button" onClick={undoDelete}>撤销</button><button type="button" aria-label="关闭撤销提示" onClick={() => setDeletedModule(null)}>×</button></div> : null}
  </section>;
};

const configuredValue = (value: unknown): boolean => {
  if (typeof value === "string") return value.trim() !== "";
  if (typeof value === "number" || typeof value === "boolean") return true;
  if (Array.isArray(value)) return value.length > 0;
  if (value && typeof value === "object") return Object.values(value as Record<string, unknown>).some(configuredValue);
  return false;
};

const moduleSetupStatus = (type: string, props: BuilderModuleProps): { ready: boolean; label: string } => {
  const list = (key: string): unknown[] => Array.isArray(props[key]) ? props[key] as unknown[] : [];
  const listHas = (key: string, childKey?: string): boolean => list(key).some(item => childKey && item && typeof item === "object" && !Array.isArray(item) ? configuredValue((item as Record<string, unknown>)[childKey]) : configuredValue(item));
  if (["img_text_slideshow", "img_text_slideshow_2"].includes(type)) return listHas("images", "image") ? { ready: true, label: "轮播图片已配置" } : { ready: false, label: "请至少添加一张轮播图片" };
  if (type === "product") return list("products").length ? { ready: true, label: `已选择 ${list("products").length} 件商品` } : { ready: false, label: "请选择要展示的商品" };
  if (type === "tab_product") return list("tabs").some(tab => tab && typeof tab === "object" && Array.isArray((tab as Record<string, unknown>).products) && ((tab as Record<string, unknown>).products as unknown[]).length) ? { ready: true, label: "商品标签页已配置" } : { ready: false, label: "请为至少一个标签页选择商品" };
  if (type === "brand") return list("brands").length ? { ready: true, label: `已选择 ${list("brands").length} 个品牌` } : { ready: false, label: "请选择要展示的品牌" };
  if (type === "page") return list("items").length ? { ready: true, label: `已选择 ${list("items").length} 个内容页` } : { ready: false, label: "请选择要展示的内容页" };
  if (type === "story_block") return configuredValue(props.image) ? { ready: true, label: "故事图片已配置" } : { ready: false, label: "建议添加一张故事图片" };
  if (type === "feature_grid") return listHas("items", "image") ? { ready: true, label: "精选卡片图片已配置" } : { ready: false, label: "建议为精选卡片添加图片" };
  if (type === "custom_code") return configuredValue(props.html) ? { ready: true, label: "自定义内容已配置" } : { ready: false, label: "请填写安全 HTML 内容" };
  return { ready: true, label: "模块可直接使用" };
};

const ModuleInspectorPanel = (): React.JSX.Element => {
  const { selectedItem, config: puckConfig } = usePuck();
  const type = typeof selectedItem?.type === "string" ? selectedItem.type : "";
  const definition = type ? catalogByType.get(type) : undefined;
  const component = type ? puckConfig.components[type] : undefined;
  if (!type || !selectedItem?.props) return <section className="beike-puck-module-inspector is-empty"><header><strong>模块设置</strong><p>从页面预览或“结构”中选择一个模块，即可编辑内容、样式和响应式显示。</p></header><Puck.Fields /></section>;
  const label = typeof component?.label === "string" ? component.label : definition?.label || type;
  const props = selectedItem.props as BuilderModuleProps;
  const setup = moduleSetupStatus(type, props);
  const hidden = props._builder?.visible === false;
  const placement = props.placement === "all" ? "所有页面" : props.placement === "both" ? "首页与商品页" : previewPageLabels[previewPageFor(props.placement) || "home"];
  return <section className="beike-puck-module-inspector">
    <header><span className="beike-puck-module-inspector__eyebrow">正在编辑</span><strong>{label}</strong><p>{definition?.description || "编辑该模块的内容、外观与显示规则。"}</p><div><span>{placement}</span><span className={hidden ? "is-warning" : "is-positive"}>{hidden ? "当前已隐藏" : "当前可见"}</span><span className={setup.ready ? "is-positive" : "is-warning"}>{setup.label}</span></div></header>
    <Puck.Fields />
  </section>;
};

/** Store-wide design controls belong in a predictable workspace, not behind
 * an accidental click on the canvas root. */
const ThemeSettingsPanel = (): React.JSX.Element => {
  const { appState, dispatch } = usePuck();
  const rootProps = appState.data.root.props as unknown as BuilderRootProps;
  const style = rootProps.globalStyle || {};
  const metadata = rootProps.pageMetadata || {};
  const embeds = rootProps.appEmbeds || {};
  const setRoot = (changes: Partial<BuilderRootProps>): void => {
    dispatch({ type: "setData", recordHistory: true, data: previous => ({ ...previous, root: { ...previous.root, props: { ...previous.root.props, ...changes } } }) });
  };
  const setStyle = (key: string, value: unknown): void => setRoot({ globalStyle: { ...style, [key]: value } });
  const setMetadata = (key: string, value: string): void => setRoot({ pageMetadata: { ...metadata, [key]: value } });
  const numberControls: Array<{ key: string; label: string; min: number; max: number; step: number; suffix: string; fallback: number }> = [
    { key: "font_size", label: "基础字号", min: 13, max: 20, step: 1, suffix: "px", fallback: 15 },
    { key: "page_width", label: "页面最大宽度", min: 1000, max: 1600, step: 20, suffix: "px", fallback: 1440 },
    { key: "section_spacing", label: "模块间距", min: 0, max: 160, step: 4, suffix: "px", fallback: 64 },
    { key: "grid_gap", label: "网格间距", min: 4, max: 40, step: 2, suffix: "px", fallback: 16 },
    { key: "border_radius", label: "全局圆角", min: 0, max: 24, step: 1, suffix: "px", fallback: 12 },
  ];
  const colorControls = [
    ["primary_color", "主色", "#000000"], ["accent_color", "强调色", "#C1FBD4"],
    ["background_color", "页面背景", "#FBFBF5"], ["text_color", "正文颜色", "#000000"],
  ];
  return <section className="beike-puck-theme-settings" aria-label="主题与店铺外观">
    <header><strong>主题与店铺外观</strong><p>一次设置全店的颜色、排版、间距、SEO 和应用嵌入。</p></header>
    <fieldset><legend>品牌配色</legend><div className="beike-puck-theme-settings__colors">{colorControls.map(([key, label, fallback]) => <label key={key}><span>{label}</span><span><input type="color" value={typeof style[key] === "string" && /^#[0-9a-f]{6}$/i.test(String(style[key])) ? String(style[key]) : fallback} onChange={event => setStyle(key, event.target.value.toUpperCase())} /><output>{typeof style[key] === "string" ? String(style[key]) : fallback}</output></span></label>)}</div></fieldset>
    <fieldset><legend>排版与布局</legend><label className="beike-puck-theme-settings__select"><span>字体风格</span><select value={typeof style.font_family === "string" ? style.font_family : "system"} onChange={event => setStyle("font_family", event.target.value)}><option value="system">系统无衬线</option><option value="serif">衬线字体</option><option value="rounded">圆角无衬线</option></select></label><label className="beike-puck-theme-settings__select"><span>商品卡片</span><select value={typeof style.card_style === "string" ? style.card_style : "card"} onChange={event => setStyle("card_style", event.target.value)}><option value="card">卡片式</option><option value="standard">标准式</option></select></label>{numberControls.map(control => { const raw = Number(style[control.key]); const value = Number.isFinite(raw) ? Math.min(control.max, Math.max(control.min, raw)) : control.fallback; return <label className="beike-puck-theme-settings__range" key={control.key}><span>{control.label}<output>{value}{control.suffix}</output></span><input type="range" min={control.min} max={control.max} step={control.step} value={value} onChange={event => setStyle(control.key, Number(event.target.value))} /></label>; })}<label className="beike-puck-theme-settings__toggle"><input type="checkbox" checked={style.reduce_motion === true} onChange={event => setStyle("reduce_motion", event.target.checked)} /><span><strong>减少前台动效</strong><small>为易晕动的顾客提供更平稳的浏览体验。</small></span></label></fieldset>
    <fieldset><legend>页面与 SEO</legend><label><span>后台页面名称</span><input type="text" value={metadata.title || ""} maxLength={160} onChange={event => setMetadata("title", event.target.value)} /></label><label><span>URL 别名</span><input type="text" value={metadata.slug || ""} maxLength={160} placeholder="例如 summer-sale" onChange={event => setMetadata("slug", event.target.value.toLowerCase().replace(/\s+/g, "-"))} /></label><label><span>SEO 标题</span><input type="text" value={metadata.seo_title || ""} maxLength={160} onChange={event => setMetadata("seo_title", event.target.value)} /></label><label><span>SEO 描述</span><textarea value={metadata.seo_description || ""} maxLength={320} rows={4} onChange={event => setMetadata("seo_description", event.target.value)} /></label><label><span>社交分享特色图</span><MediaField field={{ label: "社交分享特色图" }} value={metadata.featured_image || ""} onChange={value => setMetadata("featured_image", value)} /></label></fieldset>
    {bootstrap.appEmbeds.length ? <fieldset><legend>应用嵌入</legend><p className="beike-puck-theme-settings__hint">在不改动页面模块的情况下，统一控制追踪、客服等前台扩展。</p>{bootstrap.appEmbeds.map(embed => <label className="beike-puck-theme-settings__toggle" key={embed.code}><input type="checkbox" checked={(embeds[embed.code] as { enabled?: boolean } | undefined)?.enabled ?? embed.default_enabled} onChange={event => setRoot({ appEmbeds: { ...embeds, [embed.code]: { ...((embeds[embed.code] && typeof embeds[embed.code] === "object" && !Array.isArray(embeds[embed.code])) ? embeds[embed.code] as Record<string, unknown> : {}), enabled: event.target.checked } } })} /><span><strong>{embed.name}</strong><small>{embed.description}</small></span></label>)}</fieldset> : null}
  </section>;
};

/** Keeps secondary and recovery actions out of the main editing path. */
const HeaderMoreMenu = ({ previewUrl, onRefresh, revisions, exportUrl, onImport }: { previewUrl: string; onRefresh: () => void; revisions: ReactNode; exportUrl: string; onImport: () => void }): React.JSX.Element => <details className="beike-puck-header-more">
  <summary>更多</summary>
  <div role="group" aria-label="更多编辑器操作">
    <button type="button" onClick={onRefresh}>刷新主题预览</button>
    <a href={previewUrl} target="_blank" rel="noreferrer">在新窗口打开主题</a>
    {revisions}
    <a href={exportUrl}>导出 LONGOS 备份</a>
    <button type="button" onClick={onImport}>导入 LONGOS 备份</button>
  </div>
</details>;

/** Synchronizes the Puck root page type, the iframe, and the structure tab. */
const BuilderHeaderControls = ({ page, target, context, saveState, saveMessage, hasUnpublishedChanges, onPageChange, onTargetChange, onShowChrome, onSave, onPublish, onRefresh, previewUrl, onOpenRevisions, onExport, onImport }: { page: PreviewPage; target: PreviewTarget | null; context: StructureContext; saveState: SaveState; saveMessage: string; hasUnpublishedChanges: boolean; onPageChange: (page: PreviewPage) => void; onTargetChange: (target: PreviewTarget | null) => void; onShowChrome: () => void; onSave: () => void; onPublish: () => void; onRefresh: () => void; previewUrl: string; onOpenRevisions: ReactNode; onExport: string; onImport: () => void }): React.JSX.Element => {
  const { dispatch } = usePuck();
  const busy = saveState === "saving" || saveState === "publishing";
  const stateLabel: Record<SaveState, string> = { saved: hasUnpublishedChanges ? "草稿已保存" : "已同步", saving: "保存中", publishing: "发布中", unsaved: "待保存", error: "保存失败", conflict: "存在冲突", offline: "网络已断开" };
  const changePage = (next: PreviewPage): void => {
    dispatch({ type: "setData", recordHistory: true, data: previous => ({ ...previous, root: { ...previous.root, props: { ...previous.root.props, pageType: next } } }) });
    dispatch({ type: "setUi", ui: { itemSelector: null } });
    onPageChange(next);
  };
  return <div className="beike-puck-header-controls">
    <output className={`beike-puck-save-status is-${saveState}`} role="status" aria-live="polite"><i aria-hidden="true" /><span><strong>{stateLabel[saveState]}</strong><small>{saveMessage}</small></span></output>
    <div className="beike-puck-header-context">
      <label>预览页面<select value={page} onChange={event => changePage(event.target.value as PreviewPage)}><option value="home">首页</option><option value="product">商品详情页</option><option value="category">商品分类页</option><option value="page">内容页</option></select></label>
      {page !== "home" ? <PreviewTargetPicker key={page} page={page} target={target} onSelect={onTargetChange} /> : null}
      <button className={`beike-puck-header-action is-chrome ${context === "chrome" ? "is-active" : ""}`} type="button" onClick={onShowChrome}>页头与页尾</button>
    </div>
    <div className="beike-puck-header-actions">
      <button className="beike-puck-header-action" type="button" disabled={busy || saveState === "conflict"} onClick={onSave}>{saveState === "saving" ? "保存中…" : saveState === "error" || saveState === "offline" ? "重试保存" : "保存草稿"}<kbd>Ctrl S</kbd></button>
      <button className="beike-puck-header-action is-primary" type="button" disabled={busy || saveState === "conflict" || saveState === "offline"} onClick={onPublish}>{saveState === "publishing" ? "正在发布…" : hasUnpublishedChanges ? "发布更改" : "重新发布"}</button>
      <HeaderMoreMenu previewUrl={previewUrl} onRefresh={onRefresh} revisions={onOpenRevisions} exportUrl={onExport} onImport={onImport} />
    </div>
  </div>;
};

/**
 * Renders the real storefront inside Puck's native center canvas. Selection
 * uses Puck's public selector API, not a hidden generic-component preview.
 */
const StorefrontThemePreview = ({ src, revision, status, patch, swiperStyleUrl, swiperScriptUrl, chromeTab, onLoad, onPreviewError, onPatchApplied, onPatchFailure, onBlockedAction, onSelectChrome, onSelectPageModule }: { src: string; revision: number; status: string; patch: LivePreviewPatch | null; swiperStyleUrl: string; swiperScriptUrl: string; chromeTab: "header" | "footer" | null; onLoad: () => void; onPreviewError: (message: string) => void; onPatchApplied: () => void; onPatchFailure: () => void; onBlockedAction: () => void; onSelectChrome: (tab: "header" | "footer") => void; onSelectPageModule: () => void }): React.JSX.Element => {
  const frame = useRef<HTMLIFrameElement>(null);
  const stage = useRef<HTMLDivElement>(null);
  const [deviceWidth, setDeviceWidth] = useState(1024);
  const [stageSize, setStageSize] = useState({ width: 1280, height: 720 });
  useEffect(() => {
    if (!stage.current) return;
    const observer = new ResizeObserver(entries => {
      const { width, height } = entries[0].contentRect;
      if (width > 0 && height > 0) setStageSize({ width, height });
    });
    observer.observe(stage.current);
    return () => observer.disconnect();
  }, []);
  const scale = Math.min(1, stageSize.width / deviceWidth);
  const scrollPosition = useRef({ src, x: 0, y: 0 });
  const { dispatch, getSelectorForId, selectedItem } = usePuck();
  const handlers = useRef({ dispatch, getSelectorForId, onBlockedAction, onPreviewError, onSelectChrome, onSelectPageModule });
  const selectionCleanup = useRef<(() => void) | null>(null);
  handlers.current = { dispatch, getSelectorForId, onBlockedAction, onPreviewError, onSelectChrome, onSelectPageModule };
  const selectedModuleId = typeof selectedItem?.props.id === "string" ? selectedItem.props.id : "";
  const updateSelectedOutline = (): void => {
    try {
      const documentInFrame = frame.current?.contentDocument;
      if (!documentInFrame) return;
      documentInFrame.querySelectorAll(".beike-builder-module-selected, .beike-builder-chrome-selected").forEach(element => element.classList.remove("beike-builder-module-selected", "beike-builder-chrome-selected"));
      if (selectedModuleId) documentInFrame.getElementById(`module-${selectedModuleId}`)?.classList.add("beike-builder-module-selected");
      if (chromeTab) documentInFrame.querySelector(`.beike-builder-chrome-${chromeTab}`)?.classList.add("beike-builder-chrome-selected");
    } catch {
      // A failed decoration must never prevent the actual storefront preview.
    }
  };
  useEffect(() => { updateSelectedOutline(); }, [chromeTab, selectedModuleId]);
  const attachSelection = (): boolean => {
    try {
      const documentInFrame = frame.current?.contentDocument;
      if (!documentInFrame) return false;
      const errorTitle = documentInFrame.querySelector<HTMLElement>(".error-title");
      if (errorTitle) {
        handlers.current.onPreviewError(`主题预览加载失败：${errorTitle.textContent?.trim() || "服务器返回错误页面"}`);
        return false;
      }
      selectionCleanup.current?.();
      let style = documentInFrame.getElementById("longos-builder-selection-style") as HTMLStyleElement | null;
      if (!style) {
        style = documentInFrame.createElement("style");
        style.id = "longos-builder-selection-style";
        style.textContent = ".module-item-design{cursor:pointer;outline-offset:4px;transition:outline-color .15s ease,box-shadow .15s ease}.module-item-design:hover{outline:2px dashed #84adff}.beike-builder-module-selected{outline:3px solid #2563eb!important;box-shadow:0 0 0 5px rgba(191,219,254,.82)!important}.beike-builder-chrome-header,.beike-builder-chrome-footer{cursor:pointer;outline-offset:4px}.beike-builder-chrome-selected{outline:3px solid #16a34a!important;box-shadow:0 0 0 5px rgba(187,247,208,.82)!important}";
        documentInFrame.head.append(style);
      }
      documentInFrame.querySelector<HTMLElement>("body > header, #app > header")?.classList.add("beike-builder-chrome-header");
      documentInFrame.querySelector<HTMLElement>("footer.site-footer")?.classList.add("beike-builder-chrome-footer");
      const previewWindow = documentInFrame.defaultView;
      if (scrollPosition.current.src === src) previewWindow?.scrollTo(scrollPosition.current.x, scrollPosition.current.y);
      const rememberScroll = (): void => {
        if (!previewWindow) return;
        scrollPosition.current = { src, x: previewWindow.scrollX, y: previewWindow.scrollY };
      };
      const selectFromPreview = (event: MouseEvent): void => {
        const clickedElement = previewEventElement(event.target);
        const chrome = clickedElement?.closest<HTMLElement>(".beike-builder-chrome-header, .beike-builder-chrome-footer");
        if (chrome) {
          event.preventDefault();
          event.stopImmediatePropagation();
          handlers.current.onSelectChrome(chrome.classList.contains("beike-builder-chrome-footer") ? "footer" : "header");
          return;
        }
        const target = clickedElement?.closest<HTMLElement>("[id^='module-']");
        if (!target) return;
        const moduleId = target.id.slice("module-".length);
        const selector = handlers.current.getSelectorForId(moduleId);
        if (!selector) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        handlers.current.onSelectPageModule();
        handlers.current.dispatch({ type: "setUi", ui: { itemSelector: selector } });
      };
      const blockPreviewAction = (event: MouseEvent): void => {
        const target = previewEventElement(event.target);
        if (!target?.closest("a, button, input, select, textarea, label, [role='button']")) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        handlers.current.onBlockedAction();
      };
      const blockPreviewSubmit = (event: SubmitEvent): void => {
        event.preventDefault();
        event.stopImmediatePropagation();
        handlers.current.onBlockedAction();
      };
      previewWindow?.addEventListener("scroll", rememberScroll, { passive: true });
      documentInFrame.addEventListener("click", selectFromPreview, true);
      documentInFrame.addEventListener("click", blockPreviewAction, true);
      documentInFrame.addEventListener("submit", blockPreviewSubmit, true);
      selectionCleanup.current = () => {
        previewWindow?.removeEventListener("scroll", rememberScroll);
        documentInFrame.removeEventListener("click", selectFromPreview, true);
        documentInFrame.removeEventListener("click", blockPreviewAction, true);
        documentInFrame.removeEventListener("submit", blockPreviewSubmit, true);
      };
      updateSelectedOutline();
      return true;
    } catch {
      // The live page may fail independently; preserve Puck controls.
      handlers.current.onPreviewError("主题预览无法访问，请确认预览地址与后台使用同一域名");
      return false;
    }
  };
  useEffect(() => () => selectionCleanup.current?.(), []);
  useEffect(() => {
    if (!patch) return;
    const applyPatch = async (): Promise<void> => {
      try {
        const documentInFrame = frame.current?.contentDocument;
        if (!documentInFrame) throw new Error("Preview document is not ready");
        const target = documentInFrame.querySelector<HTMLElement>(`[data-builder-module-id="${patch.moduleId}"]`) || documentInFrame.getElementById(`module-${patch.moduleId}`)?.parentElement;
        if (!target) throw new Error("Preview module target is missing");
        const template = documentInFrame.createElement("template");
        template.innerHTML = patch.html.trim();
        const replacement = template.content.firstElementChild as HTMLElement | null;
        if (!replacement) throw new Error("Preview renderer returned an empty fragment");

        const needsSwiper = replacement.querySelector(".swiper") !== null;
        if (needsSwiper && swiperStyleUrl && !documentInFrame.querySelector(`link[href="${swiperStyleUrl}"]`)) {
          const link = documentInFrame.createElement("link");
          link.rel = "stylesheet"; link.href = swiperStyleUrl; documentInFrame.head.append(link);
        }
        if (needsSwiper && swiperScriptUrl && typeof (documentInFrame.defaultView as unknown as { Swiper?: unknown })?.Swiper !== "function") {
          await new Promise<void>((resolve, reject) => {
            const existing = documentInFrame.querySelector<HTMLScriptElement>(`script[src="${swiperScriptUrl}"]`);
            if (existing) { existing.addEventListener("load", () => resolve(), { once: true }); existing.addEventListener("error", () => reject(new Error("Swiper load failed")), { once: true }); return; }
            const script = documentInFrame.createElement("script");
            script.src = swiperScriptUrl; script.onload = () => resolve(); script.onerror = () => reject(new Error("Swiper load failed")); documentInFrame.head.append(script);
          });
        }
        target.replaceWith(replacement);
        // Scripts created by innerHTML are inert. Recreate only the trusted,
        // server-rendered section scripts so carousel and offer interactions
        // behave exactly like a full storefront request.
        for (const inert of Array.from(replacement.querySelectorAll("script"))) {
          const executable = documentInFrame.createElement("script");
          for (const attribute of Array.from(inert.attributes)) executable.setAttribute(attribute.name, attribute.value);
          executable.textContent = inert.textContent;
          inert.replaceWith(executable);
        }
        const PreviewCustomEvent = documentInFrame.defaultView?.CustomEvent;
        if (PreviewCustomEvent) documentInFrame.dispatchEvent(new PreviewCustomEvent("longos:builder-preview-updated", { detail: { moduleId: patch.moduleId } }));
        attachSelection();
        onPatchApplied();
      } catch {
        // Tell the owner to reload after autosave; silently swallowing this
        // leaves merchants looking at a stale canvas while editing.
        onPatchFailure();
      }
    };
    void applyPatch();
  }, [patch?.sequence]);
  return <section className="beike-puck-storefront-preview" aria-label="真实前台主题预览">
    <header><strong>真实主题预览</strong><div className="beike-puck-device-controls" role="group" aria-label="预览设备">{previewDevices.map(device => <button type="button" key={device.width} aria-pressed={deviceWidth === device.width} onClick={() => setDeviceWidth(device.width)} title={`${device.label} ${device.width}px`}><span>{device.shortLabel}</span><small>{device.width}px</small></button>)}</div><output aria-live="polite">{status}</output></header>
    <div ref={stage} className="beike-puck-preview-stage">
      <iframe ref={frame} key={`${src}-${revision}`} title="真实前台主题预览" style={{ width: deviceWidth, height: stageSize.height / scale, transform: `scale(${scale})`, left: Math.max(0, (stageSize.width - deviceWidth * scale) / 2) }} onLoad={() => { if (attachSelection()) onLoad(); }} onError={() => onPreviewError("主题预览加载失败，请检查店铺地址或服务器日志")} src={previewFrameUrl(src, revision)} />
    </div>
  </section>;
};

const PreviewContext = createContext<ComponentProps<typeof StorefrontThemePreview> | null>(null);
// Keep the component identity stable: changing a save label must not reload the iframe.
const editorOverrides: Partial<Overrides> = { preview: function ThemePreview() {
  const props = useContext(PreviewContext);
  return props ? <StorefrontThemePreview {...props} /> : <></>;
} };

/** Selects an actual storefront record before loading the full-theme iframe. */
const PreviewTargetPicker = ({ page, target, onSelect }: { page: Exclude<PreviewPage, "home">; target: PreviewTarget | null; onSelect: (target: PreviewTarget | null) => void }): React.JSX.Element => {
  const [query, setQuery] = useState("");
  const { results, loading, error, empty } = useCommerceSearch(previewCommerce, page, query);
  const labels: Record<Exclude<PreviewPage, "home">, string> = { product: "商品", category: "商品分类", page: "内容页" };
  const select = (item: CommerceSearchItem): void => { onSelect({ id: String(item.id), name: item.name || `#${item.id}`, url: item.url }); setQuery(""); };
  return <div className="beike-puck-preview-target"><label>预览{labels[page]}<input value={query} onChange={event => setQuery(event.target.value)} placeholder={`搜索${labels[page]}`} aria-label={`搜索预览${labels[page]}`} /></label>{target ? <button type="button" className="beike-puck-preview-target__selected" onClick={() => onSelect(null)}><span>正在预览：{target.name}</span><small>清除</small></button> : null}{loading || error || empty ? <small role="status">{loading ? "正在搜索…" : error || "没有匹配结果，请换个关键词"}</small> : null}{results.length ? <div className="beike-puck-preview-target__results">{results.map(item => <button type="button" key={item.id} onClick={() => select(item)}>{item.image_format || item.image || item.logo ? <img src={item.image_format || item.image || item.logo} alt="" /> : null}<span>{item.name || `#${item.id}`}</span>{item.status === false || item.status === 0 ? <small>未启用</small> : null}</button>)}</div> : null}</div>;
};

const RevisionHistory = ({ onRestore }: { onRestore: (id: number) => Promise<void> }): React.JSX.Element => {
  const dialog = useRef<HTMLDialogElement>(null);
  const [items, setItems] = useState<BuilderRevision[]>([]);
  const [message, setMessage] = useState("");
  const [restoring, setRestoring] = useState<number | null>(null);
  const [confirming, setConfirming] = useState<number | null>(null);
  const open = async (): Promise<void> => {
    dialog.current?.showModal(); setConfirming(null); setMessage("正在加载发布版本…");
    try {
      setItems(await builderApi.revisions());
      setMessage("");
    } catch { setMessage("发布版本加载失败，请稍后重试。"); }
  };
  const restore = async (revision: BuilderRevision): Promise<void> => {
    setRestoring(revision.id);
    try {
      await onRestore(revision.id);
    } catch { setMessage("该版本恢复失败，请稍后重试。"); setRestoring(null); }
  };
  return <><button className="beike-puck-header-action beike-puck-revisions" type="button" onClick={() => void open()}>查看发布记录</button><dialog ref={dialog} className="beike-puck-revision-dialog"><div className="beike-puck-revision-dialog__head"><div><h2>发布记录</h2><p>发布时同时保存页面、页头、页尾和菜单，系统保留最近 25 个版本。</p></div><button type="button" onClick={() => dialog.current?.close()} aria-label="关闭发布记录">×</button></div><div className="beike-puck-revision-dialog__body">{message ? <p className="beike-puck-revision-dialog__empty">{message}</p> : null}{!message && !items.length ? <p className="beike-puck-revision-dialog__empty">暂未发布过版本。</p> : null}{items.slice().reverse().map(item => <div className={`beike-puck-revision-dialog__row ${confirming === item.id ? "is-confirming" : ""}`} key={item.id}><span><strong>{formatAppDateTime(item.created_at)}</strong><small>{confirming === item.id ? "恢复会替换当前草稿，线上版本不会改变。" : `${item.module_count} 个模块 · 完整店铺外观`}</small></span>{confirming === item.id ? <div className="beike-puck-revision-dialog__confirm"><button type="button" disabled={restoring !== null} onClick={() => setConfirming(null)}>取消</button><button className="is-danger" type="button" disabled={restoring !== null} onClick={() => void restore(item)}>{restoring === item.id ? "正在恢复…" : "确认恢复"}</button></div> : <button type="button" disabled={restoring !== null} onClick={() => setConfirming(item.id)}>恢复到草稿</button>}</div>)}</div></dialog></>;
};

/** Blocks accidental last-write-wins when two administrators edit together. */
const BuilderConflictDialog = ({ message, busy, onDownload, onReload, onOverwrite }: { message: string; busy: boolean; onDownload: () => void; onReload: () => void; onOverwrite: () => void }): React.JSX.Element => {
  const dialog = useRef<HTMLDialogElement>(null);
  useEffect(() => {
    if (!dialog.current?.open) dialog.current?.showModal();
  }, []);
  return <dialog ref={dialog} className="beike-puck-conflict-dialog" onCancel={event => event.preventDefault()} aria-labelledby="builder-conflict-title" aria-describedby="builder-conflict-description">
    <div className="beike-puck-conflict-dialog__icon" aria-hidden="true">!</div>
    <div><h2 id="builder-conflict-title">草稿存在更新冲突</h2><p id="builder-conflict-description">{message} 你可以先下载当前窗口中的修改，再载入服务器草稿；只有确认要替换他人的修改时才选择覆盖。</p></div>
    <div className="beike-puck-conflict-dialog__actions"><button type="button" disabled={busy} onClick={onDownload}>下载我的修改</button><button type="button" disabled={busy} onClick={onReload}>载入最新草稿</button><button className="is-danger" type="button" disabled={busy} onClick={onOverwrite}>{busy ? "正在覆盖…" : "用当前内容覆盖"}</button></div>
  </dialog>;
};

/** A deliberate, informative publish checkpoint replaces the browser confirm. */
const PublishDialog = ({ data, onCancel, onConfirm }: { data: PuckData; onCancel: () => void; onConfirm: () => void }): React.JSX.Element => {
  const dialog = useRef<HTMLDialogElement>(null);
  useEffect(() => {
    if (!dialog.current?.open) dialog.current?.showModal();
    return () => dialog.current?.close();
  }, []);
  const visibleModules = (Array.isArray(data?.content) ? data.content : []).filter(item => item.props._builder?.visible !== false);
  const affectedPages = Array.from(new Set(visibleModules.flatMap(item => {
    const placement = typeof item.props.placement === "string" ? item.props.placement : "home";
    if (placement === "all") return Object.keys(previewPageLabels) as PreviewPage[];
    if (placement === "both") return ["home", "product"] as PreviewPage[];
    return previewPageFor(placement) ? [placement as PreviewPage] : ["home"];
  })));
  return <dialog ref={dialog} className="beike-puck-publish-dialog" onCancel={event => { event.preventDefault(); onCancel(); }} aria-labelledby="builder-publish-title" aria-describedby="builder-publish-description">
    <div className="beike-puck-publish-dialog__mark" aria-hidden="true">L</div>
    <div className="beike-puck-publish-dialog__copy"><p className="beike-puck-publish-dialog__eyebrow">LONGOS · PUBLISH</p><h2 id="builder-publish-title">把当前设计发布到线上？</h2><p id="builder-publish-description">顾客将立即看到当前草稿中的页面、页头、页尾和导航设置。</p></div>
    <dl><div><dt>可见模块</dt><dd>{visibleModules.length}</dd></div><div><dt>涉及页面</dt><dd>{affectedPages.length || 1}</dd></div><div><dt>发布保护</dt><dd>完整校验</dd></div></dl>
    <p className="beike-puck-publish-dialog__note">发布前会先保存未完成的修改，并验证线上数据写入；版本记录失败不会再阻断发布。</p>
    <div className="beike-puck-publish-dialog__actions"><button type="button" onClick={onCancel}>继续编辑</button><button className="is-primary" type="button" onClick={onConfirm}>确认发布</button></div>
  </dialog>;
};

const BuilderApp = (): React.JSX.Element => {
  const timer = useRef<number | null>(null);
  const recoveryTimer = useRef<number | null>(null);
  const previewTimer = useRef<number | null>(null);
  const previewAbort = useRef<AbortController | null>(null);
  const previewSequence = useRef(0);
  const previewNeedsReload = useRef(false);
  const writes = useRef(new BuilderWriteQueue());
  const editVersion = useRef(browserRecovery ? 1 : 0);
  const savedVersion = useRef(0);
  const draftVersion = useRef(Math.max(0, bootstrap.draftVersion || 0));
  const latestChrome = useRef(initialChrome);
  const replacing = useRef(false);
  const conflictActive = useRef(false);
  const navigationAllowed = useRef(false);
  const [replacementBusy, setReplacementBusy] = useState(false);
  const lastData = useRef(JSON.stringify(initialData));
  const currentPage = useRef<PreviewPage>(previewPageFor((initialData as PuckData).root?.props?.pageType) || "home");
  const importFile = useRef<HTMLInputElement>(null);
  const latest = useRef<PuckData>(initialData as PuckData);
  const [saveState, setSaveState] = useState<SaveState>(navigator.onLine ? (browserRecovery ? "unsaved" : "saved") : "offline");
  const [message, setMessage] = useState(browserRecovery
    ? (navigator.onLine ? "已恢复本窗口未保存的修改，正在重新保存" : "已恢复本窗口未保存的修改；联网后自动重试")
    : navigator.onLine ? (bootstrap.hasUnpublishedChanges ? "草稿已保存，尚未发布" : "线上版本已同步") : "当前离线；修改会保留在此窗口，联网后自动重试");
  const [hasUnpublishedChanges, setHasUnpublishedChanges] = useState(browserRecovery ? true : bootstrap.hasUnpublishedChanges);
  const [conflictMessage, setConflictMessage] = useState<string | null>(null);
  const [publishOpen, setPublishOpen] = useState(false);
  const publishCandidate = useRef<PuckData>(initialData as PuckData);
  const [chrome, setChrome] = useState<BuilderChrome>(initialChrome);
  const [chromeTab, setChromeTab] = useState<"header" | "footer">("header");
  const [previewPage, setPreviewPage] = useState<PreviewPage>(currentPage.current);
  const [previewTarget, setPreviewTarget] = useState<PreviewTarget | null>(null);
  const [structureContext, setStructureContext] = useState<StructureContext>("page");
  const [previewRevision, setPreviewRevision] = useState(0);
  const [previewLoading, setPreviewLoading] = useState(true);
  const [previewPatch, setPreviewPatch] = useState<LivePreviewPatch | null>(null);
  const [livePreviewMessage, setLivePreviewMessage] = useState("");
  const preserveLocalRecovery = useCallback((data: PuckData = latest.current, chromeState: BuilderChrome = latestChrome.current): void => {
    writeBuilderRecovery(recoveryStorage, recoveryKey, draftVersion.current, data, chromeState);
  }, []);
  const scheduleLocalRecovery = useCallback((): void => {
    if (recoveryTimer.current) window.clearTimeout(recoveryTimer.current);
    recoveryTimer.current = window.setTimeout(() => {
      recoveryTimer.current = null;
      preserveLocalRecovery(latest.current, latestChrome.current);
    }, 220);
  }, [preserveLocalRecovery]);
  const markConflict = (error: BuilderConflictError): void => {
    if (error.serverVersion > 0) draftVersion.current = error.serverVersion;
    conflictActive.current = true;
    setConflictMessage(error.message);
    setSaveState("conflict");
    setMessage("检测到草稿冲突，当前修改尚未覆盖服务器版本");
  };
  const replaceDraft = async (operation: () => Promise<BuilderMutationResult>): Promise<void> => {
    if (replacing.current) return;
    replacing.current = true;
    setReplacementBusy(true);
    if (timer.current) window.clearTimeout(timer.current);
    setSaveState("saving");
    try {
      const result = await writes.current.run(operation);
      draftVersion.current = result.draftVersion;
      navigationAllowed.current = true;
      window.location.reload();
    } catch (error) {
      replacing.current = false;
      setReplacementBusy(false);
      if (error instanceof BuilderConflictError) { markConflict(error); return; }
      setSaveState("error");
      setMessage(error instanceof Error ? error.message : "草稿替换失败，请重试");
      throw error;
    }
  };
  const importBackup = async (file?: File): Promise<void> => {
    if (!file) return;
    if (file.size > 2 * 1024 * 1024) { setSaveState("error"); setMessage("备份文件不能超过 2MB"); return; }
    setSaveState("saving"); setMessage("正在导入装修备份…");
    try {
      const payload: unknown = JSON.parse(await file.text());
      await replaceDraft(() => builderApi.importBackup(payload, draftVersion.current));
    } catch (error) { setSaveState("error"); setMessage(error instanceof Error ? error.message : "备份导入失败"); }
    finally { if (importFile.current) importFile.current.value = ""; }
  };

  const persist = useCallback(async (data: PuckData, publish = false, chromeToSave: BuilderChrome = latestChrome.current, force = false): Promise<boolean> => {
    if (replacing.current) return false;
    if (timer.current) window.clearTimeout(timer.current);
    if (!navigator.onLine) {
      setSaveState("offline");
      setMessage("当前离线；修改会保留在此窗口，联网后自动重试");
      return false;
    }
    const version = editVersion.current;
    const snapshot = structuredClone({ data, chrome: chromeToSave });
    setSaveState(publish ? "publishing" : "saving"); setMessage(publish ? "正在校验并发布完整店铺外观…" : "正在保存草稿…");
    try {
      const result = await writes.current.run(() => builderApi.save(snapshot.data, snapshot.chrome, draftVersion.current, publish, force));
      draftVersion.current = result.draftVersion;
      conflictActive.current = false;
      setConflictMessage(null);
      savedVersion.current = version;
      if (version !== editVersion.current || replacing.current) {
        preserveLocalRecovery(latest.current, latestChrome.current);
        return true;
      }
      if (recoveryTimer.current) window.clearTimeout(recoveryTimer.current);
      recoveryTimer.current = null;
      clearBuilderRecovery(recoveryStorage, recoveryKey);
      setHasUnpublishedChanges(result.hasUnpublishedChanges);
      setSaveState("saved");
      setMessage(publish
        ? !result.cacheRefreshed ? "已发布；服务器缓存将在下一次刷新时同步" : !result.revisionCreated ? "已发布到线上；本次历史版本未能创建" : "已发布到线上并创建完整版本"
        : result.hasUnpublishedChanges ? "草稿已保存，尚未发布" : "线上版本已同步");
      if (publish || previewNeedsReload.current) {
        previewNeedsReload.current = false;
        setPreviewRevision(value => value + 1);
      }
      return true;
    } catch (error) {
      if (version !== editVersion.current || replacing.current) return false;
      if (error instanceof BuilderConflictError) { markConflict(error); return false; }
      setSaveState("error"); setMessage(error instanceof Error ? error.message : "草稿保存失败，请点击保存草稿重试");
      return false;
    }
  }, [preserveLocalRecovery]);
  const queueSave = (data: PuckData): void => {
    if (replacing.current) return;
    const serialized = JSON.stringify(data);
    if (serialized === lastData.current) return;
    const previewChange = classifyPreviewChange(latest.current, data);
    lastData.current = serialized;
    editVersion.current += 1;
    latest.current = data;
    scheduleLocalRecovery();
    setHasUnpublishedChanges(true);
    const selectedPage = previewPageFor(data.root?.props?.pageType);
    if (selectedPage && selectedPage !== currentPage.current) { currentPage.current = selectedPage; setPreviewPage(selectedPage); setPreviewTarget(null); setStructureContext("page"); }
    if (timer.current) window.clearTimeout(timer.current);
    if (previewTimer.current) window.clearTimeout(previewTimer.current);
    previewAbort.current?.abort();
    if (previewChange.kind === "module") {
      if (!previewMatchesPlacement(previewChange.item.props.placement, previewPage)) {
        setLivePreviewMessage("模块已更新；切换到对应页面即可查看");
      } else {
      const requestSequence = ++previewSequence.current;
      setLivePreviewMessage("正在实时更新当前模块…");
      previewTimer.current = window.setTimeout(() => {
        const controller = new AbortController();
        previewAbort.current = controller;
        void builderApi.previewModule(previewChange.item, previewPage, controller.signal).then(result => {
          if (requestSequence !== previewSequence.current) return;
          setPreviewPatch({ sequence: requestSequence, moduleId: result.moduleId, html: result.html });
          setLivePreviewMessage("正在应用真实主题预览…");
        }).catch((error: unknown) => {
          if (error instanceof DOMException && error.name === "AbortError") return;
          if (requestSequence !== previewSequence.current) return;
          previewNeedsReload.current = true;
          setLivePreviewMessage("局部预览未能更新，保存后将自动刷新");
          if (savedVersion.current === editVersion.current && saveState !== "saving" && saveState !== "publishing") {
            previewNeedsReload.current = false;
            setPreviewRevision(value => value + 1);
          }
        });
      }, 180);
      }
    } else if (previewChange.kind === "reload") {
      previewSequence.current += 1;
      previewNeedsReload.current = true;
      setLivePreviewMessage("页面结构已更改，保存后将刷新完整预览");
    }
    if (conflictActive.current) { setSaveState("conflict"); setMessage("草稿冲突尚未解决；当前窗口中的修改仍已保留"); return; }
    if (!navigator.onLine) { setSaveState("offline"); setMessage("当前离线；修改会保留在此窗口，联网后自动重试"); return; }
    setSaveState("unsaved"); setMessage("存在未保存的更改");
    timer.current = window.setTimeout(() => { void persist(latest.current); }, 900);
  };
  const invalidatePreviewRequest = (): void => {
    if (previewTimer.current) window.clearTimeout(previewTimer.current);
    previewAbort.current?.abort();
    previewAbort.current = null;
    previewSequence.current += 1;
  };
  useEffect(() => () => { if (timer.current) window.clearTimeout(timer.current); if (recoveryTimer.current) window.clearTimeout(recoveryTimer.current); if (previewTimer.current) window.clearTimeout(previewTimer.current); previewAbort.current?.abort(); }, []);
  useEffect(() => { setPreviewLoading(true); }, [previewPage, previewRevision]);
  useEffect(() => {
    const warn = (event: BeforeUnloadEvent): void => {
      if (navigationAllowed.current || (editVersion.current === savedVersion.current && saveState !== "saving" && saveState !== "publishing")) return;
      preserveLocalRecovery(latest.current, latestChrome.current);
      event.preventDefault(); event.returnValue = "";
    };
    window.addEventListener("beforeunload", warn);
    return () => window.removeEventListener("beforeunload", warn);
  }, [preserveLocalRecovery, saveState]);
  useEffect(() => {
    const offline = (): void => {
      if (timer.current) window.clearTimeout(timer.current);
      preserveLocalRecovery(latest.current, latestChrome.current);
      setSaveState("offline");
      setMessage("当前离线；修改会保留在此窗口，联网后自动重试");
    };
    const online = (): void => {
      if (conflictActive.current) return;
      if (editVersion.current !== savedVersion.current) void persist(latest.current);
      else {
        setSaveState("saved");
        setMessage(hasUnpublishedChanges ? "草稿已保存，尚未发布" : "网络已恢复，线上版本已同步");
      }
    };
    window.addEventListener("offline", offline);
    window.addEventListener("online", online);
    return () => { window.removeEventListener("offline", offline); window.removeEventListener("online", online); };
  }, [hasUnpublishedChanges, persist, preserveLocalRecovery]);
  useEffect(() => {
    if (!browserRecovery || !navigator.onLine || conflictActive.current) return;
    timer.current = window.setTimeout(() => { void persist(latest.current, false, latestChrome.current); }, 250);
    return () => { if (timer.current) window.clearTimeout(timer.current); };
  }, [persist]);
  const stageChrome = useCallback((next: BuilderChrome): void => {
    if (JSON.stringify(next) === JSON.stringify(latestChrome.current)) return;
    latestChrome.current = structuredClone(next);
    editVersion.current += 1;
    previewNeedsReload.current = true;
    setLivePreviewMessage("页头或页尾已更改，保存后将刷新完整预览");
    setHasUnpublishedChanges(true);
    setChrome(structuredClone(next));
    scheduleLocalRecovery();
    if (timer.current) window.clearTimeout(timer.current);
    if (conflictActive.current) {
      setSaveState("conflict");
      setMessage("草稿冲突尚未解决；页头与页尾修改已保留在当前窗口");
      return;
    }
    if (!navigator.onLine) {
      setSaveState("offline");
      setMessage("当前离线；页头与页尾修改已保留，联网后自动重试");
      return;
    }
    setSaveState("unsaved");
    setMessage("页头或页尾存在未保存的更改");
    timer.current = window.setTimeout(() => { void persist(latest.current, false, latestChrome.current); }, 1100);
  }, [persist, scheduleLocalRecovery]);
  const saveChrome = useCallback(async (next: BuilderChrome): Promise<boolean> => {
    stageChrome(next);
    return conflictActive.current ? false : persist(latest.current, false, latestChrome.current);
  }, [persist, stageChrome]);
  const saveDraft = (): void => { if (saveState !== "saving" && saveState !== "publishing" && !conflictActive.current) void persist(latest.current); };
  const requestPublish = (data?: PuckData): void => {
    if (conflictActive.current || saveState === "saving" || saveState === "publishing" || saveState === "offline") return;
    publishCandidate.current = data && Array.isArray(data.content) ? data : latest.current;
    setPublishOpen(true);
  };
  const confirmPublish = (): void => {
    setPublishOpen(false);
    void persist(publishCandidate.current, true);
  };
  const downloadLocalDraft = (): void => {
    const payload = { format: "longos-store-builder", version: 5, exported_at: new Date().toISOString(), source: "browser-conflict-recovery", puck: latest.current, header: latestChrome.current.header, footer: latestChrome.current.footer, menu: latestChrome.current.menu };
    const url = URL.createObjectURL(new Blob([JSON.stringify(payload, null, 2)], { type: "application/json" }));
    const anchor = document.createElement("a"); anchor.href = url; anchor.download = `longos-editor-recovery-${new Date().toISOString().replace(/[:.]/g, "-")}.json`; anchor.click();
    window.setTimeout(() => URL.revokeObjectURL(url), 0);
  };
  const overwriteDraft = (): void => {
    void persist(latest.current, false, latestChrome.current, true);
  };
  useEffect(() => {
    const shortcut = (event: KeyboardEvent): void => {
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === "s") {
        event.preventDefault();
        if (saveState !== "saving" && saveState !== "publishing" && !conflictActive.current) void persist(latest.current);
      }
    };
    window.addEventListener("keydown", shortcut);
    return () => window.removeEventListener("keydown", shortcut);
  }, [persist, saveState]);
  // Puck's default tabs are overridden only where the Builder needs a real
  // storefront-aware context. The standard Fields panel remains intact for
  // ordinary page modules.
  const editorPlugins = useMemo<Plugin[]>(() => [
    {
      name: "blocks",
      label: "插入",
      render: () => <ExistingComponentInsertPanel page={previewPage} onInserted={() => setStructureContext("page")} />,
    },
    {
      name: "outline",
      label: "结构",
      render: () => <PageStructurePanel page={previewPage} context={structureContext} onEditChrome={tab => { setChromeTab(tab); setStructureContext("chrome"); }} onReturnToPage={() => setStructureContext("page")} />,
    },
    {
      name: "fields",
      label: "设置",
      render: () => structureContext === "chrome"
        ? <ChromeSettings chrome={chrome} locale={bootstrap.locale} initialTab={chromeTab} navigationUrl={bootstrap.navigationEditorUrl} policySettingsUrl={bootstrap.policySettingsUrl} onTabChange={setChromeTab} onClose={() => setStructureContext("page")} onDraftChange={stageChrome} onSave={saveChrome} />
        : <ModuleInspectorPanel />,
    },
    {
      name: "theme",
      label: "主题",
      render: () => <ThemeSettingsPanel />,
    },
  ], [chrome, chromeTab, previewPage, saveChrome, stageChrome, structureContext]);
  const defaultPreviewUrl = { home: bootstrap.storefrontPreviewUrl, product: bootstrap.productPreviewUrl, category: bootstrap.categoryPreviewUrl, page: bootstrap.pagePreviewUrl }[previewPage];
  const previewUrl = (() => {
    if (!previewTarget || previewPage === "home") return defaultPreviewUrl;
    if (previewTarget.url) {
      try {
        const base = new URL(bootstrap.storefrontBaseUrl, window.location.href);
        const candidate = new URL(previewTarget.url, base);
        if (candidate.origin === base.origin) return candidate.href;
      } catch {
        // Invalid and cross-origin target URLs fall back to the local route.
      }
    }
    const path = { product: "products", category: "categories", page: "pages" }[previewPage];
    return `${bootstrap.storefrontBaseUrl.replace(/\/+$/, "")}/${path}/${encodeURIComponent(previewTarget.id)}`;
  })();
  const previewStatus = previewLoading ? "正在加载安全草稿预览…" : livePreviewMessage || (saveState === "saving" || saveState === "publishing" || saveState === "unsaved" ? "修改正在同步，预览会自动更新。" : message);
  return <PreviewContext.Provider value={{ src: previewUrl, revision: previewRevision, status: previewStatus, patch: previewPatch, swiperStyleUrl: bootstrap.swiperStyleUrl, swiperScriptUrl: bootstrap.swiperScriptUrl, chromeTab: structureContext === "chrome" ? chromeTab : null, onLoad: () => { setPreviewLoading(false); setLivePreviewMessage(""); }, onPreviewError: error => { setPreviewLoading(false); setLivePreviewMessage(error); }, onPatchApplied: () => setLivePreviewMessage("实时预览已更新"), onPatchFailure: () => {
    previewNeedsReload.current = true;
    setLivePreviewMessage("局部预览未能应用，保存后将自动刷新");
    if (savedVersion.current === editVersion.current && saveState !== "saving" && saveState !== "publishing") {
      previewNeedsReload.current = false;
      setPreviewRevision(value => value + 1);
    }
  }, onBlockedAction: () => setLivePreviewMessage("编辑预览已阻止跳转或交易操作；请在新窗口测试真实交互"), onSelectChrome: tab => { setChromeTab(tab); setStructureContext("chrome"); }, onSelectPageModule: () => setStructureContext("page") }}>
    <div style={{ height: "100%" }} inert={replacementBusy || Boolean(conflictMessage) || publishOpen} aria-busy={replacementBusy || saveState === "publishing"}>
    <Puck config={config} data={initialData} dictionary={puckZhCnDictionary} viewports={viewports} iframe={{ enabled: false }} plugins={editorPlugins} overrides={editorOverrides} headerTitle="LONGOS 店铺编辑器" onChange={(data) => queueSave(data as PuckData)} onPublish={(data) => requestPublish(data as PuckData)} renderHeaderActions={() => <BuilderHeaderControls page={previewPage} target={previewTarget} context={structureContext} saveState={saveState} saveMessage={message} hasUnpublishedChanges={hasUnpublishedChanges} onPageChange={page => { invalidatePreviewRequest(); setPreviewPage(page); setPreviewTarget(null); setStructureContext("page"); }} onTargetChange={target => { invalidatePreviewRequest(); setPreviewTarget(target); setPreviewRevision(value => value + 1); }} onShowChrome={() => setStructureContext(current => current === "chrome" ? "page" : "chrome")} onSave={saveDraft} onPublish={() => requestPublish()} onRefresh={() => { invalidatePreviewRequest(); setPreviewRevision(value => value + 1); }} previewUrl={previewUrl} onOpenRevisions={<RevisionHistory onRestore={id => replaceDraft(() => builderApi.restoreRevision(id, draftVersion.current))} />} onExport={bootstrap.exportUrl} onImport={() => importFile.current?.click()} />} />
    </div>
    <input ref={importFile} type="file" accept="application/json,.json" hidden onChange={event => { void importBackup(event.target.files?.[0]); }} />
    {publishOpen ? <PublishDialog data={publishCandidate.current} onCancel={() => setPublishOpen(false)} onConfirm={confirmPublish} /> : null}
    {conflictMessage ? <BuilderConflictDialog message={conflictMessage} busy={replacementBusy || saveState === "saving" || saveState === "publishing"} onDownload={downloadLocalDraft} onReload={() => { navigationAllowed.current = true; window.location.reload(); }} onOverwrite={overwriteDraft} /> : null}
  </PreviewContext.Provider>;
};

createRoot(mount).render(<BuilderErrorBoundary><BuilderApp /></BuilderErrorBoundary>);
