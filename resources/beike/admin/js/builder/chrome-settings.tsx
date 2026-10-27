import { useEffect, useRef, useState } from "react";
import { MediaField, StructuredLinkField } from "./config";

export interface BuilderChrome {
    header: Record<string, unknown>;
    footer: Record<string, unknown>;
    menu: Record<string, unknown>;
}

interface ChromeSettingsProps {
    chrome: BuilderChrome;
    locale: string;
    initialTab: "header" | "footer";
    navigationUrl: string;
    policySettingsUrl: string;
    onTabChange: (tab: "header" | "footer") => void;
    onClose: () => void;
    onDraftChange: (chrome: BuilderChrome) => void;
    onSave: (chrome: BuilderChrome) => Promise<boolean>;
}

const clone = <T,>(value: T): T => JSON.parse(JSON.stringify(value)) as T;
const record = (value: unknown): Record<string, unknown> =>
    value && typeof value === "object" && !Array.isArray(value)
        ? (value as Record<string, unknown>)
        : {};
const strings = (value: unknown): Record<string, string> =>
    Object.fromEntries(
        Object.entries(record(value)).filter(
            (entry): entry is [string, string] => typeof entry[1] === "string",
        ),
    );
const textAt = (value: unknown, locale: string): string =>
    strings(value)[locale] || strings(value).zh_cn || strings(value).en || "";
const setTextAt = (
    target: Record<string, unknown>,
    key: string,
    locale: string,
    text: string,
): void => {
    target[key] = { ...strings(target[key]), [locale]: text };
};

const newAnnouncement = (locale: string): Record<string, unknown> => ({
    title: { [locale]: "新公告" },
    link: { type: "custom", value: "", new_window: false },
});
const newService = (locale: string): Record<string, unknown> => ({
    show: true,
    image: "",
    title: { [locale]: "服务保障" },
    sub_title: { [locale]: "" },
});
const newFooterLink = (locale: string): Record<string, unknown> => ({
    type: "custom",
    value: "",
    text: { [locale]: "新链接" },
    new_window: false,
});
const newSocialLink = (): Record<string, unknown> => ({
    image: "",
    link: "",
    show: true,
});
const paymentOptions = [
    ["visa", "Visa"],
    ["mastercard", "Mastercard"],
    ["amex", "American Express"],
    ["paypal", "PayPal"],
    ["apple_pay", "Apple Pay"],
    ["google_pay", "Google Pay"],
    ["shop_pay", "Shop Pay"],
    ["klarna", "Klarna"],
    ["afterpay", "Afterpay"],
    ["unionpay", "UnionPay"],
    ["jcb", "JCB"],
    ["discover", "Discover"],
] as const;

/**
 * This inline inspector occupies Puck's normal settings panel while the
 * central canvas continues to show the real theme header and footer.
 */
export const ChromeSettings = ({
    chrome,
    locale,
    initialTab,
    navigationUrl,
    policySettingsUrl,
    onTabChange,
    onClose,
    onDraftChange,
    onSave,
}: ChromeSettingsProps): React.JSX.Element => {
    const [draft, setDraft] = useState<BuilderChrome>(() => clone(chrome));
    const draftRef = useRef<BuilderChrome>(clone(chrome));
    const [tab, setTab] = useState<"header" | "footer">(initialTab);
    const [dirty, setDirty] = useState(false);
    const [saving, setSaving] = useState(false);
    const [saveError, setSaveError] = useState("");
    const autoSaveTimer = useRef<number | null>(null);
    const editRevision = useRef(0);
    const savesInFlight = useRef(0);
    useEffect(() => {
        if (!dirty) {
            const next = clone(chrome);
            draftRef.current = next;
            setDraft(next);
        }
    }, [chrome, dirty]);
    useEffect(() => setTab(initialTab), [initialTab]);
    useEffect(() => {
        if (!dirty) return;
        if (autoSaveTimer.current) window.clearTimeout(autoSaveTimer.current);
        const revision = editRevision.current;
        autoSaveTimer.current = window.setTimeout(() => {
            savesInFlight.current += 1;
            setSaving(true);
            setSaveError("");
            void onSave(clone(draftRef.current)).then((saved) => {
                if (!saved) {
                    if (editRevision.current === revision) setSaveError("自动保存失败，请检查网络后重试。");
                    return;
                }
                if (editRevision.current === revision) setDirty(false);
            }).catch(() => { if (editRevision.current === revision) setSaveError("自动保存失败，请检查网络后重试。"); }).finally(() => {
                savesInFlight.current = Math.max(0, savesInFlight.current - 1);
                if (savesInFlight.current === 0) setSaving(false);
            });
        }, 800);
        return () => {
            if (autoSaveTimer.current) window.clearTimeout(autoSaveTimer.current);
        };
    }, [dirty, draft, onSave]);

    const update = (mutate: (next: BuilderChrome) => void): void => {
        editRevision.current += 1;
        setSaveError("");
        const next = clone(draftRef.current);
        mutate(next);
        draftRef.current = next;
        setDraft(next);
        // Stage every keystroke in the parent immediately. If this inspector
        // unmounts before its debounce fires, the parent-level recovery and
        // autosave timers still retain the merchant's latest header/footer.
        onDraftChange(clone(next));
        setDirty(true);
    };
    const commit = async (): Promise<boolean> => {
        if (autoSaveTimer.current) window.clearTimeout(autoSaveTimer.current);
        if (!dirty) return true;
        const revision = editRevision.current;
        savesInFlight.current += 1;
        setSaving(true);
        setSaveError("");
        let saved = false;
        try {
            saved = await onSave(clone(draftRef.current));
            if (saved && editRevision.current === revision) setDirty(false);
            if (!saved) setSaveError("保存失败，当前修改仍保留在编辑器中。");
        } catch {
            setSaveError("保存失败，当前修改仍保留在编辑器中。");
        } finally {
            savesInFlight.current = Math.max(0, savesInFlight.current - 1);
            if (savesInFlight.current === 0) setSaving(false);
        }
        return saved;
    };
    const close = async (): Promise<void> => {
        if (await commit()) onClose();
    };
    const changeTab = (next: "header" | "footer"): void => {
        setTab(next);
        onTabChange(next);
    };
    const setAnnouncementLink = (
        index: number,
        link: Record<string, unknown>,
    ): void =>
        update((next) => {
            const nextAds = record(record(next.header).header_ads);
            const items = Array.isArray(nextAds.items)
                ? nextAds.items.map(record)
                : [];
            if (!items[index]) return;
            items[index].link = { ...record(items[index].link), ...link };
            nextAds.items = items;
            record(next.header).header_ads = nextAds;
        });
    const header = record(draft.header);
    const ads = record(header.header_ads);
    const announcements = Array.isArray(ads.items) ? ads.items.map(record) : [];
    const footer = record(draft.footer);
    const content = record(footer.content);
    const intro = record(content.intro);
    const socialItems = Array.isArray(intro.social_network)
        ? intro.social_network.map(record)
        : [];
    const contact = record(content.contact);
    const services = record(footer.services);
    const serviceItems = Array.isArray(services.items)
        ? services.items.map(record)
        : [];
    const bottom = record(footer.bottom);
    const paymentMethods = Array.isArray(bottom.payments)
        ? bottom.payments.filter(
              (item): item is string => typeof item === "string",
          )
        : [];
    const footerGroups = ["link1", "link2", "link3"].map((key) => ({
        key,
        value: record(content[key]),
    }));

    return (
        <section
            className="beike-puck-chrome-dialog beike-puck-chrome-panel"
            aria-labelledby="builder-chrome-title"
        >
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    void commit();
                }}
            >
                <header className="beike-puck-chrome-dialog__head">
                    <div>
                        <h2 id="builder-chrome-title">页头与页尾</h2>
                        <p>修改会自动保存到草稿，并同步刷新真实前台预览。</p>
                    </div>
                    <button
                        type="button"
                        onClick={() => void close()}
                        aria-label="关闭页头与页尾设置"
                    >
                        ×
                    </button>
                </header>
                <nav
                    className="beike-puck-chrome-tabs"
                    aria-label="页面结构设置"
                >
                    <button
                        type="button"
                        className={tab === "header" ? "is-active" : ""}
                        onClick={() => changeTab("header")}
                    >
                        页头与导航
                    </button>
                    <button
                        type="button"
                        className={tab === "footer" ? "is-active" : ""}
                        onClick={() => changeTab("footer")}
                    >
                        页尾与保障
                    </button>
                </nav>
                <div className="beike-puck-chrome-dialog__body">
                    {tab === "header" ? (
                        <>
                            <section className="beike-puck-chrome-section">
                                <h3>公告栏</h3>
                                <label className="beike-puck-chrome-switch">
                                    <input
                                        type="checkbox"
                                        checked={ads.active === true}
                                        onChange={(event) =>
                                            update((next) => {
                                                record(next.header).header_ads =
                                                    {
                                                        ...record(
                                                            record(next.header)
                                                                .header_ads,
                                                        ),
                                                        active: event.target
                                                            .checked,
                                                    };
                                            })
                                        }
                                    />
                                    启用公告栏
                                </label>
                                <div className="beike-puck-chrome-grid">
                                    <label>
                                        公告背景色
                                        <input
                                            type="color"
                                            value={
                                                typeof ads.bg_color === "string"
                                                    ? ads.bg_color
                                                    : "#101828"
                                            }
                                            onChange={(event) =>
                                                update((next) => {
                                                    record(
                                                        next.header,
                                                    ).header_ads = {
                                                        ...record(
                                                            record(next.header)
                                                                .header_ads,
                                                        ),
                                                        bg_color:
                                                            event.target.value.toUpperCase(),
                                                    };
                                                })
                                            }
                                        />
                                    </label>
                                    <label>
                                        公告文字颜色
                                        <input
                                            type="color"
                                            value={
                                                typeof ads.color === "string"
                                                    ? ads.color
                                                    : "#FFFFFF"
                                            }
                                            onChange={(event) =>
                                                update((next) => {
                                                    record(
                                                        next.header,
                                                    ).header_ads = {
                                                        ...record(
                                                            record(next.header)
                                                                .header_ads,
                                                        ),
                                                        color: event.target.value.toUpperCase(),
                                                    };
                                                })
                                            }
                                        />
                                    </label>
                                </div>
                                {announcements.map((item, index) => (
                                    <article
                                        className="beike-puck-chrome-card"
                                        key={index}
                                    >
                                        <label>
                                            公告文字
                                            <input
                                                value={textAt(
                                                    item.title,
                                                    locale,
                                                )}
                                                onChange={(event) =>
                                                    update((next) => {
                                                        const nextAds = record(
                                                            record(next.header)
                                                                .header_ads,
                                                        );
                                                        const items =
                                                            Array.isArray(
                                                                nextAds.items,
                                                            )
                                                                ? nextAds.items.map(
                                                                      record,
                                                                  )
                                                                : [];
                                                        setTextAt(
                                                            items[index],
                                                            "title",
                                                            locale,
                                                            event.target.value,
                                                        );
                                                        nextAds.items = items;
                                                        record(
                                                            next.header,
                                                        ).header_ads = nextAds;
                                                    })
                                                }
                                            />
                                        </label>
                                        <StructuredLinkField
                                            field={{ label: "公告跳转链接" }}
                                            value={record(item.link)}
                                            onChange={(link) =>
                                                setAnnouncementLink(index, link)
                                            }
                                        />
                                        <button
                                            type="button"
                                            className="is-danger"
                                            onClick={() =>
                                                update((next) => {
                                                    const nextAds = record(
                                                        record(next.header)
                                                            .header_ads,
                                                    );
                                                    nextAds.items = (
                                                        Array.isArray(
                                                            nextAds.items,
                                                        )
                                                            ? nextAds.items
                                                            : []
                                                    ).filter(
                                                        (_, itemIndex) =>
                                                            itemIndex !== index,
                                                    );
                                                    record(
                                                        next.header,
                                                    ).header_ads = nextAds;
                                                })
                                            }
                                        >
                                            删除公告
                                        </button>
                                    </article>
                                ))}
                                <button
                                    type="button"
                                    onClick={() =>
                                        update((next) => {
                                            const nextAds = record(
                                                record(next.header).header_ads,
                                            );
                                            nextAds.items = [
                                                ...(Array.isArray(nextAds.items)
                                                    ? nextAds.items
                                                    : []),
                                                newAnnouncement(locale),
                                            ];
                                            record(next.header).header_ads =
                                                nextAds;
                                        })
                                    }
                                >
                                    添加公告
                                </button>
                            </section>
                            <section className="beike-puck-chrome-section">
                                <h3>主导航</h3>
                                <p>
                                    页头菜单已拆分为独立工作台，可完整编辑排序、标签、分组、Mega
                                    Menu 和子链接。
                                </p>
                                <a
                                    className="beike-puck-chrome-link"
                                    href={navigationUrl}
                                >
                                    打开页头菜单编辑器
                                </a>
                            </section>
                        </>
                    ) : (
                        <>
                            <section className="beike-puck-chrome-section">
                                <h3>品牌与联系方式</h3>
                                <div className="beike-puck-chrome-media">
                                    <span>页尾品牌标志</span>
                                    <MediaField
                                        field={{ label: "选择页尾品牌标志" }}
                                        value={intro.logo}
                                        onChange={(value) =>
                                            update((next) => {
                                                const nextFooter = record(next.footer);
                                                const nextContent = record(nextFooter.content);
                                                nextContent.intro = {
                                                    ...record(nextContent.intro),
                                                    logo: value,
                                                };
                                                nextFooter.content = nextContent;
                                                next.footer = nextFooter;
                                            })
                                        }
                                    />
                                    <small>留空时使用系统中的店铺名称。</small>
                                </div>
                                <div className="beike-puck-chrome-grid">
                                    <label>
                                        联系电话
                                        <input
                                            value={
                                                typeof contact.telephone ===
                                                "string"
                                                    ? contact.telephone
                                                    : ""
                                            }
                                            onChange={(event) =>
                                                update((next) => {
                                                    const nextFooter = record(
                                                        next.footer,
                                                    );
                                                    const nextContent = record(
                                                        nextFooter.content,
                                                    );
                                                    nextContent.contact = {
                                                        ...record(
                                                            nextContent.contact,
                                                        ),
                                                        telephone:
                                                            event.target.value,
                                                    };
                                                    nextFooter.content =
                                                        nextContent;
                                                    next.footer = nextFooter;
                                                })
                                            }
                                        />
                                    </label>
                                    <label>
                                        联系地址
                                        <input
                                            value={textAt(
                                                contact.address,
                                                locale,
                                            )}
                                            onChange={(event) =>
                                                update((next) => {
                                                    const nextFooter = record(
                                                        next.footer,
                                                    );
                                                    const nextContent = record(
                                                        nextFooter.content,
                                                    );
                                                    const nextContact = record(
                                                        nextContent.contact,
                                                    );
                                                    setTextAt(
                                                        nextContact,
                                                        "address",
                                                        locale,
                                                        event.target.value,
                                                    );
                                                    nextContent.contact =
                                                        nextContact;
                                                    nextFooter.content =
                                                        nextContent;
                                                    next.footer = nextFooter;
                                                })
                                            }
                                        />
                                    </label>
                                    <label>
                                        品牌说明
                                        <textarea
                                            value={textAt(intro.text, locale)}
                                            onChange={(event) =>
                                                update((next) => {
                                                    const nextFooter = record(
                                                        next.footer,
                                                    );
                                                    const nextContent = record(
                                                        nextFooter.content,
                                                    );
                                                    const nextIntro = record(
                                                        nextContent.intro,
                                                    );
                                                    setTextAt(
                                                        nextIntro,
                                                        "text",
                                                        locale,
                                                        event.target.value,
                                                    );
                                                    nextContent.intro =
                                                        nextIntro;
                                                    nextFooter.content =
                                                        nextContent;
                                                    next.footer = nextFooter;
                                                })
                                            }
                                        />
                                    </label>
                                    <label>
                                        版权文案
                                        <textarea
                                            value={textAt(
                                                bottom.copyright,
                                                locale,
                                            )}
                                            onChange={(event) =>
                                                update((next) => {
                                                    const nextFooter = record(
                                                        next.footer,
                                                    );
                                                    const nextBottom = record(
                                                        nextFooter.bottom,
                                                    );
                                                    setTextAt(
                                                        nextBottom,
                                                        "copyright",
                                                        locale,
                                                        event.target.value,
                                                    );
                                                    nextFooter.bottom =
                                                        nextBottom;
                                                    next.footer = nextFooter;
                                                })
                                            }
                                        />
                                    </label>
                                </div>
                            </section>
                            <section className="beike-puck-chrome-section">
                                <h3>社交媒体</h3>
                                <p>只显示已启用且填写了有效 HTTPS 地址的项目。</p>
                                {socialItems.map((item, index) => (
                                    <article className="beike-puck-chrome-card" key={index}>
                                        <label className="beike-puck-chrome-switch">
                                            <input
                                                type="checkbox"
                                                checked={item.show !== false}
                                                onChange={(event) => update((next) => {
                                                    const nextFooter = record(next.footer);
                                                    const nextContent = record(nextFooter.content);
                                                    const nextIntro = record(nextContent.intro);
                                                    const items = Array.isArray(nextIntro.social_network) ? nextIntro.social_network.map(record) : [];
                                                    items[index].show = event.target.checked;
                                                    nextIntro.social_network = items;
                                                    nextContent.intro = nextIntro;
                                                    nextFooter.content = nextContent;
                                                    next.footer = nextFooter;
                                                })}
                                            />
                                            在页尾显示
                                        </label>
                                        <label>
                                            社交主页地址
                                            <input
                                                type="url"
                                                inputMode="url"
                                                placeholder="https://instagram.com/your-store"
                                                value={typeof item.link === "string" ? item.link : ""}
                                                onChange={(event) => update((next) => {
                                                    const nextFooter = record(next.footer);
                                                    const nextContent = record(nextFooter.content);
                                                    const nextIntro = record(nextContent.intro);
                                                    const items = Array.isArray(nextIntro.social_network) ? nextIntro.social_network.map(record) : [];
                                                    items[index].link = event.target.value;
                                                    nextIntro.social_network = items;
                                                    nextContent.intro = nextIntro;
                                                    nextFooter.content = nextContent;
                                                    next.footer = nextFooter;
                                                })}
                                            />
                                        </label>
                                        <div className="beike-puck-chrome-media is-compact">
                                            <span>自定义图标（可选）</span>
                                            <MediaField
                                                field={{ label: "选择社交媒体图标" }}
                                                value={item.image}
                                                onChange={(value) => update((next) => {
                                                    const nextFooter = record(next.footer);
                                                    const nextContent = record(nextFooter.content);
                                                    const nextIntro = record(nextContent.intro);
                                                    const items = Array.isArray(nextIntro.social_network) ? nextIntro.social_network.map(record) : [];
                                                    items[index].image = value;
                                                    nextIntro.social_network = items;
                                                    nextContent.intro = nextIntro;
                                                    nextFooter.content = nextContent;
                                                    next.footer = nextFooter;
                                                })}
                                            />
                                        </div>
                                        <button type="button" className="is-danger" onClick={() => update((next) => {
                                            const nextFooter = record(next.footer);
                                            const nextContent = record(nextFooter.content);
                                            const nextIntro = record(nextContent.intro);
                                            nextIntro.social_network = (Array.isArray(nextIntro.social_network) ? nextIntro.social_network : []).filter((_, itemIndex) => itemIndex !== index);
                                            nextContent.intro = nextIntro;
                                            nextFooter.content = nextContent;
                                            next.footer = nextFooter;
                                        })}>删除社交链接</button>
                                    </article>
                                ))}
                                <button type="button" onClick={() => update((next) => {
                                    const nextFooter = record(next.footer);
                                    const nextContent = record(nextFooter.content);
                                    const nextIntro = record(nextContent.intro);
                                    nextIntro.social_network = [
                                        ...(Array.isArray(nextIntro.social_network) ? nextIntro.social_network : []),
                                        newSocialLink(),
                                    ];
                                    nextContent.intro = nextIntro;
                                    nextFooter.content = nextContent;
                                    next.footer = nextFooter;
                                })}>添加社交链接</button>
                            </section>
                            <section className="beike-puck-chrome-section">
                                <h3>扩展链接组</h3>
                                <p>
                                    政策与客户支持由系统自动生成；此处只添加需要的额外店铺链接。
                                </p>
                                <a className="beike-puck-chrome-link" href={policySettingsUrl}>编辑政策、联系我们与支持信息</a>
                                {footerGroups.map(
                                    ({ key, value }) => {
                                        const links = Array.isArray(value.links)
                                            ? value.links.map(record)
                                            : [];
                                        return (
                                            <article
                                                className="beike-puck-chrome-card is-footer-group"
                                                key={key}
                                            >
                                                <label>
                                                    分组标题
                                                    <input
                                                        value={textAt(
                                                            value.title,
                                                            locale,
                                                        )}
                                                        onChange={(event) =>
                                                            update((next) => {
                                                                const nextFooter =
                                                                    record(
                                                                        next.footer,
                                                                    );
                                                                const nextContent =
                                                                    record(
                                                                        nextFooter.content,
                                                                    );
                                                                const group =
                                                                    record(
                                                                        nextContent[
                                                                            key
                                                                        ],
                                                                    );
                                                                setTextAt(
                                                                    group,
                                                                    "title",
                                                                    locale,
                                                                    event.target
                                                                        .value,
                                                                );
                                                                nextContent[
                                                                    key
                                                                ] = group;
                                                                nextFooter.content =
                                                                    nextContent;
                                                                next.footer =
                                                                    nextFooter;
                                                            })
                                                        }
                                                    />
                                                </label>
                                                {links.map(
                                                    (link, linkIndex) => (
                                                        <div
                                                            className="beike-puck-chrome-nested"
                                                            key={linkIndex}
                                                        >
                                                            <label>
                                                                链接文字
                                                                <input
                                                                    value={textAt(
                                                                        link.text,
                                                                        locale,
                                                                    )}
                                                                    onChange={(
                                                                        event,
                                                                    ) =>
                                                                        update(
                                                                            (
                                                                                next,
                                                                            ) => {
                                                                                const nextFooter =
                                                                                    record(
                                                                                        next.footer,
                                                                                    );
                                                                                const nextContent =
                                                                                    record(
                                                                                        nextFooter.content,
                                                                                    );
                                                                                const group =
                                                                                    record(
                                                                                        nextContent[
                                                                                            key
                                                                                        ],
                                                                                    );
                                                                                const nextLinks =
                                                                                    Array.isArray(
                                                                                        group.links,
                                                                                    )
                                                                                        ? group.links.map(
                                                                                              record,
                                                                                          )
                                                                                        : [];
                                                                                setTextAt(
                                                                                    nextLinks[
                                                                                        linkIndex
                                                                                    ],
                                                                                    "text",
                                                                                    locale,
                                                                                    event
                                                                                        .target
                                                                                        .value,
                                                                                );
                                                                                group.links =
                                                                                    nextLinks;
                                                                                nextContent[
                                                                                    key
                                                                                ] =
                                                                                    group;
                                                                                nextFooter.content =
                                                                                    nextContent;
                                                                                next.footer =
                                                                                    nextFooter;
                                                                            },
                                                                        )
                                                                    }
                                                                />
                                                            </label>
                                                            <StructuredLinkField
                                                                field={{
                                                                    label: "链接目标",
                                                                }}
                                                                value={link}
                                                                onChange={(
                                                                    changed,
                                                                ) =>
                                                                    update(
                                                                        (
                                                                            next,
                                                                        ) => {
                                                                            const nextFooter =
                                                                                record(
                                                                                    next.footer,
                                                                                );
                                                                            const nextContent =
                                                                                record(
                                                                                    nextFooter.content,
                                                                                );
                                                                            const group =
                                                                                record(
                                                                                    nextContent[
                                                                                        key
                                                                                    ],
                                                                                );
                                                                            const nextLinks =
                                                                                Array.isArray(
                                                                                    group.links,
                                                                                )
                                                                                    ? group.links.map(
                                                                                          record,
                                                                                      )
                                                                                    : [];
                                                                            nextLinks[
                                                                                linkIndex
                                                                            ] =
                                                                                {
                                                                                    ...nextLinks[
                                                                                        linkIndex
                                                                                    ],
                                                                                    ...changed,
                                                                                    text: nextLinks[
                                                                                        linkIndex
                                                                                    ]
                                                                                        .text,
                                                                                };
                                                                            group.links =
                                                                                nextLinks;
                                                                            nextContent[
                                                                                key
                                                                            ] =
                                                                                group;
                                                                            nextFooter.content =
                                                                                nextContent;
                                                                            next.footer =
                                                                                nextFooter;
                                                                        },
                                                                    )
                                                                }
                                                            />
                                                            <button
                                                                type="button"
                                                                className="is-danger"
                                                                onClick={() =>
                                                                    update(
                                                                        (
                                                                            next,
                                                                        ) => {
                                                                            const nextFooter =
                                                                                record(
                                                                                    next.footer,
                                                                                );
                                                                            const nextContent =
                                                                                record(
                                                                                    nextFooter.content,
                                                                                );
                                                                            const group =
                                                                                record(
                                                                                    nextContent[
                                                                                        key
                                                                                    ],
                                                                                );
                                                                            group.links =
                                                                                (
                                                                                    Array.isArray(
                                                                                        group.links,
                                                                                    )
                                                                                        ? group.links
                                                                                        : []
                                                                                ).filter(
                                                                                    (
                                                                                        _,
                                                                                        index,
                                                                                    ) =>
                                                                                        index !==
                                                                                        linkIndex,
                                                                                );
                                                                            nextContent[
                                                                                key
                                                                            ] =
                                                                                group;
                                                                            nextFooter.content =
                                                                                nextContent;
                                                                            next.footer =
                                                                                nextFooter;
                                                                        },
                                                                    )
                                                                }
                                                            >
                                                                删除链接
                                                            </button>
                                                        </div>
                                                    ),
                                                )}
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        update((next) => {
                                                            const nextFooter =
                                                                record(
                                                                    next.footer,
                                                                );
                                                            const nextContent =
                                                                record(
                                                                    nextFooter.content,
                                                                );
                                                            const group =
                                                                record(
                                                                    nextContent[
                                                                        key
                                                                    ],
                                                                );
                                                            group.links = [
                                                                ...(Array.isArray(
                                                                    group.links,
                                                                )
                                                                    ? group.links
                                                                    : []),
                                                                newFooterLink(
                                                                    locale,
                                                                ),
                                                            ];
                                                            nextContent[key] =
                                                                group;
                                                            nextFooter.content =
                                                                nextContent;
                                                            next.footer =
                                                                nextFooter;
                                                        })
                                                    }
                                                >
                                                    添加链接
                                                </button>
                                            </article>
                                        );
                                    },
                                )}
                            </section>
                            <section className="beike-puck-chrome-section">
                                <h3>安全付款标识</h3>
                                <p className="beike-puck-chrome-help">
                                    仅展示已启用支付渠道可能支持且在此勾选的品牌。PayPal
                                    卡付款仍取决于商户账户、买家地区、币种与实时资格。
                                </p>
                                <label className="beike-puck-chrome-switch">
                                    <input
                                        type="checkbox"
                                        checked={
                                            bottom.payments_enable !== false
                                        }
                                        onChange={(event) =>
                                            update((next) => {
                                                const nextFooter = record(
                                                    next.footer,
                                                );
                                                nextFooter.bottom = {
                                                    ...record(
                                                        nextFooter.bottom,
                                                    ),
                                                    payments_enable:
                                                        event.target.checked,
                                                };
                                                next.footer = nextFooter;
                                            })
                                        }
                                    />
                                    显示付款方式
                                </label>
                                <div className="beike-puck-chrome-payments">
                                    {paymentOptions.map(([code, label]) => (
                                        <label key={code}>
                                            <input
                                                type="checkbox"
                                                checked={paymentMethods.includes(
                                                    code,
                                                )}
                                                onChange={(event) =>
                                                    update((next) => {
                                                        const nextFooter =
                                                            record(next.footer);
                                                        const nextBottom =
                                                            record(
                                                                nextFooter.bottom,
                                                            );
                                                        const selected =
                                                            Array.isArray(
                                                                nextBottom.payments,
                                                            )
                                                                ? nextBottom.payments.filter(
                                                                      (
                                                                          item,
                                                                      ): item is string =>
                                                                          typeof item ===
                                                                          "string",
                                                                  )
                                                                : [];
                                                        nextBottom.payments =
                                                            event.target.checked
                                                                ? Array.from(
                                                                      new Set([
                                                                          ...selected,
                                                                          code,
                                                                      ]),
                                                                  )
                                                                : selected.filter(
                                                                      (item) =>
                                                                          item !==
                                                                          code,
                                                                  );
                                                        nextFooter.bottom =
                                                            nextBottom;
                                                        next.footer =
                                                            nextFooter;
                                                    })
                                                }
                                            />
                                            {label}
                                        </label>
                                    ))}
                                </div>
                            </section>
                            <section className="beike-puck-chrome-section">
                                <h3>服务保障</h3>
                                <label className="beike-puck-chrome-switch">
                                    <input
                                        type="checkbox"
                                        checked={services.enable === true}
                                        onChange={(event) =>
                                            update((next) => {
                                                const nextFooter = record(
                                                    next.footer,
                                                );
                                                nextFooter.services = {
                                                    ...record(
                                                        nextFooter.services,
                                                    ),
                                                    enable: event.target
                                                        .checked,
                                                };
                                                next.footer = nextFooter;
                                            })
                                        }
                                    />
                                    显示服务保障
                                </label>
                                {serviceItems.map((item, index) => (
                                    <article
                                        className="beike-puck-chrome-card"
                                        key={index}
                                    >
                                        <label className="beike-puck-chrome-switch">
                                            <input
                                                type="checkbox"
                                                checked={item.show !== false}
                                                onChange={(event) =>
                                                    update((next) => {
                                                        const nextFooter =
                                                            record(next.footer);
                                                        const nextServices =
                                                            record(
                                                                nextFooter.services,
                                                            );
                                                        const items =
                                                            Array.isArray(
                                                                nextServices.items,
                                                            )
                                                                ? nextServices.items.map(
                                                                      record,
                                                                  )
                                                                : [];
                                                        items[index].show =
                                                            event.target.checked;
                                                        nextServices.items =
                                                            items;
                                                        nextFooter.services =
                                                            nextServices;
                                                        next.footer =
                                                            nextFooter;
                                                    })
                                                }
                                            />
                                            显示
                                        </label>
                                        <label>
                                            标题
                                            <input
                                                value={textAt(
                                                    item.title,
                                                    locale,
                                                )}
                                                onChange={(event) =>
                                                    update((next) => {
                                                        const nextFooter =
                                                            record(next.footer);
                                                        const nextServices =
                                                            record(
                                                                nextFooter.services,
                                                            );
                                                        const items =
                                                            Array.isArray(
                                                                nextServices.items,
                                                            )
                                                                ? nextServices.items.map(
                                                                      record,
                                                                  )
                                                                : [];
                                                        setTextAt(
                                                            items[index],
                                                            "title",
                                                            locale,
                                                            event.target.value,
                                                        );
                                                        nextServices.items =
                                                            items;
                                                        nextFooter.services =
                                                            nextServices;
                                                        next.footer =
                                                            nextFooter;
                                                    })
                                                }
                                            />
                                        </label>
                                        <label>
                                            说明
                                            <input
                                                value={textAt(
                                                    item.sub_title,
                                                    locale,
                                                )}
                                                onChange={(event) =>
                                                    update((next) => {
                                                        const nextFooter =
                                                            record(next.footer);
                                                        const nextServices =
                                                            record(
                                                                nextFooter.services,
                                                            );
                                                        const items =
                                                            Array.isArray(
                                                                nextServices.items,
                                                            )
                                                                ? nextServices.items.map(
                                                                      record,
                                                                  )
                                                                : [];
                                                        setTextAt(
                                                            items[index],
                                                            "sub_title",
                                                            locale,
                                                            event.target.value,
                                                        );
                                                        nextServices.items =
                                                            items;
                                                        nextFooter.services =
                                                            nextServices;
                                                        next.footer =
                                                            nextFooter;
                                                    })
                                                }
                                            />
                                        </label>
                                        <button
                                            type="button"
                                            className="is-danger"
                                            onClick={() =>
                                                update((next) => {
                                                    const nextFooter = record(
                                                        next.footer,
                                                    );
                                                    const nextServices = record(
                                                        nextFooter.services,
                                                    );
                                                    nextServices.items = (
                                                        Array.isArray(
                                                            nextServices.items,
                                                        )
                                                            ? nextServices.items
                                                            : []
                                                    ).filter(
                                                        (_, itemIndex) =>
                                                            itemIndex !== index,
                                                    );
                                                    nextFooter.services =
                                                        nextServices;
                                                    next.footer = nextFooter;
                                                })
                                            }
                                        >
                                            删除服务项
                                        </button>
                                    </article>
                                ))}
                                <button
                                    type="button"
                                    onClick={() =>
                                        update((next) => {
                                            const nextFooter = record(
                                                next.footer,
                                            );
                                            const nextServices = record(
                                                nextFooter.services,
                                            );
                                            nextServices.items = [
                                                ...(Array.isArray(
                                                    nextServices.items,
                                                )
                                                    ? nextServices.items
                                                    : []),
                                                newService(locale),
                                            ];
                                            nextFooter.services = nextServices;
                                            next.footer = nextFooter;
                                        })
                                    }
                                >
                                    添加服务项
                                </button>
                            </section>
                        </>
                    )}
                </div>
                <footer className="beike-puck-chrome-dialog__foot">
                    <output className={saveError ? "is-error" : ""} role={saveError ? "alert" : "status"} aria-live="polite">
                        {saveError || (saving ? "正在保存草稿…" : dirty ? "等待自动保存…" : "所有更改已保存到草稿")}
                    </output>
                    <button type="button" onClick={() => void close()}>
                        返回页面
                    </button>
                    <button className="is-primary" type="submit" disabled={saving && !dirty}>
                        {saving ? "保存中…" : saveError ? "重试保存" : "立即保存"}
                    </button>
                </footer>
            </form>
        </section>
    );
};
