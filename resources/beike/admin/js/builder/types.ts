export type BuilderBreakpoint = "desktop" | "tablet" | "mobile";

export interface ResponsiveVisibility {
  desktop: boolean;
  tablet: boolean;
  mobile: boolean;
}

export interface BuilderModuleProps {
  id: string;
  title?: string | Record<string, string>;
  sub_title?: string | Record<string, string>;
  button_text?: string | Record<string, string>;
  placement?: "home" | "product" | "category" | "page" | "both" | "all";
  module_size?: "container-fluid" | "container" | "w-100";
  anchor?: string;
  section_layout?: { spacing_mode?: "inherit" | "custom"; top?: number; bottom?: number };
  responsive?: { visibility?: Partial<ResponsiveVisibility> };
  _builder?: { module_id?: string; code?: string; name?: string; view_path?: string; visible?: boolean; component_version?: number };
  [key: string]: unknown;
}

export interface BuilderRootProps {
  schemaVersion: number;
  pageType: string;
  globalStyle: Record<string, unknown>;
  appEmbeds: Record<string, unknown>;
  pageMetadata: { title?: string; slug?: string; seo_title?: string; seo_description?: string; featured_image?: string };
}

export interface CatalogDefinition {
  code: string;
  name: string;
  category: string;
  description?: string;
  keywords?: string[];
  view_path: string;
  make: Record<string, unknown>;
  fields?: ShopifySetting[];
  component_version: number;
  available_on?: Array<"home" | "product" | "category" | "page">;
  max_instances?: number;
  capabilities?: Record<string, boolean>;
}
export interface ShopifySetting { id?: string; type: string; label?: string; info?: string; placeholder?: string; unit?: string; default?: unknown; min?: number; max?: number; step?: number; limit?: number; options?: Array<{ label: string; value: string }>; }
export interface ShopifyUnit { settings: ShopifySetting[]; blocks: Array<Record<string, unknown>>; }
export interface GeneratedRegistryComponent { type: string; label: string; source: string; version: number; fields: ShopifySetting[]; blocks: Array<Record<string, unknown>>; }
export interface AppEmbedDefinition { code: string; name: string; description: string; default_enabled: boolean; }
export interface EditorLinkType { value: string; label: string; }

export interface PuckData {
  content: Array<{ type: string; props: BuilderModuleProps }>;
  root: { props: BuilderRootProps };
}

export interface BuilderBootstrap {
  catalog: CatalogDefinition[];
  shopifyUnits: Record<string, ShopifyUnit>;
  shopifyRegistry: GeneratedRegistryComponent[];
  appEmbeds: AppEmbedDefinition[];
  editorLinkTypes: EditorLinkType[];
  puckData: PuckData;
  updateUrl: string;
  previewModuleUrl: string;
  mediaLibraryUrl: string;
  videoMediaLibraryUrl: string;
  entityAutocompleteUrls: Record<string, string>;
  revisionsUrl: string;
  restoreRevisionUrl: string;
  storefrontBaseUrl: string;
  storefrontPreviewUrl: string;
  navigationEditorUrl: string;
  policySettingsUrl: string;
  productPreviewUrl: string;
  categoryPreviewUrl: string;
  pagePreviewUrl: string;
  exportUrl: string;
  importUrl: string;
  swiperStyleUrl: string;
  swiperScriptUrl: string;
  locale: string;
  headerSettings: Record<string, unknown>;
  footerSettings: Record<string, unknown>;
  menuSettings: Record<string, unknown>;
  csrfToken: string;
  draftVersion: number;
  hasUnpublishedChanges: boolean;
}
