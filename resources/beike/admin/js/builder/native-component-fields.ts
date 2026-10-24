/**
 * Native section inspector definitions are intentionally separate from the
 * Puck assembly. Adding a first-party section no longer requires editing the
 * generated-Shopify field bridge or the root editor configuration.
 */
export interface NativeFieldRenderers {
  ColorField: unknown;
  MediaField: unknown;
  StructuredLinkField: unknown;
  CommerceField: unknown;
  PaymentMethodsField: unknown;
  JsonObjectField: unknown;
  DateTimeField: unknown;
  RichTextField: unknown;
}

const yesNo = (label: string): Record<string, unknown> => ({ type: "radio", label, options: [{ label: "开启", value: true }, { label: "关闭", value: false }] });
const select = (label: string, options: Array<[string, string]>): Record<string, unknown> => ({ type: "select", label, options: options.map(([value, text]) => ({ value, label: text })) });
const text = (label: string, placeholder = "请输入内容", maxLength = 160): Record<string, unknown> => ({ type: "text", label, placeholder, maxLength });
const area = (label: string, placeholder = "请输入内容", maxLength = 1200, rows = 4): Record<string, unknown> => ({ type: "textarea", label, placeholder, maxLength, rows });
const localizedDefault = (en: string, zhCn: string): Record<string, string> => ({ en, zh_cn: zhCn });
const itemSummary = (item: Record<string, unknown>, index = 0, keys: string[] = ["title", "heading", "name", "label", "question", "value"]): string => {
  for (const key of keys) {
    const value = item[key];
    if (typeof value === "string" && value.trim()) return value.trim().slice(0, 48);
    if (value && typeof value === "object" && !Array.isArray(value)) {
      const label = Object.values(value).find(item => typeof item === "string" && item.trim());
      if (typeof label === "string") return label.slice(0, 48);
    }
  }
  return `第 ${index + 1} 项`;
};
const itemArray = (label: string, arrayFields: Record<string, unknown>, max = 12, defaultItemProps: Record<string, unknown> = {}, summaryKeys?: string[], min = 1): Record<string, unknown> => ({
  type: "array", label, arrayFields, min, max, defaultItemProps,
  getItemSummary: (item: Record<string, unknown>, index?: number) => itemSummary(item, index || 0, summaryKeys),
});

/** Keep irrelevant controls out of the inspector without changing payload keys. */
export const nativeFieldVisibility = (code: string, props: Record<string, unknown>): Record<string, boolean> => {
  if (["img_text_slideshow", "img_text_slideshow_2"].includes(code)) return { autoplay_delay: props.autoplay === true };
  if (code === "campaign_offer") return { ends_at: props.timer_mode === "fixed" };
  return {};
};

/** Fields that are genuinely shared by a native renderer, never filler. */
export const nativeSharedFields = (code: string, ColorField: unknown): Record<string, unknown> => {
  const title = text("标题", "输入顾客可见的模块标题");
  const subtitle = area("副标题", "用一句简短说明补充标题", 320, 3);
  const button = text("按钮文字", "例如：立即选购", 48);
  const sized = { module_size: select("内容宽度", [["container-fluid", "常规页面宽度"], ["container", "紧凑内容宽度"], ["w-100", "全宽展示"]]) };
  const style = { style: { type: "object", label: "模块背景", objectFields: { background_color: { type: "custom", label: "背景色", render: ColorField, allowEmpty: true, emptyLabel: "跟随页面背景" } } } };
  if (["promo_bar"].includes(code)) return { ...sized, ...style, title, sub_title: subtitle, button_text: button };
  if (["trust_cards", "testimonials", "campaign_offer", "payment_trust", "social_proof"].includes(code)) return { ...sized, ...style, title, sub_title: subtitle };
  if (code === "story_block") return { ...sized, ...style, title, button_text: button };
  if (["faq", "feature_grid"].includes(code)) return { ...sized, ...style, title, sub_title: subtitle };
  if (code === "rich_text") return { ...sized, ...style };
  if (code === "custom_code") return { ...sized, ...style, title };
  if (["latest_products", "bestseller"].includes(code)) return { ...sized, ...style, title, sub_title: subtitle };
  if (["tab_product", "product", "brand", "page"].includes(code)) return { ...sized, ...style, title };
  if (code === "icons") return { ...sized, ...style, title, sub_title: subtitle };
  if (["img_text_slideshow", "img_text_slideshow_2"].includes(code)) return { ...style, module_size: select("内容宽度", [["w-100", "全宽展示"], ["container-fluid", "常规页面宽度"]]) };
  return { ...sized, ...style };
};

/** Real inspector fields for the native storefront section payloads. */
export const nativeComponentFields = (code: string, renderers: NativeFieldRenderers): Record<string, unknown> => {
  const link = { type: "custom", label: "跳转链接", render: renderers.StructuredLinkField };
  const image = { type: "custom", label: "图片", render: renderers.MediaField };
  const cardLink = { type: "custom", label: "卡片链接", render: renderers.StructuredLinkField };
  const productList = (label: string): Record<string, unknown> => ({ type: "custom", label, render: renderers.CommerceField, resourceType: "product", multiple: true, max: 24 });
  const brandList = (label: string): Record<string, unknown> => ({ type: "custom", label, render: renderers.CommerceField, resourceType: "brand", multiple: true, max: 24 });
  const pageList = (label: string): Record<string, unknown> => ({ type: "custom", label, render: renderers.CommerceField, resourceType: "page", multiple: true, max: 24 });
  const imageItemFields = { image, image_alt: text("图片替代文字", "描述图片内容，装饰图可留空", 180), text: text("主文案", "例如：免费配送", 80), sub_text: text("补充文案", "用一句话说明具体权益", 180), link: cardLink };
  const slideItemFields = { image, image_alt: text("图片替代文字", "描述图片内容，装饰图可留空", 180), sub_title: text("眉题", "例如：本周新品", 80), title: text("标题", "输入轮播主标题", 120), description: area("说明", "补充活动或商品信息", 320, 3), text_position: select("文字位置", [["start", "左侧"], ["center", "居中"], ["end", "右侧"]]), link: cardLink };
  if (["img_text_slideshow", "img_text_slideshow_2"].includes(code)) return {
    title: text("轮播名称（辅助阅读）", "例如：首页主轮播", 80),
    autoplay: yesNo("自动轮播"),
    autoplay_delay: { type: "number", label: "轮播间隔（毫秒）", min: 2000, max: 15000 },
    images: itemArray("轮播内容", slideItemFields, 12, { image: "", image_alt: localizedDefault("", ""), sub_title: localizedDefault("", ""), title: localizedDefault("New slide", "新轮播内容"), description: localizedDefault("", ""), text_position: "start", link: { type: "custom", value: "", new_window: false } }, ["title", "sub_title"]),
    scroll_text: { type: "object", label: "滚动公告", objectFields: { text: text("公告文字"), bg: { type: "custom", label: "公告背景色", render: renderers.ColorField }, color: { type: "custom", label: "公告文字颜色", render: renderers.ColorField }, font_size: { type: "number", label: "字体大小", min: 12, max: 32 }, padding: { type: "number", label: "上下留白", min: 0, max: 48 } } },
  };
  const commerceGrid = {
    columns_desktop: { type: "number", label: "桌面端列数（≥1024px）", min: 2, max: 5 },
    columns_tablet: { type: "number", label: "平板端列数（768–1023px）", min: 1, max: 3 },
    columns_mobile: { type: "number", label: "移动端列数（<768px）", min: 1, max: 2 },
  };
  if (code === "tab_product") return { ...commerceGrid, tabs: itemArray("商品标签页", { title: text("标签名称"), products: productList("选择商品") }, 8, { title: localizedDefault("New tab", "新标签"), products: [] }) };
  if (code === "product") return { ...commerceGrid, products: productList("展示商品") };
  if (code === "brand") return { ...commerceGrid, show_view_all: yesNo("显示全部品牌入口"), brands: brandList("展示品牌") };
  if (code === "icons") return { ...commerceGrid, style_variant: select("展示样式", [["minimal", "简洁图标"], ["cards", "卡片式"]]), images: itemArray("图标与权益", imageItemFields, 10, { image: "", image_alt: localizedDefault("", ""), text: localizedDefault("New benefit", "新权益"), sub_text: localizedDefault("", ""), link: { type: "custom", value: "", new_window: false } }, ["text", "sub_text"]) };
  if (code === "page") return { ...commerceGrid, show_view_all: yesNo("显示全部内容入口"), items: pageList("展示内容页") };
  if (["latest_products", "bestseller"].includes(code)) return {
    limit: { type: "number", label: "展示数量", min: 1, max: 24 },
    layout: select("展示方式", [["grid", "响应式网格"], ["scroll", "横向滑动"]]),
    ...commerceGrid,
  };
  if (code === "promo_bar") return {
    style: { type: "object", label: "外观", objectFields: { background_color: { type: "custom", label: "背景色", render: renderers.ColorField, allowEmpty: true, emptyLabel: "跟随页面背景" } } },
    link,
  };
  if (code === "faq") return { style_variant: select("展示样式", [["divided", "分隔线"], ["cards", "卡片式"]]), open_first: yesNo("默认展开第一项"), items: itemArray("问答内容", { question: text("问题"), answer: area("答案") }, 12, { question: localizedDefault("New question", "新问题"), answer: localizedDefault("", "") }, ["question"]) };
  const contentGrid = {
    columns: { type: "number", label: "桌面端列数（≥1024px）", min: 2, max: 4 },
    columns_tablet: { type: "number", label: "平板端列数（768–1023px）", min: 1, max: 3 },
    columns_mobile: { type: "number", label: "移动端列数（<768px）", min: 1, max: 2 },
  };
  if (code === "trust_cards") return { ...contentGrid, style_variant: select("展示样式", [["soft", "柔和卡片"], ["outline", "线框卡片"], ["contrast", "高对比卡片"]]), items: itemArray("服务权益卡片", { icon: text("图标"), title: text("标题"), description: area("说明"), link: cardLink }, 6, { icon: localizedDefault("✦", "✦"), title: localizedDefault("New service benefit", "新服务权益"), description: localizedDefault("", ""), link: { type: "custom", value: "", new_window: false } }) };
  if (code === "testimonials") return { ...contentGrid, style_variant: select("展示样式", [["cards", "卡片式"], ["soft", "柔和背景"], ["minimal", "简洁引用"]]), items: itemArray("顾客评价", { quote: area("评价内容"), name: text("顾客姓名"), detail: text("补充说明"), rating: { type: "number", label: "评分（1–5）", min: 1, max: 5 } }, 8, { quote: localizedDefault("New customer review", "新的顾客评价"), name: localizedDefault("", ""), detail: localizedDefault("", ""), rating: 5 }, ["quote", "name"]) };
  if (code === "campaign_offer") return {
    timer_mode: select("倒计时", [["none", "不显示"], ["daily", "每日重置"], ["fixed", "固定结束时间"]]), ends_at: { type: "custom", label: "固定结束时间", render: renderers.DateTimeField }, code: text("优惠码"), code_label: text("优惠码说明"), terms: area("活动规则"),
    offers: itemArray("优惠阶梯", { quantity: { type: "number", label: "商品数量", min: 1, max: 99 }, condition: text("条件"), benefit: text("优惠内容"), description: text("补充说明"), badge: text("标签"), best: yesNo("推荐方案"), free_shipping: yesNo("免运费") }, 4, { quantity: 1, condition: localizedDefault("Purchase condition", "购买条件"), benefit: localizedDefault("Offer benefit", "优惠内容"), description: localizedDefault("", ""), badge: localizedDefault("", ""), best: false, free_shipping: false }, ["benefit", "condition"]),
  };
  if (code === "payment_trust") return {
    status_text: text("状态文案"), payments_title: text("支付方式标题"), payments_sub: text("支付方式说明"),
    payments: { type: "custom", label: "支持的支付方式", render: renderers.PaymentMethodsField },
    benefits: itemArray("购买保障", { icon: text("图标"), title: text("标题"), text: text("说明"), gold: yesNo("高亮") }, 4, { icon: localizedDefault("✓", "✓"), title: localizedDefault("New purchase protection", "新购买保障"), text: localizedDefault("", ""), gold: false }),
  };
  if (code === "story_block") return {
    image, image_alt: text("图片替代文字"), eyebrow: text("眉题"), description: area("正文说明"), theme: select("视觉主题", [["light", "浅色"], ["ink", "深色"], ["sand", "暖色"]]), image_position: select("图片位置", [["right", "右侧"], ["left", "左侧"]]), link,
  };
  if (code === "feature_grid") return {
    ...contentGrid,
    image_ratio: select("图片比例", [["landscape", "横向"], ["square", "方形"], ["portrait", "纵向"]]),
    card_style: select("卡片样式", [["editorial", "杂志式"], ["bordered", "线框式"], ["minimal", "简洁式"]]),
    items: itemArray("精选卡片", { image, image_alt: text("图片替代文字"), eyebrow: text("眉题"), title: text("标题"), text: area("说明"), link_label: text("链接文字"), link: cardLink }, 8, { image: "", image_alt: localizedDefault("", ""), eyebrow: localizedDefault("", ""), title: localizedDefault("New featured card", "新精选卡片"), text: localizedDefault("", ""), link_label: localizedDefault("", ""), link: { type: "custom", value: "", new_window: false } }),
  };
  if (code === "social_proof") return { theme: select("视觉主题", [["light", "浅色"], ["ink", "深色"], ["sand", "暖色"]]), items: itemArray("信任背书", { value: text("数据"), label: text("标签"), detail: text("说明") }, 5, { value: localizedDefault("", ""), label: localizedDefault("New proof point", "新信任数据"), detail: localizedDefault("", "") }, ["label", "value"]) };
  if (code === "custom_code") return { height: { type: "number", label: "预览高度", min: 160, max: 1200 }, html: area("安全 HTML"), css: area("安全 CSS"), settings: { type: "custom", label: "区块设置（JSON）", render: renderers.JsonObjectField } };
  if (code === "rich_text") return { text: { type: "custom", label: "富文本内容", render: renderers.RichTextField } };
  return {};
};
