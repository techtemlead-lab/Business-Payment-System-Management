import { useEffect, useRef, useState } from "react";
import { router } from "@inertiajs/react";
import {
    ArrowDown,
    ArrowUp,
    Copy,
    Eye,
    Monitor,
    Plus,
    RotateCcw,
    Save,
    Smartphone,
    Tablet,
    Trash2,
    Undo2,
    Redo2,
} from "lucide-react";
import { Layout } from "@/components/Layout";
import { PublicWebsite } from "@/components/PublicWebsite";
import { Modal, Button } from "@/components/Common";
import {
    newSection,
    readPath,
    writePath,
    sectionTypes,
    websiteRequest,
    remapTranslations,
    siteStyle,
    contrastRatio,
    optimizeImage,
    type SiteDocument,
    type SiteMedia,
} from "@/lib/website";
import { ApplicationFields } from "@/components/ApplicationFields";

type Props = {
    draft: SiteDocument;
    defaults: SiteDocument;
    revision: number;
    publishedRevision: number;
    scheduledAt: string | null;
    versions: { id: number; summary: string; created_at: string }[];
    media: SiteMedia[];
};
const tabs = [
    "brand",
    "theme",
    "hero",
    "navigation",
    "sections",
    "pages",
    "media",
    "announcement",
    "contact",
    "footer",
    "application",
    "login",
    "portal",
    "seo",
    "availability",
    "languages",
    "redirects",
    "history",
];
const labels: Record<string, string> = {
    brand: "Brand & logos",
    theme: "Colors & appearance",
    hero: "Homepage hero",
    navigation: "Header & navigation",
    sections: "Homepage sections",
    pages: "Public pages",
    media: "Media library",
    announcement: "Announcement bar",
    contact: "Contact & social",
    footer: "Footer",
    application: "Dealership application",
    login: "Login appearance",
    portal: "Dealer workspace",
    seo: "Search & sharing",
    availability: "Website availability",
    languages: "Languages",
    redirects: "URL redirects",
    history: "Version history",
};
const choices: Record<string, string[]> = {
    heading_font: ["Inter", "Manrope", "system"],
    body_font: ["Inter", "Manrope", "system"],
    shadow: ["none", "subtle", "strong"],
    button_style: ["filled", "outline", "pill"],
    mode: ["light", "dark"],
    density: ["compact", "comfortable"],
    motion: ["none", "subtle", "expressive"],
    header_layout: ["spread", "center"],
    align: ["left", "center"],
    focal: ["center", "top", "bottom", "left", "right"],
    background: ["paper", "surface", "primary", "accent"],
    layout: ["grid", "stack", "split"],
    default: ["en", "bn"],
    landing: ["/dashboard", "/purchases", "/ledger", "/payouts"],
};
const imageFields = ["logo", "dark_logo", "favicon", "image", "mobile_image"];
const human = (key: string) =>
    key.replaceAll("_", " ").replace(/^./, (c) => c.toUpperCase());
function isTranslatable(path: string): boolean {
    return /^(?:brand\.tagline|hero\.(?:eyebrow|title|highlight|body|button_label|secondary_label)|(?:sections\.\d+|pages\.\d+\.sections\.\d+)\.(?:title|body|button_label|image_alt|items\.\d+\.(?:title|body|alt))|pages\.\d+\.(?:title|description)|(?:navigation|contact\.socials|footer\.links)\.\d+\.label|contact\.(?:address|hours)|footer\.text|application\.(?:title|intro|button_label|closed_message|success_message|consent|(?:labels|hints|placeholders)\.[a-z_]+|questions\.\d+\.label)|login\.(?:title|body|help_text)|seo\.(?:title|description)|availability\.(?:title|message)|announcement\.text|portal\.announcement)$/.test(
        path,
    );
}

export default function WebsiteCustomize(props: Props) {
    const [doc, setDoc] = useState(props.draft);
    const [tab, setTab] = useState("brand");
    const [focusPath, setFocusPath] = useState("");
    const [search, setSearch] = useState("");
    const [language, setLanguage] = useState("en");
    const [width, setWidth] = useState(1200);
    const [previewSpace, setPreviewSpace] = useState(600);
    const previewContainer = useRef<HTMLDivElement>(null);
    useEffect(() => {
        if (!previewContainer.current) return;
        const observer = new ResizeObserver(([entry]) =>
            setPreviewSpace(entry.contentRect.width),
        );
        observer.observe(previewContainer.current);
        return () => observer.disconnect();
    }, []);
    const [previewPage, setPreviewPage] = useState("");
    const [media, setMedia] = useState(props.media);
    const [status, setStatus] = useState("Draft saved");
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [undo, setUndo] = useState<SiteDocument[]>([]);
    const [redo, setRedo] = useState<SiteDocument[]>([]);
    const [publishOpen, setPublishOpen] = useState(false);
    const [summary, setSummary] = useState("Website customization update");
    const [publishAt, setPublishAt] = useState("");
    const [uploadAlt, setUploadAlt] = useState("");
    const [uploadFolder, setUploadFolder] = useState("General");
    const [sectionType, setSectionType] = useState("text");
    const [slug, setSlug] = useState("about");
    const current = useRef(doc);
    const revision = useRef(props.revision);
    const saved = useRef(JSON.stringify(props.draft));
    const pending = useRef<Promise<number> | null>(null);
    useEffect(() => {
        setDoc(props.draft);
        current.current = props.draft;
        revision.current = props.revision;
        saved.current = JSON.stringify(props.draft);
        setStatus("Draft saved");
    }, [props.draft, props.revision]);
    const dirty = JSON.stringify(doc) !== saved.current;
    const change = (next: SiteDocument) => {
        setUndo((values) => [
            ...values.slice(-49),
            structuredClone(current.current),
        ]);
        setRedo([]);
        current.current = next;
        setDoc(next);
        setStatus("Unsaved changes");
    };
    const set = (path: string, value: any) => {
        const next = structuredClone(current.current);
        if (
            language === "bn" &&
            typeof value === "string" &&
            isTranslatable(path)
        ) {
            next.translations = { ...next.translations, [path]: value };
        } else writePath(next, path, value);
        if (path.endsWith(".image") && value) {
            const asset = media.find((item) => item.url === value);
            const prefix = path.slice(0, -6);
            if (asset && readPath(next, `${prefix}.image_alt`) !== undefined)
                writePath(next, `${prefix}.image_alt`, asset.alt);
            else if (asset && path.includes(".items."))
                writePath(next, `${prefix}.alt`, asset.alt);
        }
        if (path === "theme.mode") {
            Object.assign(
                next.theme,
                value === "dark"
                    ? {
                          primary: "#a5b4fc",
                          accent: "#c4b5fd",
                          background: "#161a28",
                          text: "#f1f5f9",
                          surface: "#22283c",
                          muted: "#b6c2d3",
                          border: "#46516a",
                      }
                    : Object.fromEntries(
                          [
                              "primary",
                              "accent",
                              "background",
                              "text",
                              "surface",
                              "muted",
                              "border",
                          ].map((key) => [key, props.defaults.theme[key]]),
                      ),
            );
        }
        change(next);
    };
    async function save(): Promise<number> {
        if (pending.current) {
            await pending.current;
            return save();
        }
        const snapshot = JSON.stringify(current.current);
        if (snapshot === saved.current) return revision.current;
        setStatus("Saving draft…");
        setErrors({});
        const operation = websiteRequest("/website/draft", "PUT", {
            document: current.current,
            revision: revision.current,
        })
            .then((result) => {
                revision.current = result.revision;
                saved.current = snapshot;
                setStatus(
                    JSON.stringify(current.current) === snapshot
                        ? "Draft saved"
                        : "Unsaved changes",
                );
                return result.revision;
            })
            .catch((error) => {
                setStatus("Save needs attention");
                setErrors(
                    error.errors ?? {
                        request: [
                            error.message ??
                                "Unable to save. Reload and try again.",
                        ],
                    },
                );
                throw error;
            })
            .finally(() => {
                pending.current = null;
            });
        pending.current = operation;
        return operation;
    }
    useEffect(() => {
        if (!dirty || status === "Save needs attention") return;
        const timer = window.setTimeout(() => {
            void save().catch(() => {});
        }, 1500);
        return () => clearTimeout(timer);
    }, [doc, status]);
    useEffect(() => {
        const warn = (event: BeforeUnloadEvent) => {
            if (JSON.stringify(current.current) !== saved.current) {
                event.preventDefault();
                event.returnValue = "";
            }
        };
        window.addEventListener("beforeunload", warn);
        return () => window.removeEventListener("beforeunload", warn);
    }, []);
    const move = (path: string, index: number, direction: number) => {
        const next = structuredClone(current.current);
        const list = readPath(next, path);
        if (index + direction < 0 || index + direction >= list.length) return;
        [list[index], list[index + direction]] = [
            list[index + direction],
            list[index],
        ];
        next.translations = remapTranslations(next.translations, path, (old) =>
            old === index
                ? index + direction
                : old === index + direction
                  ? index
                  : old,
        );
        change(next);
    };
    const remove = (path: string, index: number) => {
        const next = structuredClone(current.current);
        readPath(next, path).splice(index, 1);
        next.translations = remapTranslations(next.translations, path, (old) =>
            old === index ? null : old > index ? old - 1 : old,
        );
        change(next);
    };
    const append = (path: string, item: unknown) => {
        const next = structuredClone(current.current);
        readPath(next, path).push(item);
        change(next);
    };
    function field(path: string, value: any) {
        const key = path.split(".").at(-1)!;
        const translated =
            language === "bn" &&
            typeof value === "string" &&
            isTranslatable(path);
        if (
            typeof value === "string" &&
            search &&
            !`${path} ${value}`.toLowerCase().includes(search.toLowerCase())
        )
            return null;
        const v = translated ? (doc.translations[path] ?? "") : value;
        const label = human(key);
        const disabled = language === "bn" && !translated;
        if (imageFields.includes(key))
            return (
                <label className="editor-field" key={path}>
                    {label}
                    <select
                        disabled={disabled}
                        value={value || ""}
                        onChange={(event) => set(path, event.target.value)}
                    >
                        <option value="">Default / no image</option>
                        {media.map((asset) => (
                            <option key={asset.key} value={asset.url}>
                                {asset.folder} / {asset.name}
                            </option>
                        ))}
                    </select>
                    {value && (
                        <img className="editor-image" src={value} alt={label} />
                    )}
                </label>
            );
        if (typeof value === "boolean")
            return (
                <label className="editor-checkbox" key={path}>
                    <input
                        type="checkbox"
                        checked={value}
                        disabled={disabled}
                        onChange={(event) => set(path, event.target.checked)}
                    />
                    {label}
                </label>
            );
        if (choices[key] || (key === "type" && path.includes("sections")))
            return (
                <label className="editor-field" key={path}>
                    {label}
                    <select
                        value={value}
                        disabled={disabled}
                        onChange={(event) => set(path, event.target.value)}
                    >
                        {(choices[key] ?? sectionTypes).map((option) => (
                            <option key={option} value={option}>
                                {human(option)}
                            </option>
                        ))}
                    </select>
                </label>
            );
        if (key === "type" && path.includes("questions"))
            return (
                <label className="editor-field" key={path}>
                    Question type
                    <select
                        value={value}
                        disabled={disabled}
                        onChange={(event) => set(path, event.target.value)}
                    >
                        {["text", "textarea", "select"].map((type) => (
                            <option key={type}>{type}</option>
                        ))}
                    </select>
                </label>
            );
        if (key === "when_field")
            return (
                <label className="editor-field" key={path}>
                    Show only when
                    <select
                        value={value || ""}
                        disabled={disabled}
                        onChange={(event) => set(path, event.target.value)}
                    >
                        <option value="">Always</option>
                        <option value="level">Dealer level</option>
                        <option value="division">Division</option>
                    </select>
                </label>
            );
        const type =
            typeof value === "number"
                ? "number"
                : [
                        "primary",
                        "accent",
                        "text",
                        "muted",
                        "border",
                        "surface",
                        "background",
                    ].includes(key) && path.startsWith("theme.")
                  ? "color"
                  : key.endsWith("_at")
                    ? "datetime-local"
                    : "text";
        return (
            <label className="editor-field" key={path}>
                {label}
                {translated && (
                    <small>
                        Bengali · English fallback: {String(value).slice(0, 90)}
                    </small>
                )}
                {[
                    "body",
                    "intro",
                    "message",
                    "description",
                    "consent",
                    "title",
                    "help_text",
                    "success_message",
                ].includes(key) ? (
                    <textarea
                        value={v ?? ""}
                        disabled={disabled}
                        rows={3}
                        onChange={(event) => set(path, event.target.value)}
                    />
                ) : (
                    <input
                        type={type}
                        value={v ?? ""}
                        disabled={disabled}
                        step={key === "line_height" ? 0.1 : 1}
                        onChange={(event) =>
                            set(
                                path,
                                type === "number"
                                    ? Number(event.target.value)
                                    : event.target.value,
                            )
                        }
                    />
                )}
            </label>
        );
    }
    function fields(object: any, prefix: string) {
        return Object.entries(object).map(([key, value]) => {
            const path = `${prefix}.${key}`;
            if (key === "id") return null;
            if (Array.isArray(value)) return list(path, value);
            if (value !== null && typeof value === "object")
                return (
                    <details className="editor-group" key={path} open>
                        <summary>{human(key)}</summary>
                        {fields(value, path)}
                    </details>
                );
            return field(path, value);
        });
    }
    function list(path: string, values: any[]) {
        const standard =
            path.endsWith("field_order") || path.endsWith("dashboard_order");
        const kind = path.split(".").at(-1);
        return (
            <div className="editor-list" key={path}>
                <h3>{human(kind ?? "")}</h3>
                {standard && (
                    <small>
                        {kind === "field_order"
                            ? "Required identity and territory fields remain enforced."
                            : "Change the order of dashboard sections without hiding financial information."}
                    </small>
                )}
                {values.map((item, index) => (
                    <div className="editor-list-item" key={`${path}-${index}`}>
                        <div className="editor-item-heading">
                            <strong>
                                {item.title ||
                                    item.label ||
                                    item.slug ||
                                    item.id ||
                                    (typeof item === "string"
                                        ? human(item)
                                        : `Item ${index + 1}`)}
                            </strong>
                            <button
                                type="button"
                                title="Move up"
                                aria-label={`Move ${index + 1} up`}
                                onClick={() => move(path, index, -1)}
                                disabled={index === 0 || language === "bn"}
                            >
                                <ArrowUp size={15} />
                            </button>
                            <button
                                type="button"
                                title="Move down"
                                aria-label={`Move ${index + 1} down`}
                                onClick={() => move(path, index, 1)}
                                disabled={
                                    index === values.length - 1 ||
                                    language === "bn"
                                }
                            >
                                <ArrowDown size={15} />
                            </button>
                            {!standard && (
                                <button
                                    type="button"
                                    aria-label={`Remove item ${index + 1}`}
                                    disabled={language === "bn"}
                                    onClick={() => remove(path, index)}
                                >
                                    <Trash2 size={15} />
                                </button>
                            )}
                            {kind === "sections" && (
                                <button
                                    type="button"
                                    aria-label="Duplicate section"
                                    disabled={language === "bn"}
                                    onClick={() =>
                                        append(path, {
                                            ...structuredClone(item),
                                            id: `section-${crypto.randomUUID()}`,
                                        })
                                    }
                                >
                                    <Copy size={15} />
                                </button>
                            )}
                        </div>
                        {!standard && (
                            <details
                                open={
                                    values.length < 3 ||
                                    focusPath === `${path}.${index}`
                                }
                            >
                                <summary>Edit {item.type ?? "details"}</summary>
                                {typeof item === "string"
                                    ? field(`${path}.${index}`, item)
                                    : fields(item, `${path}.${index}`)}
                            </details>
                        )}
                    </div>
                ))}
                {!standard && language === "en" && (
                    <button
                        type="button"
                        className="editor-add"
                        onClick={() =>
                            append(
                                path,
                                kind === "sections"
                                    ? newSection(sectionType)
                                    : kind === "items"
                                      ? {
                                            title: "New item",
                                            body: "",
                                            image: "",
                                            href: "",
                                            alt: "",
                                        }
                                      : kind === "options"
                                        ? "New option"
                                        : kind === "questions"
                                          ? {
                                                id: `question_${Date.now()}`,
                                                label: "New question",
                                                type: "text",
                                                required: false,
                                                options: [],
                                                when_field: "",
                                                when_value: "",
                                            }
                                          : kind === "redirects"
                                            ? {
                                                  from: "old-page",
                                                  to: "/pages/about",
                                              }
                                            : {
                                                  label: "New link",
                                                  href: "/",
                                                  group: "",
                                              },
                            )
                        }
                    >
                        <Plus size={15} /> Add{" "}
                        {kind === "sections"
                            ? sectionType
                            : human(kind ?? "item")}
                    </button>
                )}
            </div>
        );
    }
    const selected = focusPath && readPath(doc, focusPath);
    async function upload(file: File) {
        setStatus("Uploading image…");
        try {
            if (file.size > 20 * 1024 * 1024)
                throw {
                    errors: {
                        upload: ["Choose a source image smaller than 20 MB."],
                    },
                };
            const data = new FormData();
            data.append("file", await optimizeImage(file));
            data.append("alt", uploadAlt);
            data.append("folder", uploadFolder);
            const result = await websiteRequest("/website/media", "POST", data);
            setMedia(result.media);
            setStatus("Image added to library");
        } catch (error: any) {
            setErrors(error.errors ?? { upload: ["Upload failed."] });
            setStatus("Upload needs attention");
        }
    }
    return (
        <Layout title="Website customization">
            <div className="website-editor">
                <div className="editor-top">
                    <div>
                        <span className="editorial-label">
                            YOUR WEBSITE, YOUR WAY
                        </span>
                        <h1>
                            Website studio
                            <span>Published v{props.publishedRevision}</span>
                        </h1>
                        <p role="status">
                            {status}
                            {dirty ? " · Live website unchanged" : ""}
                        </p>
                    </div>
                    <div className="editor-actions">
                        <button
                            aria-label="Undo"
                            disabled={!undo.length}
                            onClick={() => {
                                const previous = undo.at(-1)!;
                                setRedo((values) => [...values, doc]);
                                setUndo((values) => values.slice(0, -1));
                                current.current = previous;
                                setDoc(previous);
                                setStatus("Unsaved changes");
                            }}
                        >
                            <Undo2 size={18} />
                        </button>
                        <button
                            aria-label="Redo"
                            disabled={!redo.length}
                            onClick={() => {
                                const next = redo.at(-1)!;
                                setUndo((values) => [...values, doc]);
                                setRedo((values) => values.slice(0, -1));
                                current.current = next;
                                setDoc(next);
                                setStatus("Unsaved changes");
                            }}
                        >
                            <Redo2 size={18} />
                        </button>
                        <Button
                            variant="outline"
                            onClick={() => void save().catch(() => {})}
                        >
                            <Save size={16} /> Save draft
                        </Button>
                        <Button
                            variant="outline"
                            onClick={() => {
                                void save()
                                    .then(() =>
                                        window.open(
                                            "/website/preview" +
                                                (previewPage
                                                    ? `?page=${previewPage}`
                                                    : ""),
                                            "_blank",
                                            "noopener",
                                        ),
                                    )
                                    .catch(() => {});
                            }}
                        >
                            <Eye size={16} /> Preview
                        </Button>
                        <Button onClick={() => setPublishOpen(true)}>
                            Publish
                        </Button>
                    </div>
                </div>
                {Object.keys(errors).length > 0 && (
                    <div className="editor-errors" role="alert">
                        <strong>Please correct these settings</strong>
                        {Object.entries(errors).map(([key, value]) => (
                            <p key={key}>
                                {key.replace("document.", "")}:{" "}
                                {Array.isArray(value) ? value.join(" ") : value}
                            </p>
                        ))}
                    </div>
                )}
                {props.scheduledAt && (
                    <div className="editor-notice">
                        Scheduled:{" "}
                        {new Date(props.scheduledAt).toLocaleString("en-GB", {
                            timeZone: "Asia/Dhaka",
                        })}{" "}
                        Dhaka time. Further draft edits do not change that
                        snapshot.{" "}
                        <button
                            onClick={() => router.delete("/website/schedule")}
                        >
                            Cancel schedule
                        </button>
                    </div>
                )}
                <div className="editor-workspace">
                    <aside className="editor-navigation">
                        <input
                            aria-label="Search customization settings"
                            placeholder="Find a setting…"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                        />
                        {tabs.map((name) => (
                            <button
                                key={name}
                                aria-current={tab === name ? "page" : undefined}
                                onClick={() => {
                                    setTab(name);
                                    setFocusPath("");
                                }}
                            >
                                {labels[name]}
                            </button>
                        ))}
                    </aside>
                    <section
                        className="editor-controls"
                        aria-label="Customization settings"
                    >
                        <div className="editor-controls-title">
                            <h2>{labels[tab]}</h2>
                            <select
                                aria-label="Editing language"
                                value={language}
                                onChange={(event) =>
                                    setLanguage(event.target.value)
                                }
                            >
                                <option value="en">English</option>
                                <option value="bn">বাংলা</option>
                            </select>
                        </div>
                        {language === "bn" && (
                            <p className="editor-hint">
                                Edit Bengali display text. Empty translations
                                use English. Translations stay with their
                                sections when reordered.
                            </p>
                        )}
                        {tab === "theme" && (
                            <div className="editor-presets">
                                {[
                                    {
                                        name: "Forest",
                                        primary: "#183c30",
                                        accent: "#d7ee9b",
                                        background: "#f7f6f0",
                                        text: "#253d31",
                                        surface: "#ffffff",
                                        muted: "#68746b",
                                        border: "#e2e5dc",
                                    },
                                    {
                                        name: "Ocean",
                                        primary: "#164e63",
                                        accent: "#a5f3fc",
                                        background: "#f0f9ff",
                                        text: "#164e63",
                                        surface: "#ffffff",
                                        muted: "#526b76",
                                        border: "#cbdfe9",
                                    },
                                    {
                                        name: "Midnight",
                                        primary: "#a5b4fc",
                                        accent: "#c4b5fd",
                                        background: "#161a28",
                                        text: "#f1f5f9",
                                        surface: "#22283c",
                                        muted: "#b6c2d3",
                                        border: "#46516a",
                                    },
                                ].map((palette) => (
                                    <button
                                        key={palette.name}
                                        onClick={() => {
                                            const next = structuredClone(doc);
                                            const { name, ...colors } = palette;
                                            next.theme = {
                                                ...next.theme,
                                                ...colors,
                                                mode:
                                                    name === "Midnight"
                                                        ? "dark"
                                                        : "light",
                                            };
                                            change(next);
                                        }}
                                    >
                                        {palette.name}
                                    </button>
                                ))}
                            </div>
                        )}
                        {tab === "theme" && (
                            <p className="editor-hint">
                                Text contrast:{" "}
                                {contrastRatio(
                                    doc.theme.text,
                                    doc.theme.background,
                                ).toFixed(1)}
                                :1.{" "}
                                {contrastRatio(
                                    doc.theme.text,
                                    doc.theme.background,
                                ) < 4.5
                                    ? "Choose darker text or a lighter background for readable body text."
                                    : "Good contrast for body text."}
                            </p>
                        )}
                        {[
                            "brand",
                            "theme",
                            "hero",
                            "announcement",
                            "contact",
                            "footer",
                            "application",
                            "login",
                            "portal",
                            "seo",
                            "availability",
                            "languages",
                        ].includes(tab) && fields(readPath(doc, tab), tab)}
                        {tab === "languages" && (
                            <p className="editor-hint">
                                Choose বাংলা above, then open a content panel to
                                translate its text. Fonts use the operating
                                system’s Bengali fallback.
                            </p>
                        )}
                        {tab === "navigation" &&
                            list("navigation", doc.navigation)}
                        {tab === "redirects" && (
                            <>
                                <p className="editor-hint">
                                    Redirect old /pages/ addresses to a new
                                    local or HTTPS URL.
                                </p>
                                {list("redirects", doc.redirects)}
                            </>
                        )}
                        {(tab === "sections" || tab === "pages") && (
                            <label className="editor-field">
                                Section library
                                <select
                                    value={sectionType}
                                    onChange={(event) =>
                                        setSectionType(event.target.value)
                                    }
                                >
                                    {sectionTypes.map((type) => (
                                        <option key={type}>{type}</option>
                                    ))}
                                </select>
                            </label>
                        )}
                        {tab === "sections" &&
                            (selected && focusPath.startsWith("sections.") ? (
                                <>
                                    <button onClick={() => setFocusPath("")}>
                                        ← All sections
                                    </button>
                                    {fields(selected, focusPath)}
                                </>
                            ) : (
                                list("sections", doc.sections)
                            ))}
                        {tab === "pages" && (
                            <>
                                <label className="editor-field">
                                    New page URL
                                    <input
                                        value={slug}
                                        onChange={(event) =>
                                            setSlug(event.target.value)
                                        }
                                        placeholder="about"
                                    />
                                </label>
                                <button
                                    className="editor-add"
                                    onClick={() => {
                                        append("pages", {
                                            slug,
                                            title: human(slug),
                                            description: "",
                                            visible: false,
                                            index: true,
                                            sections: [newSection()],
                                        });
                                        setPreviewPage(slug);
                                    }}
                                >
                                    Add draft page
                                </button>
                                {doc.pages.map((page, index) => (
                                    <details
                                        className="editor-group"
                                        key={index}
                                        open={previewPage === page.slug}
                                    >
                                        <summary
                                            onClick={() =>
                                                setPreviewPage(page.slug)
                                            }
                                        >
                                            {page.title} · /pages/{page.slug}
                                        </summary>
                                        {fields(page, `pages.${index}`)}
                                        <button
                                            onClick={() =>
                                                remove("pages", index)
                                            }
                                        >
                                            Remove page
                                        </button>
                                        <button
                                            onClick={() => {
                                                append("navigation", {
                                                    label: page.title,
                                                    href: `/pages/${page.slug}`,
                                                    group: "",
                                                });
                                                setTab("navigation");
                                            }}
                                        >
                                            Add to menu
                                        </button>
                                    </details>
                                ))}
                            </>
                        )}
                        {tab === "media" && (
                            <>
                                <p className="editor-hint">
                                    Images: JPG, PNG, WebP, up to 4 MB and 5000
                                    × 5000 pixels. Uploaded assets are reusable
                                    across the website.
                                </p>
                                <label className="editor-field">
                                    Image description
                                    <input
                                        value={uploadAlt}
                                        onChange={(event) =>
                                            setUploadAlt(event.target.value)
                                        }
                                    />
                                </label>
                                <label className="editor-field">
                                    Folder
                                    <input
                                        value={uploadFolder}
                                        onChange={(event) =>
                                            setUploadFolder(event.target.value)
                                        }
                                    />
                                </label>
                                <label className="editor-upload">
                                    Upload image
                                    <input
                                        type="file"
                                        accept="image/jpeg,image/png,image/webp"
                                        onChange={(event) => {
                                            if (event.target.files?.[0])
                                                void upload(
                                                    event.target.files[0],
                                                );
                                            event.target.value = "";
                                        }}
                                    />
                                </label>
                                {media.map((asset) => (
                                    <div
                                        className="editor-media"
                                        key={asset.key}
                                    >
                                        <img src={asset.url} alt={asset.alt} />
                                        <strong>{asset.name}</strong>
                                        <small>
                                            {asset.folder} ·{" "}
                                            {Math.round(asset.size / 1024)} KB ·
                                            Used{" "}
                                            {JSON.stringify(doc).split(
                                                asset.url,
                                            ).length - 1}{" "}
                                            times in draft
                                        </small>
                                        <label>
                                            Alt text
                                            <input
                                                value={asset.alt}
                                                onChange={(event) =>
                                                    setMedia((values) =>
                                                        values.map((item) =>
                                                            item.key ===
                                                            asset.key
                                                                ? {
                                                                      ...item,
                                                                      alt: event
                                                                          .target
                                                                          .value,
                                                                  }
                                                                : item,
                                                        ),
                                                    )
                                                }
                                            />
                                        </label>
                                        <label>
                                            Folder
                                            <input
                                                value={asset.folder}
                                                onChange={(event) =>
                                                    setMedia((values) =>
                                                        values.map((item) =>
                                                            item.key ===
                                                            asset.key
                                                                ? {
                                                                      ...item,
                                                                      folder: event
                                                                          .target
                                                                          .value,
                                                                  }
                                                                : item,
                                                        ),
                                                    )
                                                }
                                            />
                                        </label>
                                        <button
                                            onClick={() =>
                                                void websiteRequest(
                                                    `/website/media/${asset.key}`,
                                                    "PATCH",
                                                    {
                                                        name: asset.name,
                                                        alt: asset.alt,
                                                        folder: asset.folder,
                                                    },
                                                )
                                                    .then((result) =>
                                                        setMedia(result.media),
                                                    )
                                                    .catch((error) =>
                                                        setErrors(
                                                            error.errors ?? {
                                                                media: [
                                                                    "Update failed.",
                                                                ],
                                                            },
                                                        ),
                                                    )
                                            }
                                        >
                                            Save asset details
                                        </button>
                                    </div>
                                ))}
                            </>
                        )}
                        {tab === "history" && (
                            <>
                                <p className="editor-hint">
                                    Restore creates a draft. The live website
                                    changes only when you publish.
                                </p>
                                {props.versions.map((version) => (
                                    <div
                                        className="editor-version"
                                        key={version.id}
                                    >
                                        <strong>{version.summary}</strong>
                                        <small>
                                            {new Date(
                                                version.created_at,
                                            ).toLocaleString()}
                                        </small>
                                        <button
                                            onClick={() =>
                                                router.post(
                                                    `/website/restore/${version.id}`,
                                                    {
                                                        revision:
                                                            revision.current,
                                                    },
                                                )
                                            }
                                        >
                                            <RotateCcw size={15} /> Restore to
                                            draft
                                        </button>
                                    </div>
                                ))}
                            </>
                        )}
                        {![
                            "pages",
                            "sections",
                            "media",
                            "history",
                            "navigation",
                            "redirects",
                        ].includes(tab) && (
                            <button
                                className="editor-reset"
                                onClick={() => {
                                    const next = structuredClone(doc);
                                    writePath(
                                        next,
                                        tab,
                                        structuredClone(
                                            readPath(props.defaults, tab),
                                        ),
                                    );
                                    change(next);
                                }}
                            >
                                Reset this group to defaults
                            </button>
                        )}
                    </section>
                    <section
                        className="editor-preview-panel"
                        aria-label="Website preview"
                    >
                        <div className="editor-preview-toolbar">
                            <select
                                aria-label="Preview page"
                                value={previewPage}
                                onChange={(event) =>
                                    setPreviewPage(event.target.value)
                                }
                            >
                                <option value="">Homepage</option>
                                <option value="@application">
                                    Dealership application
                                </option>
                                <option value="@login">Login</option>
                                {doc.pages.map((page) => (
                                    <option key={page.slug} value={page.slug}>
                                        {page.title}
                                    </option>
                                ))}
                            </select>
                            {[
                                [1200, Monitor],
                                [768, Tablet],
                                [390, Smartphone],
                            ].map(([size, Icon]: any) => (
                                <button
                                    key={size}
                                    aria-label={`${size} pixel preview`}
                                    aria-pressed={width === size}
                                    onClick={() => setWidth(size)}
                                >
                                    <Icon size={18} />
                                </button>
                            ))}
                        </div>
                        <div
                            className="editor-preview-scroll"
                            ref={previewContainer}
                        >
                            <div
                                className="editor-preview-device"
                                style={{
                                    width,
                                    maxWidth: "none",
                                    zoom: Math.min(1, previewSpace / width),
                                }}
                                onClickCapture={(event) => {
                                    if (
                                        (event.target as HTMLElement).closest(
                                            "a,button,input,select,textarea",
                                        )
                                    )
                                        event.preventDefault();
                                }}
                            >
                                {previewPage === "@application" ? (
                                    <div
                                        className="site-root application-preview"
                                        style={siteStyle(doc)}
                                    >
                                        <h1>{doc.application.title}</h1>
                                        <p>{doc.application.intro}</p>
                                        <ApplicationFields
                                            document={doc}
                                            preview
                                        />
                                        <button className="site-button">
                                            {doc.application.button_label}
                                        </button>
                                    </div>
                                ) : previewPage === "@login" ? (
                                    <div
                                        className="site-root application-preview"
                                        style={siteStyle(doc)}
                                    >
                                        <h1>{doc.login.title}</h1>
                                        <p>{doc.login.body}</p>
                                        {doc.login.image && (
                                            <img src={doc.login.image} alt="" />
                                        )}
                                        <label>
                                            Email address
                                            <input disabled />
                                        </label>
                                        <label>
                                            Password
                                            <input disabled />
                                        </label>
                                        <button className="site-button">
                                            Sign in
                                        </button>
                                        <p>{doc.login.help_text}</p>
                                    </div>
                                ) : (
                                    <PublicWebsite
                                        document={doc}
                                        slug={previewPage}
                                        preview
                                        language={language}
                                        onSelect={(path) => {
                                            setTab(path.split(".")[0]);
                                            setFocusPath(path);
                                        }}
                                    />
                                )}
                            </div>
                        </div>
                        <p className="editor-hint">
                            Click a website section to edit it. Preview links
                            and submission controls are disabled.
                        </p>
                    </section>
                </div>
                <Modal
                    open={publishOpen}
                    onOpenChange={setPublishOpen}
                    title="Publish website"
                    description="Publish the saved changes now or schedule a snapshot."
                >
                    <label className="editor-field">
                        Change summary
                        <input
                            value={summary}
                            maxLength={250}
                            onChange={(event) => setSummary(event.target.value)}
                        />
                    </label>
                    <label className="editor-field">
                        Schedule (optional, local time)
                        <input
                            type="datetime-local"
                            value={publishAt}
                            onChange={(event) =>
                                setPublishAt(event.target.value)
                            }
                        />
                    </label>
                    <Button
                        onClick={() =>
                            void save()
                                .then((rev) => {
                                    router.post(
                                        "/website/publish",
                                        {
                                            revision: rev,
                                            summary,
                                            publish_at: publishAt
                                                ? new Date(
                                                      publishAt,
                                                  ).toISOString()
                                                : null,
                                        },
                                        {
                                            onSuccess: () =>
                                                setPublishOpen(false),
                                            onError: (errors) =>
                                                setErrors(
                                                    Object.fromEntries(
                                                        Object.entries(
                                                            errors,
                                                        ).map(
                                                            ([key, value]) => [
                                                                key,
                                                                [value],
                                                            ],
                                                        ),
                                                    ),
                                                ),
                                        },
                                    );
                                })
                                .catch(() => {})
                        }
                    >
                        {publishAt ? "Schedule publication" : "Publish now"}
                    </Button>
                </Modal>
            </div>
        </Layout>
    );
}
