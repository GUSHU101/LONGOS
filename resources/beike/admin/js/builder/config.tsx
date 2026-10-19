import type { Config } from "@puckeditor/core";
import { useEffect, useId, useRef, useState } from "react";
import { LongosCommerceAdapter, type CommerceAdapter, type CommerceResource, type CommerceSearchItem } from "./commerce";
import { useCommerceSearch } from "./use-commerce-search";
import { localizedFields } from "./localized-fields";
import { languageMap, readLocalized, readMediaUrl, writeLocalized } from "./localized-value";
import { zhBuilderLabel, zhComponentLabel } from "./i18n";
import { nativeComponentFields, nativeFieldVisibility, nativeSharedFields } from "./native-component-fields";
import { mediaSelectionFromMessage, normalizeMediaUrl } from "./media-library";
import type { AppEmbedDefinition, CatalogDefinition, BuilderModuleProps, BuilderRootProps, EditorLinkType, GeneratedRegistryComponent, ShopifyUnit } from "./types";
import { datetimeLocalValue } from "./timezone";

const localized = (value: unknown): string => {
  if (typeof value === "string") return value;
  if (value && typeof value === "object" && !Array.isArray(value)) {
    const entries = Object.values(value as Record<string, unknown>);
    return typeof entries[0] === "string" ? entries[0] : "";
  }
  return "";
};

const imageUrl = (value: unknown): string => {
  return readMediaUrl(value, editorLocale);
};

const imageFromProps = (props: BuilderModuleProps): string => {
  const direct = imageUrl(props.image) || imageUrl(props.desktop_image);
  if (direct) return direct;
  const images = Array.isArray(props.images) ? props.images : [];
  for (const image of images) {
    const found = imageUrl(image) || (image && typeof image === "object" && !Array.isArray(image) ? imageUrl((image as Record<string, unknown>).image) : "");
    if (found) return found;
  }
  return "";
};

const selectOptions = (options: unknown): Array<{ label: string; value: string }> => {
  if (!Array.isArray(options)) return [];
  const normalized: Array<{ label: string; value: string }> = [];
  for (const option of options) {
    if (typeof option === "string" || typeof option === "number") {
      normalized.push({ label: String(option), value: String(option) });
      continue;
    }
    if (!option || typeof option !== "object" || Array.isArray(option)) continue;
    const record = option as Record<string, unknown>;
    const value = record.value ?? record.id;
    const label = record.label ?? record.name ?? value;
    if ((typeof value !== "string" && typeof value !== "number") || (typeof label !== "string" && typeof label !== "number")) continue;
    normalized.push({ label: String(label), value: String(value) });
  }
  return normalized.slice(0, 100);
};

interface MediaFieldProps {
  field: { label?: string };
  value: unknown;
  onChange: (value: string) => void;
  readOnly?: boolean;
}

/** Permission-aware Puck image field backed by the existing Beike media manager. */
export const MediaField = ({ field, value, onChange, readOnly }: MediaFieldProps): React.JSX.Element => {
  const dialog = useRef<HTMLDialogElement>(null);
  const frame = useRef<HTMLIFrameElement>(null);
  const [open, setOpen] = useState(false);
  const [selectionError, setSelectionError] = useState("");
  const current = imageUrl(value);
  useEffect(() => {
    const receive = (event: MessageEvent): void => {
      if (event.origin !== window.location.origin || event.source !== frame.current?.contentWindow) return;
      const file = mediaSelectionFromMessage(event.data, "image");
      if (!file) {
        setSelectionError("请选择一张有效图片");
        return;
      }
      setSelectionError("");
      onChange(file.path || file.origin_url || file.url); dialog.current?.close(); setOpen(false);
    };
    window.addEventListener("message", receive);
    return () => window.removeEventListener("message", receive);
  }, [onChange]);
  const show = (): void => { if (readOnly) return; setSelectionError(""); dialog.current?.showModal(); setOpen(true); };
  const close = (): void => { dialog.current?.close(); setOpen(false); };
  return <div className="beike-puck-media-field">
    <div className="beike-puck-media-field__preview">{current ? <img src={current} alt="已选择的图片" /> : "尚未选择图片"}</div>
    <div className="beike-puck-media-field__actions"><button type="button" onClick={show} disabled={readOnly}>选择图片</button>{current ? <button type="button" onClick={() => onChange("")} disabled={readOnly}>清除</button> : null}</div>
    <dialog ref={dialog} className="beike-puck-media-dialog" onClose={() => setOpen(false)} aria-label={field.label || "选择图片"}>
      <div className="beike-puck-media-dialog__head"><strong>{field.label || "选择图片"}</strong><button type="button" onClick={close} aria-label="关闭媒体库">×</button></div>
      {selectionError ? <p role="alert" className="beike-puck-media-field__error">{selectionError}</p> : null}
      {open && <iframe ref={frame} title="LONGOS 图片媒体库" src={mediaLibraryUrl} />}
    </dialog>
  </div>;
};

let mediaLibraryUrl = "";
let editorLocale = "zh_cn";
let videoMediaLibraryUrl = "";
let linkTypeOptions: EditorLinkType[] = [
  { value: "custom", label: "自定义链接" }, { value: "product", label: "商品" }, { value: "category", label: "商品分类" },
  { value: "page", label: "内容页" }, { value: "brand", label: "品牌" }, { value: "static", label: "站内页面" },
];

interface ColorFieldProps { field: { label?: string; allowEmpty?: boolean; emptyLabel?: string }; value: unknown; onChange: (value: string) => void; readOnly?: boolean; }
const hexColor = (value: unknown, fallback: string): string => typeof value === "string" && /^#[0-9a-f]{6}$/i.test(value.trim()) ? value.trim().toUpperCase() : fallback;
const ColorField = ({ field, value, onChange, readOnly }: ColorFieldProps): React.JSX.Element => {
  const current = typeof value === "string" && /^#[0-9a-f]{6}$/i.test(value.trim()) ? value.trim().toUpperCase() : "";
  const [draft, setDraft] = useState(current);
  useEffect(() => setDraft(current), [current]);
  const valid = /^#[0-9a-f]{6}$/i.test(draft.trim());
  const commit = (): void => {
    const next = draft.trim().toUpperCase();
    if (valid) onChange(next);
    else if (!next && field.allowEmpty) onChange("");
    else setDraft(current || "#000000");
  };
  const choose = (next: string): void => { const normalized = next.toUpperCase(); setDraft(normalized); onChange(normalized); };
  return <div className="beike-puck-color-field">
    <input type="color" aria-label={`${field.label || "颜色"}取色器`} value={current || "#000000"} disabled={readOnly} onChange={event => choose(event.target.value)} />
    <input className="beike-puck-color-field__hex" aria-label={`${field.label || "颜色"}十六进制值`} aria-invalid={Boolean(draft && !valid)} value={draft} placeholder={field.allowEmpty ? field.emptyLabel || "跟随默认颜色" : "#000000"} maxLength={7} disabled={readOnly} onChange={event => setDraft(event.target.value)} onBlur={commit} onKeyDown={event => { if (event.key === "Enter") event.currentTarget.blur(); }} />
    {field.allowEmpty && current ? <button type="button" disabled={readOnly} onClick={() => { setDraft(""); onChange(""); }}>重置</button> : null}
    {!draft && field.allowEmpty ? <small>{field.emptyLabel || "跟随默认颜色"}</small> : null}
  </div>;
};
const gradientColors = (value: unknown): [string, string] => {
  const matches = typeof value === "string" ? value.match(/#[0-9a-f]{6}/ig) || [] : [];
  return [hexColor(matches[0], "#000000"), hexColor(matches[1], "#C1FBD4")];
};
const GradientField = ({ value, onChange, readOnly }: ColorFieldProps): React.JSX.Element => {
  const [first, second] = gradientColors(value);
  const update = (one: string, two: string): void => onChange(`linear-gradient(135deg, ${one.toUpperCase()}, ${two.toUpperCase()})`);
  return <div className="beike-puck-color-field beike-puck-gradient-field"><input type="color" value={first} disabled={readOnly} onChange={event => update(event.target.value, second)} /><input type="color" value={second} disabled={readOnly} onChange={event => update(first, event.target.value)} /><output style={{ background: `linear-gradient(135deg, ${first}, ${second})` }}>渐变色</output></div>;
};
interface RangeFieldProps { field: { label?: string; min?: number; max?: number; step?: number; unit?: string }; value: unknown; onChange: (value: number) => void; readOnly?: boolean; }
/** A bounded slider keeps Shopify `range` semantics instead of degrading it to free text. */
const RangeField = ({ field, value, onChange, readOnly }: RangeFieldProps): React.JSX.Element => {
  const min = Number.isFinite(field.min) ? Number(field.min) : 0;
  const max = Number.isFinite(field.max) && Number(field.max) > min ? Number(field.max) : Math.max(min + 100, 100);
  const step = Number.isFinite(field.step) && Number(field.step) > 0 ? Number(field.step) : 1;
  const current = typeof value === "number" && Number.isFinite(value) ? Math.min(max, Math.max(min, value)) : min;
  const change = (raw: string): void => { const next = Number(raw); if (Number.isFinite(next)) onChange(Math.min(max, Math.max(min, next))); };
  return <div className="beike-puck-range-field">
    <input type="range" min={min} max={max} step={step} value={current} disabled={readOnly} onChange={event => change(event.target.value)} aria-label={field.label || "数值范围"} />
    <label><input type="number" min={min} max={max} step={step} value={current} disabled={readOnly} onChange={event => change(event.target.value)} aria-label={`${field.label || "数值范围"}数值`} /><output>{field.unit || ""}</output></label>
  </div>;
};
const ColorSchemeField = ({ value, onChange, readOnly }: ColorFieldProps): React.JSX.Element => {
  const selected = ["scheme-1", "scheme-2", "scheme-3"].includes(String(value)) ? String(value) : "scheme-1";
  return <select className="beike-puck-color-scheme" value={selected} disabled={readOnly} onChange={event => onChange(event.target.value)} aria-label="配色方案"><option value="scheme-1">深色文字框</option><option value="scheme-2">浅色文字框</option><option value="scheme-3">强调色文字框</option></select>;
};
const NativeRecoveryField = (): React.JSX.Element => <div className="beike-puck-native-recovery"><p>该迁移字段已保留，但尚未具备新版编辑器所需的专用控件。请在“高级配置”中检查其值。</p></div>;

interface RichTextFieldProps { field: { label?: string }; value: unknown; onChange: (value: unknown) => void; readOnly?: boolean; }
const safeEditorRichText = (value: string): string => {
  const template = document.createElement("template");
  template.innerHTML = value.slice(0, 12000);
  const allowed = new Set(["A", "B", "BLOCKQUOTE", "BR", "EM", "H2", "H3", "I", "LI", "OL", "P", "STRONG", "UL"]);
  for (const node of Array.from(template.content.querySelectorAll("*"))) {
    if (!allowed.has(node.tagName)) {
      node.replaceWith(...Array.from(node.childNodes));
      continue;
    }
    const rawHref = node.tagName === "A" ? node.getAttribute("href") || "" : "";
    for (const attribute of Array.from(node.attributes)) node.removeAttribute(attribute.name);
    if (node.tagName === "A") {
      const href = safeBuilderUrl(rawHref);
      if (href) {
        node.setAttribute("href", href);
        node.setAttribute("rel", "noopener noreferrer");
      }
    }
  }
  return template.innerHTML;
};

/** A compact visual rich-text control; merchants never need to type HTML. */
const RichTextField = ({ field, value, onChange, readOnly }: RichTextFieldProps): React.JSX.Element => {
  const editor = useRef<HTMLDivElement>(null);
  const [language, setLanguage] = useState(editorLocale);
  const languages = languageMap(value) ? [...new Set([editorLocale, ...Object.keys(value as Record<string, unknown>)])] : [];
  const current = safeEditorRichText(readLocalized(value, language));
  useEffect(() => {
    if (editor.current && document.activeElement !== editor.current && editor.current.innerHTML !== current) editor.current.innerHTML = current;
  }, [current]);
  const commit = (): void => {
    if (!editor.current) return;
    const next = safeEditorRichText(editor.current.innerHTML);
    if (editor.current.innerHTML !== next) editor.current.innerHTML = next;
    onChange(writeLocalized(value, language, next));
  };
  const command = (name: string): void => {
    if (readOnly) return;
    editor.current?.focus();
    document.execCommand(name, false);
    commit();
  };
  return <div className="beike-puck-rich-text-field">
    <div className="beike-puck-rich-text-field__head"><label>{field.label || "富文本内容"}</label>{languages.length > 1 ? <select aria-label={`${field.label || "富文本内容"}语言`} value={language} disabled={readOnly} onChange={event => setLanguage(event.target.value)}>{languages.map(code => <option key={code} value={code}>{code}</option>)}</select> : null}</div>
    <div className="beike-puck-rich-text-field__toolbar" role="toolbar" aria-label="文本格式"><button type="button" onClick={() => command("bold")} disabled={readOnly} aria-label="粗体"><strong>B</strong></button><button type="button" onClick={() => command("italic")} disabled={readOnly} aria-label="斜体"><em>I</em></button><button type="button" onClick={() => command("insertUnorderedList")} disabled={readOnly} aria-label="无序列表">• 列表</button><button type="button" onClick={() => command("insertOrderedList")} disabled={readOnly} aria-label="有序列表">1. 列表</button><button type="button" onClick={() => command("removeFormat")} disabled={readOnly} aria-label="清除格式">清除</button></div>
    <div ref={editor} className="beike-puck-rich-text-field__editor" contentEditable={!readOnly} role="textbox" aria-multiline="true" aria-label={field.label || "富文本内容"} suppressContentEditableWarning onInput={commit} onBlur={commit} onPaste={event => { event.preventDefault(); document.execCommand("insertText", false, event.clipboardData.getData("text/plain")); commit(); }} />
    <small>支持标题、段落、粗体、斜体和列表；内容会经过前台安全过滤。</small>
  </div>;
};

interface JsonObjectFieldProps { field: { label?: string }; value: unknown; onChange: (value: string) => void; readOnly?: boolean; }
const jsonObjectText = (value: unknown): string => {
  if (typeof value === "string") return value;
  return "";
};

/**
 * Every native section keeps quick fields and the Builder preserves access to
 * uncommon section settings through a guarded JSON object field. Dedicated
 * inspector controls remain authoritative so an older JSON copy can never
 * silently undo a merchant's latest visible edit.
 */
const AdvancedJsonField = ({ field, value, onChange, readOnly }: JsonObjectFieldProps): React.JSX.Element => {
  const saved = jsonObjectText(value);
  const [draft, setDraft] = useState(saved);
  const [error, setError] = useState("");
  useEffect(() => { setDraft(saved); }, [saved]);
  const commit = (): void => {
    if (!draft.trim()) { onChange(""); setError(""); return; }
    if (draft.length > 60000) { setError("高级配置不能超过 60KB。"); return; }
    try {
      const parsed: unknown = JSON.parse(draft);
      if (!parsed || typeof parsed !== "object" || Array.isArray(parsed)) throw new Error("root");
      const formatted = JSON.stringify(parsed, null, 2);
      onChange(formatted);
      setDraft(formatted);
      setError("");
    } catch { setError("请输入有效的 JSON 对象，例如 { \"items\": [] }。"); }
  };
  return <div className="beike-puck-advanced-json">
    <textarea aria-label={field.label || "模块高级配置"} value={draft} disabled={readOnly} onChange={event => setDraft(event.target.value)} onBlur={commit} onKeyDown={event => { if ((event.ctrlKey || event.metaKey) && event.key === "Enter") { event.preventDefault(); commit(); } }} placeholder={'{\n  "items": []\n}'} />
    <small>仅用于补充没有专用控件的扩展属性；上方可视化字段始终优先。填写后按 Ctrl/⌘ + Enter 或移开焦点以应用。</small>
    {error ? <small role="alert">{error}</small> : null}
  </div>;
};

interface DateTimeFieldProps { field: { label?: string }; value: unknown; onChange: (value: string) => void; readOnly?: boolean; }
const DateTimeField = ({ field, value, onChange, readOnly }: DateTimeFieldProps): React.JSX.Element => {
  const id = useId();
  return <div className="beike-puck-datetime-field"><label htmlFor={id}>{field.label || "日期时间"}</label><input id={id} type="datetime-local" value={datetimeLocalValue(value)} disabled={readOnly} onChange={event => onChange(event.target.value)} /><small>保存时会按店铺时区转换为带时区的准确时间。</small></div>;
};

const safeBuilderUrl = (value: string): string => {
  const candidate = value.trim();
  if (!candidate) return "";
  if (/^\/(?!\/)[^\s\\]*$/.test(candidate) || /^#[^\s]*$/.test(candidate)) return candidate;
  try {
    const url = new URL(candidate);
    return ["http:", "https:", "mailto:", "tel:"].includes(url.protocol) ? url.toString() : "";
  } catch { return ""; }
};

interface LinkFieldProps { field: { label?: string }; value: unknown; onChange: (value: string) => void; readOnly?: boolean; }
/** A Shopify URL setting is a real destination, never executable browser input. */
const LinkField = ({ field, value, onChange, readOnly }: LinkFieldProps): React.JSX.Element => {
  const uniqueId = useId().replace(/[^a-z0-9_-]/gi, "");
  const saved = typeof value === "string" ? value : "";
  const [draft, setDraft] = useState(saved);
  const [error, setError] = useState("");
  useEffect(() => { setDraft(saved); }, [saved]);
  const commit = (): void => {
    const candidate = draft.trim();
    if (!candidate) { onChange(""); setError(""); return; }
    const normalized = safeBuilderUrl(candidate);
    if (!normalized) { setError("请输入相对路径、http(s)、mailto 或 tel 链接。"); return; }
    onChange(normalized); setError("");
  };
  const id = `beike-link-${uniqueId}`;
  return <div className="beike-puck-link-field">
    <label htmlFor={id}>跳转链接</label>
    <input id={id} type="url" inputMode="url" value={draft} disabled={readOnly} onChange={event => setDraft(event.target.value)} onBlur={commit} onKeyDown={event => { if (event.key === "Enter") { event.preventDefault(); commit(); } }} aria-describedby={`${id}-help${error ? ` ${id}-error` : ""}`} aria-invalid={error ? true : undefined} placeholder="/collections/new 或 https://…" />
    <small id={`${id}-help`}>支持相对路径、http(s)、mailto 和 tel 链接。</small>
    {error ? <small id={`${id}-error`} role="alert">{error}</small> : null}
    {safeBuilderUrl(saved) ? <a href={safeBuilderUrl(saved)} target="_blank" rel="noreferrer">测试链接</a> : null}
  </div>;
};

interface StructuredLinkFieldProps { field: { label?: string }; value: unknown; onChange: (value: Record<string, unknown>) => void; readOnly?: boolean; }
const structuredLink = (value: unknown): { type: string; value: string; new_window: boolean } => {
  if (typeof value === "string") return { type: "custom", value, new_window: false };
  if (!value || typeof value !== "object" || Array.isArray(value)) return { type: "custom", value: "", new_window: false };
  const record = value as Record<string, unknown>;
  const type = linkTypeOptions.some(option => option.value === String(record.type)) ? String(record.type) : "custom";
  return { type, value: typeof record.value === "string" || typeof record.value === "number" ? String(record.value) : "", new_window: record.new_window === true };
};

interface VideoFieldProps {
  field: { label?: string };
  value: unknown;
  onChange: (value: string) => void;
  readOnly?: boolean;
}

/** The existing media manager enforces the allowed MP4 type before selection. */
const VideoField = ({ field, value, onChange, readOnly }: VideoFieldProps): React.JSX.Element => {
  const dialog = useRef<HTMLDialogElement>(null);
  const frame = useRef<HTMLIFrameElement>(null);
  const [open, setOpen] = useState(false);
  const [selectionError, setSelectionError] = useState("");
  // The local-video field deliberately does not render arbitrary remote
  // URLs. Those belong to the separately validated external-video field.
  const current = normalizeMediaUrl(value);
  useEffect(() => {
    const receive = (event: MessageEvent): void => {
      if (event.origin !== window.location.origin || event.source !== frame.current?.contentWindow) return;
      const file = mediaSelectionFromMessage(event.data, "video");
      if (!file) {
        setSelectionError("请选择一个有效 MP4 视频");
        return;
      }
      setSelectionError("");
      onChange(file.path || file.origin_url || file.url); dialog.current?.close(); setOpen(false);
    };
    window.addEventListener("message", receive);
    return () => window.removeEventListener("message", receive);
  }, [onChange]);
  const show = (): void => { if (readOnly || !videoMediaLibraryUrl) return; setSelectionError(""); dialog.current?.showModal(); setOpen(true); };
  const close = (): void => { dialog.current?.close(); setOpen(false); };
  return <div className="beike-puck-media-field beike-puck-video-field">
    <div className="beike-puck-media-field__preview">{current ? <video src={current} controls preload="metadata" aria-label="已选择的视频" /> : "尚未选择视频"}</div>
    <div className="beike-puck-media-field__actions"><button type="button" onClick={show} disabled={readOnly || !videoMediaLibraryUrl}>选择视频</button>{current ? <button type="button" onClick={() => onChange("")} disabled={readOnly}>清除</button> : null}</div>
    <dialog ref={dialog} className="beike-puck-media-dialog" onClose={() => setOpen(false)} aria-label={field.label || "选择视频"}>
      <div className="beike-puck-media-dialog__head"><strong>{field.label || "选择视频"}</strong><button type="button" onClick={close} aria-label="关闭视频媒体库">×</button></div>
      {selectionError ? <p role="alert" className="beike-puck-media-field__error">{selectionError}</p> : null}
      {open && <iframe ref={frame} title="LONGOS 视频媒体库" src={videoMediaLibraryUrl} />}
    </dialog>
  </div>;
};

const externalVideoUrl = (value: string): string => {
  try {
    const url = new URL(value);
    const host = url.hostname.toLowerCase().replace(/^www\./, "");
    return ["youtube.com", "m.youtube.com", "youtu.be", "vimeo.com", "player.vimeo.com"].includes(host) ? url.toString() : "";
  } catch { return ""; }
};

/** Shopify video_url accepts only the providers that its original schema supports. */
const ExternalVideoField = ({ field, value, onChange, readOnly }: VideoFieldProps): React.JSX.Element => {
  const uniqueId = useId().replace(/[^a-z0-9_-]/gi, "");
  const saved = typeof value === "string" ? value : "";
  const [draft, setDraft] = useState(saved);
  const [error, setError] = useState("");
  useEffect(() => { setDraft(saved); }, [saved]);
  const commit = (): void => {
    const candidate = draft.trim();
    if (!candidate) { onChange(""); setError(""); return; }
    const normalized = externalVideoUrl(candidate);
    if (!normalized) { setError("请输入有效的 YouTube 或 Vimeo 地址。"); return; }
    onChange(normalized); setError("");
  };
  const id = `beike-video-url-${uniqueId}`;
  return <div className="beike-puck-external-video-field">
    <label htmlFor={id}>YouTube 或 Vimeo 地址</label>
    <input id={id} type="url" inputMode="url" value={draft} disabled={readOnly} onChange={event => setDraft(event.target.value)} onBlur={commit} onKeyDown={event => { if (event.key === "Enter") { event.preventDefault(); commit(); } }} aria-describedby={`${id}-help${error ? ` ${id}-error` : ""}`} aria-invalid={error ? true : undefined} placeholder="https://www.youtube.com/watch?v=…" />
    <small id={`${id}-help`}>仅支持嵌入 YouTube 和 Vimeo 视频。</small>
    {error ? <small id={`${id}-error`} role="alert">{error}</small> : null}
    {externalVideoUrl(saved) ? <a href={externalVideoUrl(saved)} target="_blank" rel="noreferrer">打开已选视频</a> : null}
  </div>;
};

interface CommerceFieldProps { field: { label?: string; resourceType?: CommerceResource; multiple?: boolean; max?: number }; id?: string; value: unknown; onChange: (value: unknown) => void; readOnly?: boolean; }
type CommerceResult = CommerceSearchItem;

let commerceAdapter: CommerceAdapter | null = null;
const asSelection = (value: unknown, multiple: boolean): string[] => {
  const values = multiple ? (Array.isArray(value) ? value : []) : [value];
  return values.filter((item): item is string | number => typeof item === "string" || typeof item === "number").map(String);
};
const commerceMeta = (item: CommerceSearchItem): string => [item.sku, item.price_format, item.status === false || item.status === 0 ? "未启用" : ""].filter(Boolean).join(" · ");
const commerceImage = (item: CommerceSearchItem): string => item.image_format || item.image || item.logo || "";
const CommerceField = ({ field, id, value, onChange, readOnly }: CommerceFieldProps): React.JSX.Element => {
  const uniqueId = useId().replace(/[^a-z0-9_-]/gi, "");
  const multiple = field.multiple === true;
  const resource = field.resourceType || "product";
  const [query, setQuery] = useState("");
  const { results, loading, error, empty } = useCommerceSearch(commerceAdapter, resource, query);
  const [knownItems, setKnownItems] = useState<Record<string, CommerceResult>>({});
  const selected = asSelection(value, multiple);
  const max = multiple ? Math.max(1, field.max || 24) : 1;
  const atLimit = selected.length >= max;
  useEffect(() => { if (results.length) setKnownItems(current => ({ ...current, ...Object.fromEntries(results.map(item => [String(item.id), item])) })); }, [results]);
  const choose = (item: CommerceResult): void => {
    const selectedId = String(item.id);
    if (selected.includes(selectedId) || (multiple && atLimit)) return;
    setKnownItems(current => ({ ...current, [selectedId]: item }));
    onChange(multiple ? [...selected, selectedId] : selectedId);
    if (!multiple) setQuery("");
  };
  const remove = (selectedId: string): void => onChange(multiple ? selected.filter(value => value !== selectedId) : "");
  const move = (index: number, direction: -1 | 1): void => {
    if (!multiple) return;
    const target = index + direction;
    if (target < 0 || target >= selected.length) return;
    const next = [...selected];
    [next[index], next[target]] = [next[target], next[index]];
    onChange(next);
  };
  const resourceLabel = zhBuilderLabel(resource, "内容");
  const selectionStatus = atLimit ? `已达到上限：${max} 个${resourceLabel}` : selected.length ? `已选择 ${selected.length} 个${resourceLabel}` : `尚未选择${resourceLabel}`;
  const selectionStatusId = `beike-commerce-${uniqueId}-${id || resource}-selection-status`.replace(/[^a-z0-9_-]/gi, "-").toLowerCase();
  return <div className="beike-puck-commerce-field">
    <input value={query} onChange={event => setQuery(event.target.value)} disabled={readOnly || atLimit || !commerceAdapter?.supports(resource)} placeholder={atLimit ? `最多选择 ${max} 个${resourceLabel}` : commerceAdapter?.supports(resource) ? `搜索${resourceLabel}` : "当前不可选择"} aria-label={`搜索${resourceLabel}`} aria-describedby={selectionStatusId} />
    <span id={selectionStatusId} className="beike-puck-commerce-field__status" aria-live="polite">{loading ? "正在搜索…" : error || (empty ? "没有匹配结果，请换个关键词" : selectionStatus)}</span>
    {selected.length ? <div className="beike-puck-commerce-field__selected" aria-label={`已选${resourceLabel}`}>{selected.map((selectedId, index) => {
      const item = knownItems[selectedId];
      const itemLabel = item?.name || `已选择 #${selectedId}`;
      return <article className="beike-puck-commerce-field__selected-item" key={selectedId}><span><strong>{itemLabel}</strong><small>{multiple ? `展示顺序 ${index + 1}` : `已选择${resourceLabel}`}</small></span><div>{multiple ? <><button type="button" disabled={readOnly || index === 0} onClick={() => move(index, -1)} aria-label={`上移${itemLabel}`}>↑</button><button type="button" disabled={readOnly || index === selected.length - 1} onClick={() => move(index, 1)} aria-label={`下移${itemLabel}`}>↓</button></> : null}<button className="is-danger" type="button" disabled={readOnly} onClick={() => remove(selectedId)} aria-label={`移除${itemLabel}`}>移除</button></div></article>;
    })}</div> : <span className="beike-puck-commerce-field__hint">输入名称后选择真实{resourceLabel}；多选内容可调整前台展示顺序。</span>}
    {results.length ? <div className="beike-puck-commerce-field__results" aria-label={`${resourceLabel}搜索结果`}>{results.map(item => {
      const isSelected = selected.includes(String(item.id));
      return <button className={`beike-puck-commerce-field__item ${isSelected ? "is-selected" : ""}`} type="button" key={item.id} disabled={readOnly || isSelected || atLimit} onClick={() => choose(item)} aria-label={isSelected ? `${item.name || `${resourceLabel} #${item.id}`}已选择` : `选择${item.name || `${resourceLabel} #${item.id}`}`}>{commerceImage(item) ? <img src={commerceImage(item)} alt="" /> : null}<span><strong>{item.name || `#${item.id}`}</strong><small>{isSelected ? "已选择" : commerceMeta(item)}</small></span></button>;
    })}</div> : null}
  </div>;
};

const supportedPaymentMethods: Array<[string, string]> = [
  ["visa", "Visa"], ["mastercard", "Mastercard"], ["amex", "American Express"], ["paypal", "PayPal"], ["apple_pay", "Apple Pay"], ["google_pay", "Google Pay"], ["klarna", "Klarna"], ["afterpay", "Afterpay"], ["unionpay", "银联"], ["jcb", "JCB"], ["discover", "Discover"], ["ideal", "iDEAL"],
];

/** Keeps the payment-trust module aligned with the server's allowed payment list. */
const PaymentMethodsField = ({ value, onChange, readOnly }: { value: unknown; onChange: (value: unknown) => void; readOnly?: boolean }): React.JSX.Element => {
  const selected = new Set(Array.isArray(value) ? value.filter((item): item is string => typeof item === "string") : []);
  const toggle = (method: string): void => {
    const next = new Set(selected);
    if (next.has(method)) next.delete(method); else next.add(method);
    onChange(supportedPaymentMethods.map(([code]) => code).filter(code => next.has(code)));
  };
  return <fieldset className="beike-puck-payment-methods"><legend>前台将展示已勾选的支付方式</legend><div>{supportedPaymentMethods.map(([code, label]) => <label key={code}><input type="checkbox" checked={selected.has(code)} disabled={readOnly} onChange={() => toggle(code)} />{label}</label>)}</div></fieldset>;
};

const linkResource = (type: string): CommerceResource | null => {
  const resources: Record<string, CommerceResource> = { product: "product", category: "category", page: "page", brand: "brand" };
  return resources[type] || null;
};

/** A typed storefront-link object backed by real, permission-aware resource search. */
export const StructuredLinkField = ({ field, value, onChange, readOnly }: StructuredLinkFieldProps): React.JSX.Element => {
  const uniqueId = useId().replace(/[^a-z0-9_-]/gi, "");
  const link = structuredLink(value);
  const resource = linkResource(link.type);
  const supportsResource = resource !== null && commerceAdapter?.supports(resource) === true;
  const [query, setQuery] = useState("");
  const [directDraft, setDirectDraft] = useState(link.value);
  const [directError, setDirectError] = useState("");
  useEffect(() => { setDirectDraft(link.value); setDirectError(""); }, [link.type, link.value]);
  const { results, loading, error, empty } = useCommerceSearch(commerceAdapter, resource, query);
  const update = (next: Partial<typeof link>): void => onChange({ ...link, ...next });
  const inputId = `beike-structured-link-${uniqueId}`;
  const choose = (item: CommerceSearchItem): void => { update({ value: String(item.id) }); setQuery(""); };
  const changeType = (type: string): void => { setQuery(""); setDirectDraft(""); setDirectError(""); update({ type, value: "" }); };
  const isDirectUrl = !resource;
  const commitDirect = (): void => {
    const candidate = directDraft.trim();
    if (!candidate) { update({ value: "" }); setDirectError(""); return; }
    const normalized = link.type === "static"
      ? (/^[a-z][a-z0-9_.-]{0,127}$/i.test(candidate) ? candidate : "")
      : link.type === "custom"
        ? safeBuilderUrl(candidate)
        : (/^[a-z0-9][a-z0-9_.:\/-]{0,255}$/i.test(candidate) ? candidate : "");
    if (!normalized) { setDirectError(link.type === "static" ? "请输入有效的站内路由名称。" : link.type === "custom" ? "不支持协议相对地址或不安全的链接协议。" : "请输入扩展链接所需的安全目标标识。"); return; }
    update({ value: normalized }); setDirectDraft(normalized); setDirectError("");
  };
  return <div className="beike-puck-structured-link">
    <div><label htmlFor={`${inputId}-type`}>链接类型</label><select id={`${inputId}-type`} value={link.type} disabled={readOnly} onChange={event => changeType(event.target.value)}>{linkTypeOptions.map(option => <option key={option.value} value={option.value}>{option.label}</option>)}</select></div>
    {isDirectUrl ? <div><label htmlFor={inputId}>目标</label><input id={inputId} value={directDraft} disabled={readOnly} onChange={event => setDirectDraft(event.target.value)} onBlur={commitDirect} onKeyDown={event => { if (event.key === "Enter") { event.preventDefault(); commitDirect(); } }} aria-invalid={directError ? true : undefined} aria-describedby={directError ? `${inputId}-error` : undefined} placeholder={link.type === "static" ? "例如 home" : link.type === "custom" ? "输入站内路径或 https:// 地址" : "输入扩展目标标识"} />{directError ? <small id={`${inputId}-error`} role="alert">{directError}</small> : null}</div> : <div className="beike-puck-structured-link__picker"><label htmlFor={inputId}>选择{zhBuilderLabel(resource || "product", "内容")}</label><div className="beike-puck-commerce-field"><input id={inputId} value={query} disabled={readOnly || !supportsResource} onChange={event => setQuery(event.target.value)} placeholder={supportsResource ? `搜索${zhBuilderLabel(resource || "product", "内容")}` : "当前不可选择"} aria-label={`搜索${zhBuilderLabel(resource || "product", "内容")}`} />{link.value ? <div className="beike-puck-commerce-field__selected"><button className="beike-puck-commerce-field__item" type="button" disabled={readOnly} onClick={() => update({ value: "" })}><span><strong>已选择 #{link.value}</strong><small>点击移除</small></span><b aria-hidden="true">×</b></button></div> : null}{loading || error || empty ? <span role="status" className="beike-puck-commerce-field__empty">{loading ? "正在搜索…" : error || "没有匹配结果，请换个关键词"}</span> : null}{results.length ? <div className="beike-puck-commerce-field__results">{results.map(item => <button className="beike-puck-commerce-field__item" type="button" key={item.id} disabled={readOnly} onClick={() => choose(item)}>{commerceImage(item) ? <img src={commerceImage(item)} alt="" /> : null}<span><strong>{item.name || `#${item.id}`}</strong>{commerceMeta(item) ? <small>{commerceMeta(item)}</small> : null}</span></button>)}</div> : null}</div></div>}
    <label className="beike-puck-structured-link__window"><input type="checkbox" checked={link.new_window} disabled={readOnly} onChange={event => update({ new_window: event.target.checked })} />在新窗口打开</label>
  </div>;
};

const resourceField = (setting: { id?: string; type: string; label?: string }): Record<string, unknown> | null => {
  const mapping: Record<string, { resourceType: CommerceResource; multiple: boolean }> = {
    product: { resourceType: "product", multiple: false }, product_list: { resourceType: "product", multiple: true },
    collection: { resourceType: "category", multiple: false }, collection_list: { resourceType: "category", multiple: true },
    page: { resourceType: "page", multiple: false },
    blog: { resourceType: "pageCategory", multiple: false },
  };
  const definition = mapping[setting.type];
  if (!definition || !commerceAdapter?.supports(definition.resourceType)) return null;
  return { type: "custom", label: setting.label || setting.id, render: CommerceField, ...definition };
};

// Shopify's main-page receives `page` from its template runtime instead of
// its schema. The builder makes that dependency explicit through the same
// permission-aware picker used by other local content-page sections.
const shopifyRuntimeFields = (code: string): Record<string, unknown> => {
  if (code === "shopify-main-page" && commerceAdapter?.supports("page")) return { page: { type: "custom", label: "LONGOS 内容页", render: CommerceField, resourceType: "page" as CommerceResource, multiple: false } };
  if (code === "shopify-main-collection-banner" && commerceAdapter?.supports("category")) return { collection: { type: "custom", label: "LONGOS 商品分类", render: CommerceField, resourceType: "category" as CommerceResource, multiple: false } };
  return {};
};

const localVideoSource = (value: unknown): string => typeof value === "string" && /^\/image\/catalog\/[a-z0-9._% -]+\.mp4$/i.test(value) ? value : "";
const externalVideoEmbed = (value: unknown, loop: boolean): string => {
  if (typeof value !== "string") return "";
  try {
    const url = new URL(value);
    const host = url.hostname.toLowerCase().replace(/^www\./, "");
    const path = url.pathname.replace(/^\/+|\/+$/g, "");
    let id = "";
    if (["youtube.com", "m.youtube.com", "youtu.be"].includes(host)) {
      id = host === "youtu.be" ? path : url.searchParams.get("v") || "";
      if (!id) id = /^(?:embed|shorts)\/([^/]+)/.exec(path)?.[1] || "";
      return /^[a-z0-9_-]{6,32}$/i.test(id) ? `https://www.youtube-nocookie.com/embed/${id}?rel=0${loop ? `&loop=1&playlist=${id}` : ""}` : "";
    }
    if (["vimeo.com", "player.vimeo.com"].includes(host)) {
      id = /(?:^|\/)video\/?([0-9]+)$|^([0-9]+)$/.exec(path)?.slice(1).find(Boolean) || "";
      return /^\d+$/.test(id) ? `https://player.vimeo.com/video/${id}${loop ? "?loop=1" : ""}` : "";
    }
  } catch { /* An unfinished field is a normal editor state. */ }
  return "";
};

const ComponentPreview = (rawProps: BuilderModuleProps): React.JSX.Element => {
  // Advanced JSON is deliberately previewed before saving as well, so the
  // editor never promises a configuration that the storefront cannot render.
  let advanced: Record<string, unknown> = {};
  if (typeof rawProps._advanced_json === "string" && rawProps._advanced_json.length <= 60000) {
    try {
      const parsed: unknown = JSON.parse(rawProps._advanced_json);
      if (parsed && typeof parsed === "object" && !Array.isArray(parsed)) advanced = parsed as Record<string, unknown>;
    } catch { /* The field shows its own validation feedback. */ }
  }
  const props: BuilderModuleProps = { ...advanced, ...rawProps, id: rawProps.id, _builder: rawProps._builder };
  const code = props._builder?.code || "";
  const items = Array.isArray(props.items) ? props.items.filter((item): item is Record<string, unknown> => Boolean(item && typeof item === "object" && !Array.isArray(item))) : [];
  const images = Array.isArray(props.images) ? props.images.filter((item): item is Record<string, unknown> => Boolean(item && typeof item === "object" && !Array.isArray(item))) : [];
  const offers = Array.isArray(props.offers) ? props.offers.filter((item): item is Record<string, unknown> => Boolean(item && typeof item === "object" && !Array.isArray(item))) : [];
  const benefits = Array.isArray(props.benefits) ? props.benefits.filter((item): item is Record<string, unknown> => Boolean(item && typeof item === "object" && !Array.isArray(item))) : [];
  const tabs = Array.isArray(props.tabs) ? props.tabs.filter((item): item is Record<string, unknown> => Boolean(item && typeof item === "object" && !Array.isArray(item))) : [];
  const selectedResources: unknown = code === "product" ? props.products : code === "brand" ? props.brands : props.items;
  const selectedResourceCount = Array.isArray(selectedResources) ? selectedResources.length : 0;
  const blocks = Array.isArray(props.blocks) ? props.blocks.filter((item): item is Record<string, unknown> => Boolean(item && typeof item === "object" && !Array.isArray(item))) : [];
  const bannerBlock = (type: string): Record<string, unknown> | undefined => blocks.find(block => block.block_type === type || block.type === type);
  const bannerHeading = bannerBlock("heading");
  const bannerText = bannerBlock("text");
  const slideshowSlide = bannerBlock("slide");
  const isShopifyTextSection = ["shopify-rich-text", "shopify-image-with-text"].includes(code);
  const isShopifyCollapsible = code === "shopify-collapsible-content";
  const isShopifyCollectionList = code === "shopify-collection-list";
  const isShopifyFeaturedBlog = code === "shopify-featured-blog";
  const isShopifyCollectionBanner = code === "shopify-main-collection-banner";
  const isShopifyMainPage = code === "shopify-main-page";
  const isShopifyPage = code === "shopify-page";
  const isShopifyMultirow = code === "shopify-multirow";
  const isShopifyMulticolumn = code === "shopify-multicolumn";
  const nativeSlide = ["img_text_slideshow", "img_text_slideshow_2"].includes(code) ? images[0] : undefined;
  const title = isShopifyMainPage ? "内容页" : isShopifyCollectionBanner ? "商品分类横幅" : localized(props.title) || localized(props.heading) || (["shopify-image-banner", "shopify-rich-text", "shopify-image-with-text"].includes(code) ? localized(bannerHeading?.heading) : "") || (code === "shopify-slideshow" ? localized(slideshowSlide?.heading) : "") || localized(nativeSlide?.title) || props._builder?.name || "未命名模块";
  const subtitle = isShopifyMainPage ? "选择此路由要展示的已启用 LONGOS 内容页。" : isShopifyCollectionBanner ? "选择此模块要介绍的已启用商品分类。" : localized(props.sub_title) || (["shopify-image-banner", "shopify-rich-text", "shopify-image-with-text"].includes(code) ? localized(bannerText?.text) : "") || (code === "shopify-slideshow" ? localized(slideshowSlide?.subheading) : "") || localized(nativeSlide?.sub_title);
  const itemTitle = (item: Record<string, unknown>): string => localized(item.title) || localized(item.heading) || localized(item.question) || localized(item.value) || "内容项";
  const heroImage = imageFromProps(props) || imageUrl(slideshowSlide?.image);
  const isHero = ["slideshow", "img_text_slideshow", "img_text_slideshow_2", "img_text_banner", "img_text_banner_multiple", "image100", "image200", "image300", "image301", "image400", "image401", "image402", "image403", "story_block", "shopify-image-banner", "shopify-image-with-text", "shopify-slideshow"].includes(code);
  const isShopifyVideo = code === "shopify-video";
  const localVideo = localVideoSource(props.video);
  const externalVideo = externalVideoEmbed(props.video_url, props.enable_video_looping === true);
  const videoPoster = imageUrl(props.cover_image);
  return <section className="beike-puck-section" data-module-id={props.id}>
    <p className="beike-puck-section__eyebrow">{props._builder?.name || "LONGOS 模块"}</p>
    <div className={isHero ? "beike-puck-section__hero" : undefined}>
      <div><h2>{title}</h2>
      {subtitle ? <p>{subtitle}</p> : <p>请在右侧设置面板中完善模块内容。</p>}
      {localized(props.description) ? <p>{localized(props.description)}</p> : null}
      {localized(props.button_text) ? <span className="beike-puck-section__action" aria-hidden="true">{localized(props.button_text)}</span> : null}</div>
      {isHero ? (heroImage ? <img className="beike-puck-section__image" src={heroImage} alt="" /> : <div className="beike-puck-section__image-placeholder">添加模块图片</div>) : null}
    </div>
    {isShopifyVideo ? <div className="beike-puck-video-preview">{localVideo ? <video controls preload="metadata" src={localVideo} poster={videoPoster || undefined} loop={props.enable_video_looping === true} /> : externalVideo ? <iframe src={externalVideo} title={localized(props.description) || title} loading="lazy" allow="autoplay; encrypted-media; picture-in-picture" allowFullScreen referrerPolicy="strict-origin-when-cross-origin" /> : <div className="beike-puck-section__image-placeholder">请选择媒体库 MP4，或填写 YouTube/Vimeo 地址</div>}</div> : null}
    {isShopifyCollapsible && blocks.length ? <div className="beike-puck-section__faq">{blocks.slice(0, 6).map((block, index) => <details key={index} open={index === 0 && props.open_first_collapsible_row === true}><summary>{itemTitle(block)}</summary><p>{localized(block.row_content)}</p></details>)}</div> : null}
    {isShopifyCollectionList && blocks.length ? <div className="beike-puck-section__cards beike-puck-section__collection-list">{blocks.slice(0, 6).map((block, index) => <article key={index}><strong>{typeof block.collection === "string" || typeof block.collection === "number" ? `商品分类 #${block.collection}` : "请选择商品分类"}</strong><span>请为该区块配置一个已启用的 LONGOS 商品分类。</span></article>)}</div> : null}
    {isShopifyFeaturedBlog ? <div className="beike-puck-section__cards"><article><strong>{typeof props.blog === "string" || typeof props.blog === "number" ? `内容分类 #${props.blog}` : "请选择内容分类"}</strong><span>前台将展示此分类中最新的已启用内容页。</span></article></div> : null}
    {isShopifyCollectionBanner ? <div className="beike-puck-section__cards"><article><strong>{typeof props.collection === "string" || typeof props.collection === "number" ? `商品分类 #${props.collection}` : "请选择商品分类"}</strong><span>前台将解析并展示该分类的名称、描述和图片。</span></article></div> : null}
    {(isShopifyPage || isShopifyMainPage) ? <div className="beike-puck-section__cards"><article><strong>{typeof props.page === "string" || typeof props.page === "number" ? `内容页 #${props.page}` : "请选择内容页"}</strong><span>前台将安全地展示所选 LONGOS 内容页。</span></article></div> : null}
    {isShopifyMultirow && blocks.length ? <div className="beike-puck-section__cards beike-puck-section__multicolumn">{blocks.slice(0, 6).map((block, index) => <article key={index}>{imageUrl(block.image) ? <img src={imageUrl(block.image)} alt="" /> : null}<strong>{itemTitle(block)}</strong><span>{localized(block.text)}</span></article>)}</div> : null}
    {code === "faq" && items.length ? <div className="beike-puck-section__faq">{items.slice(0, 4).map((item, index) => <details key={index} open={index === 0}><summary>{itemTitle(item)}</summary><p>{localized(item.answer)}</p></details>)}</div> : null}
    {["feature_grid", "trust_cards", "testimonials", "social_proof"].includes(code) && items.length ? <div className="beike-puck-section__cards">{items.slice(0, 4).map((item, index) => <article key={index}><strong>{itemTitle(item)}</strong><span>{localized(item.description) || localized(item.detail) || localized(item.quote)}</span></article>)}</div> : null}
    {code === "icons" && images.length ? <div className="beike-puck-section__cards">{images.slice(0, 6).map((item, index) => <article key={index}>{imageUrl(item.image) ? <img src={imageUrl(item.image)} alt="" /> : null}<strong>{localized(item.text) || `权益 ${index + 1}`}</strong><span>{localized(item.sub_text)}</span></article>)}</div> : null}
    {code === "campaign_offer" && offers.length ? <div className="beike-puck-section__cards">{offers.slice(0, 4).map((item, index) => <article key={index}><strong>{localized(item.benefit) || `优惠 ${index + 1}`}</strong><span>{localized(item.condition) || localized(item.description)}</span></article>)}</div> : null}
    {code === "payment_trust" && benefits.length ? <div className="beike-puck-section__cards">{benefits.slice(0, 4).map((item, index) => <article key={index}><strong>{localized(item.title) || `保障 ${index + 1}`}</strong><span>{localized(item.text)}</span></article>)}</div> : null}
    {code === "tab_product" && tabs.length ? <div className="beike-puck-section__cards">{tabs.slice(0, 8).map((item, index) => <article key={index}><strong>{localized(item.title) || `分组 ${index + 1}`}</strong><span>已选择 {Array.isArray(item.products) ? item.products.length : 0} 件商品</span></article>)}</div> : null}
    {["product", "brand", "page"].includes(code) ? <div className="beike-puck-section__cards"><article><strong>{code === "product" ? "商品" : code === "brand" ? "品牌" : "内容页"}</strong><span>已选择 {selectedResourceCount} 项</span></article></div> : null}
    {isShopifyMulticolumn && blocks.length ? <div className="beike-puck-section__cards beike-puck-section__multicolumn">{blocks.slice(0, 6).map((block, index) => <article key={index}>{imageUrl(block.image) ? <img src={imageUrl(block.image)} alt="" /> : null}<strong>{itemTitle(block)}</strong><span>{localized(block.text)}</span></article>)}</div> : null}
    {blocks.length && !isShopifyTextSection && !isShopifyMulticolumn && !isShopifyCollapsible && !isShopifyCollectionList && !isShopifyMultirow ? <div className="beike-puck-section__cards">{blocks.slice(0, 4).map((block, index) => <article key={index}><strong>{itemTitle(block)}</strong><span>{localized(block.text) || localized(block.description) || localized(block.heading)}</span></article>)}</div> : null}
  </section>;
};

const schemaFields = (unit?: ShopifyUnit): Record<string, unknown> => {
  const fields: Record<string, unknown> = {};
  for (const setting of unit?.settings || []) {
    if (!setting.id || fields[setting.id]) continue;
    if (["header", "paragraph"].includes(setting.type)) continue;
    const label = zhBuilderLabel(setting.label || setting.id);
    if (["text", "inline_richtext"].includes(setting.type)) fields[setting.id] = { type: "text", label };
    if (setting.type === "url") fields[setting.id] = { type: "custom", label, render: LinkField };
    if (setting.type === "video_url") fields[setting.id] = { type: "custom", label, render: ExternalVideoField };
    if (["textarea", "richtext", "html"].includes(setting.type)) fields[setting.id] = { type: "textarea", label };
    if (setting.type === "liquid") fields[setting.id] = { type: "textarea", label: `${label} · 仅保留原始内容，不会执行` };
    if (setting.type === "checkbox") fields[setting.id] = { type: "radio", label, options: [{ label: "开启", value: true }, { label: "关闭", value: false }] };
    if (setting.type === "range") fields[setting.id] = { type: "custom", label, render: RangeField, min: setting.min, max: setting.max, step: setting.step, unit: setting.unit };
    if (setting.type === "number") fields[setting.id] = { type: "number", label, min: setting.min, max: setting.max, step: setting.step };
    if (["select", "radio"].includes(setting.type)) {
      const options = selectOptions(setting.options);
      if (options.length) fields[setting.id] = { type: "select", label, options: options.map(option => ({ ...option, label: zhBuilderLabel(option.label, option.label) })) };
    }
    if (["image_picker", "image"].includes(setting.type) && mediaLibraryUrl) fields[setting.id] = { type: "custom", label, render: MediaField };
    if (setting.type === "video" && videoMediaLibraryUrl) fields[setting.id] = { type: "custom", label, render: VideoField };
    if (setting.type === "color") fields[setting.id] = { type: "custom", label, render: ColorField };
    if (setting.type === "color_background") fields[setting.id] = { type: "custom", label, render: GradientField };
    if (setting.type === "color_scheme") fields[setting.id] = { type: "custom", label, render: ColorSchemeField };
    const commerce = resourceField(setting);
    if (commerce) fields[setting.id] = commerce;
    if (!fields[setting.id]) fields[setting.id] = { type: "custom", label: `${label} · 需要适配`, render: NativeRecoveryField };
  }
  return fields;
};

const schemaDefaults = (unit?: ShopifyUnit): Record<string, unknown> => {
  const defaults: Record<string, unknown> = {};
  for (const setting of unit?.settings || []) {
    if (!setting.id || setting.default === undefined || defaults[setting.id] !== undefined) continue;
    // A JSON-compatible registry is generated server-side. Copy only those
    // defaults into a new Puck instance so a section starts in its intended
    // Shopify state without accepting executable values from source files.
    defaults[setting.id] = setting.default;
  }
  return defaults;
};

const blockField = (setting: { id?: string; type: string; label?: string; unit?: string; min?: number; max?: number; step?: number; options?: Array<{ label: string; value: string }> }): unknown | null => {
  if (["header", "paragraph"].includes(setting.type)) return null;
  const label = zhBuilderLabel(setting.label || setting.id);
  if (["text", "inline_richtext"].includes(setting.type)) return { type: "text", label };
  if (setting.type === "url") return { type: "custom", label, render: LinkField };
  if (setting.type === "video_url") return { type: "custom", label, render: ExternalVideoField };
  if (["textarea", "richtext", "html"].includes(setting.type)) return { type: "textarea", label };
  if (setting.type === "liquid") return { type: "textarea", label: `${label} · 仅保留原始内容，不会执行` };
  if (setting.type === "checkbox") return { type: "radio", label, options: [{ label: "开启", value: true }, { label: "关闭", value: false }] };
  if (setting.type === "range") return { type: "custom", label, render: RangeField, min: setting.min, max: setting.max, step: setting.step, unit: setting.unit };
  if (setting.type === "number") return { type: "number", label, min: setting.min, max: setting.max, step: setting.step };
  if (["select", "radio"].includes(setting.type)) {
    const options = selectOptions(setting.options);
    if (options.length) return { type: "select", label, options: options.map(option => ({ ...option, label: zhBuilderLabel(option.label, option.label) })) };
  }
  if (["image_picker", "image"].includes(setting.type) && mediaLibraryUrl) return { type: "custom", label, render: MediaField };
  if (setting.type === "video" && videoMediaLibraryUrl) return { type: "custom", label, render: VideoField };
  if (setting.type === "color") return { type: "custom", label, render: ColorField };
  if (setting.type === "color_background") return { type: "custom", label, render: GradientField };
  if (setting.type === "color_scheme") return { type: "custom", label, render: ColorSchemeField };
  const commerce = resourceField(setting);
  if (commerce) return commerce;
  return { type: "custom", label: `${label} · 需要适配`, render: NativeRecoveryField };
};

const blockArrayField = (unit?: ShopifyUnit): Record<string, unknown> => {
  if (!unit?.blocks.length) return {};
  const choices = unit.blocks.filter((block) => typeof block.type === "string" && !String(block.type).startsWith("@")).map((block) => ({ label: zhBuilderLabel(block.name || block.type, "内容区块"), value: String(block.type) }));
  if (!choices.length) return {};
  const arrayFields: Record<string, unknown> = { block_type: { type: "select", label: "区块类型", options: choices } };
  const defaults: Record<string, unknown> = { block_type: choices[0].value };
  for (const block of unit.blocks) {
    const settings = Array.isArray(block.settings) ? block.settings : [];
    for (const setting of settings) {
      if (!setting || typeof setting !== "object" || typeof (setting as { id?: unknown }).id !== "string") continue;
      const typed = setting as { id: string; type: string; label?: string; default?: unknown; min?: number; max?: number; step?: number; options?: Array<{ label: string; value: string }> };
      if (!arrayFields[typed.id]) {
        const field = blockField(typed);
        if (field) arrayFields[typed.id] = field;
      }
      if (typed.default !== undefined && defaults[typed.id] === undefined) defaults[typed.id] = typed.default;
    }
  }
  return { blocks: { type: "array", label: "迁移区块", arrayFields, defaultItemProps: defaults, max: 30 } };
};

const responsiveField = (): Record<string, unknown> => ({
  responsive: {
    type: "custom",
    label: "响应式显示",
    render: ResponsiveVisibilityField,
  },
});

interface ResponsiveVisibilityFieldProps { value: unknown; onChange: (value: Record<string, unknown>) => void; readOnly?: boolean; }
const ResponsiveVisibilityField = ({ value, onChange, readOnly }: ResponsiveVisibilityFieldProps): React.JSX.Element => {
  const responsive = value && typeof value === "object" && !Array.isArray(value) ? value as Record<string, unknown> : {};
  const visibility = responsive.visibility && typeof responsive.visibility === "object" && !Array.isArray(responsive.visibility) ? responsive.visibility as Record<string, unknown> : {};
  const devices = [{ key: "desktop", label: "桌面", detail: "≥1024px" }, { key: "tablet", label: "平板", detail: "768–1023px" }, { key: "mobile", label: "手机", detail: "<768px" }] as const;
  const enabled = (key: string): boolean => visibility[key] !== false;
  const update = (key: string, checked: boolean): void => onChange({ ...responsive, visibility: { ...visibility, [key]: checked } });
  const visibleCount = devices.filter(device => enabled(device.key)).length;
  return <fieldset className="beike-puck-responsive-field" disabled={readOnly}>
    <legend>选择这个模块显示在哪些设备上</legend>
    <div>{devices.map(device => <label key={device.key} className={enabled(device.key) ? "is-enabled" : ""}><input type="checkbox" checked={enabled(device.key)} onChange={event => update(device.key, event.target.checked)} /><span><strong>{device.label}</strong><small>{device.detail}</small></span></label>)}</div>
    {visibleCount === 0 ? <p role="alert">当前模块将在所有设备上隐藏。</p> : <p>{visibleCount === devices.length ? "所有设备均显示" : `已在 ${visibleCount} 类设备上显示`}</p>}
  </fieldset>;
};

interface AnchorFieldProps { field: { label?: string }; value: unknown; onChange: (value: string) => void; readOnly?: boolean; }
const AnchorField = ({ field, value, onChange, readOnly }: AnchorFieldProps): React.JSX.Element => {
  const current = typeof value === "string" ? value : "";
  const valid = current === "" || /^[a-z][a-z0-9_-]{0,63}$/.test(current);
  return <div className="beike-puck-anchor-field">
    <input type="text" value={current} disabled={readOnly} maxLength={64} pattern="[a-z][a-z0-9_-]{0,63}" aria-invalid={!valid} aria-label={field.label || "模块锚点"} placeholder="例如 new-arrivals" onChange={event => onChange(event.target.value.toLowerCase().replace(/\s+/g, "-"))} />
    <small>{valid ? "可用于菜单或广告链接，例如 #new-arrivals。" : "请以小写字母开头，仅使用字母、数字、- 或 _。"}</small>
  </div>;
};

/** Layout controls are renderer-agnostic and therefore safe for plugins too. */
interface SectionSpacingFieldProps { value: unknown; onChange: (value: Record<string, unknown>) => void; readOnly?: boolean; }
const SectionSpacingField = ({ value, onChange, readOnly }: SectionSpacingFieldProps): React.JSX.Element => {
  const uniqueId = useId();
  const layout = value && typeof value === "object" && !Array.isArray(value) ? value as Record<string, unknown> : {};
  const mode = layout.spacing_mode === "custom" ? "custom" : "inherit";
  const bounded = (candidate: unknown): number => Number.isFinite(Number(candidate)) ? Math.min(200, Math.max(0, Number(candidate))) : 64;
  const top = bounded(layout.top);
  const bottom = bounded(layout.bottom);
  const update = (next: Record<string, unknown>): void => onChange({ ...layout, spacing_mode: mode, top, bottom, ...next });
  return <fieldset className="beike-puck-spacing-field" disabled={readOnly}>
    <legend>控制模块与前后内容之间的留白</legend>
    <div className="beike-puck-spacing-field__mode">
      <label><input type="radio" name={`${uniqueId}-spacing`} checked={mode === "inherit"} onChange={() => update({ spacing_mode: "inherit" })} /><span>跟随全局主题</span></label>
      <label><input type="radio" name={`${uniqueId}-spacing`} checked={mode === "custom"} onChange={() => update({ spacing_mode: "custom" })} /><span>单独设置</span></label>
    </div>
    {mode === "custom" ? <div className="beike-puck-spacing-field__values">
      <label><span>上方</span><input type="range" min="0" max="200" step="4" value={top} onChange={event => update({ top: bounded(event.target.value) })} /><input type="number" min="0" max="200" value={top} onChange={event => update({ top: bounded(event.target.value) })} /><small>px</small></label>
      <label><span>下方</span><input type="range" min="0" max="200" step="4" value={bottom} onChange={event => update({ bottom: bounded(event.target.value) })} /><input type="number" min="0" max="200" value={bottom} onChange={event => update({ bottom: bounded(event.target.value) })} /><small>px</small></label>
    </div> : <p>使用“主题设置”中的全局模块间距。</p>}
  </fieldset>;
};

const sectionLayoutFields = (): Record<string, unknown> => ({
  section_layout: { type: "custom", label: "模块间距", render: SectionSpacingField },
  anchor: { type: "custom", label: "模块锚点（可选）", render: AnchorField },
});

const responsiveMediaFields = (code: string): Record<string, unknown> => code === "img_text_banner" ? {
  desktop_image: { type: "custom", label: "桌面端图片", render: MediaField },
  mobile_image: { type: "custom", label: "移动端图片", render: MediaField },
} : {};

export const createPuckConfig = (catalog: CatalogDefinition[], shopifyUnits: Record<string, ShopifyUnit>, generated: GeneratedRegistryComponent[], managerUrl: string, videoManagerUrl: string, endpoints: Record<string, string>, unknownTypes: string[] = [], locale = "zh_cn", appEmbeds: AppEmbedDefinition[] = [], editorLinks: EditorLinkType[] = []): Config => {
  editorLocale = locale;
  mediaLibraryUrl = managerUrl;
  videoMediaLibraryUrl = videoManagerUrl;
  commerceAdapter = new LongosCommerceAdapter(endpoints);
  if (editorLinks.length) linkTypeOptions = editorLinks;
  // Puck's public Config accepts a dynamic component map. The builder's own
  // props stay strictly typed above; the final map is intentionally dynamic
  // because plugins can register component codes at runtime.
  const components: Config["components"] = {};
  for (const item of catalog) {
    components[item.code] = {
      label: item.name,
      metadata: { category: item.category },
      defaultProps: {
        section_layout: { spacing_mode: "inherit", top: 64, bottom: 64 },
        anchor: "",
        ...item.make,
        id: `new-${item.code}`,
        // Preserve uncommon legacy properties without duplicating every old
        // form. Dedicated fields always win when both sources contain a key.
        _advanced_json: "",
        _builder: { code: item.code, name: item.name, view_path: item.view_path, component_version: item.component_version, visible: true },
      },
      fields: {
        ...schemaFields({ settings: item.fields || [], blocks: [] }),
        ...schemaFields(shopifyUnits[item.code]),
        ...blockArrayField(shopifyUnits[item.code]),
        ...responsiveMediaFields(item.code),
        ...nativeComponentFields(item.code, { ColorField, MediaField, StructuredLinkField, CommerceField, PaymentMethodsField, JsonObjectField: AdvancedJsonField, DateTimeField, RichTextField }),
        ...nativeSharedFields(item.code, ColorField),
        ...sectionLayoutFields(),
        ...responsiveField(),
        _advanced_json: { type: "custom", label: "模块扩展配置（JSON）", render: AdvancedJsonField },
      },
      resolveFields: (data, { fields }) => {
        const visibility = nativeFieldVisibility(item.code, data.props as Record<string, unknown>);
        if (!Object.keys(visibility).length) return fields;
        const resolved = { ...fields } as Record<string, typeof fields[string]>;
        for (const [name, visible] of Object.entries(visibility)) {
          if (resolved[name]) resolved[name] = { ...resolved[name], visible };
        }
        return resolved as typeof fields;
      },
      render: ComponentPreview,
    };
  }
  // LONGOS-compatible plugins normally contribute serializable catalog definitions.
  // This TEST-only block also supplies its purpose-built Puck renderer so the
  // editor preview and the persisted storefront module share one contract.
  for (const item of generated) {
    if (components[item.type]) continue;
    components[item.type] = {
      label: zhComponentLabel(item),
      metadata: { category: "shopify" },
      defaultProps: { section_layout: { spacing_mode: "inherit", top: 64, bottom: 64 }, anchor: "", ...schemaDefaults({ settings: item.fields, blocks: item.blocks }), id: `new-${item.type}`, _builder: { code: item.type, name: zhComponentLabel(item), component_version: item.version, visible: true } },
      fields: { ...schemaFields({ settings: item.fields, blocks: item.blocks }), ...blockArrayField({ settings: item.fields, blocks: item.blocks }), ...shopifyRuntimeFields(item.type), ...sectionLayoutFields(), ...responsiveField() } as Config["components"][string]["fields"],
      render: ComponentPreview,
    };
  }
  for (const type of unknownTypes) {
    if (components[type] || !/^[a-z][a-z0-9_-]{0,63}$/.test(type)) continue;
    // Preserve unknown content as a first-class editable Puck node. Puck's
    // own outline still offers delete/reorder; the raw props travel through
    // the adapter untouched until the missing theme/plugin returns.
    components[type] = {
      label: `暂不可用：${type}`,
      metadata: { category: "recovery" },
      defaultProps: { id: `recovery-${type}`, _builder: { code: type, name: `暂不可用组件（${type}）`, component_version: 1, visible: true } },
      fields: {},
      render: (props: BuilderModuleProps): React.JSX.Element => <section className="beike-puck-section beike-puck-section--unknown" data-module-id={props.id}><p className="beike-puck-section__eyebrow">恢复模式</p><h2>暂不可用组件：{type}</h2><p>原始配置已安全保留。请恢复所属主题或插件后再编辑，或在结构面板中删除/替换此组件。</p></section>,
    };
  }
  for (const component of Object.values(components)) {
    if (component.fields) component.fields = localizedFields(component.fields, locale) as typeof component.fields;
  }
  return {
    components,
    categories: { hero: { title: "首屏与横幅" }, commerce: { title: "商品与分类" }, content: { title: "内容模块" }, marketing: { title: "营销转化" }, trust: { title: "信任与保障" }, extension: { title: "扩展模块" }, recovery: { title: "恢复组件" } },
    // Global appearance, SEO and app embeds live in the dedicated Theme tab.
    // Keeping a second copy in Puck's root inspector caused two controls to
    // edit the same data and made the ordinary Settings tab look inconsistent.
    root: { fields: {} },
  };
};
