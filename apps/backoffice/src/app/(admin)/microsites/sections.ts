/**
 * Page-builder section types (same set as the WordPress build). Each section has its own content fields
 * plus the shared "Section appearance" fields (background, text colour, spacing, width, anchor, menu label, hide).
 */
export type FieldDef =
  | { key: string; label: string; type: "text" | "textarea" | "richtext" | "image" | "images" | "video" | "switch" | "number" | "color" | "datetime" | "event" | "code"; help?: string; showIf?: (data: Record<string, unknown>) => boolean }
  | { key: string; label: string; type: "select" | "radio"; options: { value: string; label: string }[]; fallback?: string; help?: string; showIf?: (data: Record<string, unknown>) => boolean }
  | { key: string; label: string; type: "repeater"; itemLabel: string; fields: FieldDef[]; help?: string; showIf?: (data: Record<string, unknown>) => boolean };

const opt = (...pairs: [string, string][]) => pairs.map(([value, label]) => ({ value, label }));

const HEADING: FieldDef[] = [
  { key: "eyebrow", label: "Small label above the heading", type: "text" },
  { key: "heading", label: "Heading", type: "text" },
  { key: "align", label: "Heading alignment", type: "radio", options: opt(["center", "Centre"], ["left", "Left"]) },
];

export const BUTTON_FIELDS: FieldDef[] = [
  { key: "label", label: "Label", type: "text" },
  { key: "action", label: "When clicked", type: "select", options: opt(["event", "Open an event's registration page"], ["anchor", "Scroll to a section (#anchor)"], ["url", "Open a web address"], ["modal", "Show a pop-up with links"]) },
  { key: "event_id", label: "Event", type: "event", showIf: (d) => d.action === "event" },
  { key: "url", label: "Address", type: "text", help: "https://… or #section-anchor", showIf: (d) => d.action === "url" || d.action === "anchor" },
  { key: "new_tab", label: "Open in a new tab", type: "switch", showIf: (d) => d.action === "url" },
  { key: "modal_title", label: "Pop-up title", type: "text", showIf: (d) => d.action === "modal" },
  { key: "modal_links", label: "Pop-up links", type: "repeater", itemLabel: "Link", showIf: (d) => d.action === "modal", fields: [
    { key: "label", label: "Label", type: "text" },
    { key: "url", label: "Address", type: "text" },
  ] },
  { key: "style", label: "Style", type: "radio", options: opt(["solid", "Solid"], ["outline", "Outline"], ["light", "Light"]) },
  { key: "colors", label: "Colours", type: "radio", fallback: "default", options: opt(["default", "Site default"], ["custom", "Custom for this button"]), help: "Site default comes from Settings → Button colours." },
  { key: "bg_color", label: "Button colour", type: "color", showIf: (d) => d.colors === "custom", help: "Fill of Solid, border and text of Outline." },
  { key: "text_color", label: "Text colour", type: "color", showIf: (d) => d.colors === "custom", help: "Text of Solid, fill of Light." },
];

export const SECTION_TYPES: Record<string, { label: string; description: string; fields: FieldDef[]; defaults: { data: Record<string, unknown>; settings: Record<string, unknown> } }> = {
  banner: {
    label: "Introduction banner",
    description: "Big title area with date, venue, countdown and buttons.",
    fields: [
      { key: "style", label: "Banner style", type: "radio", options: opt(["center", "Centred"], ["split", "Text + key visual"], ["card", "Card"]) },
      { key: "side_image", label: "Key visual", type: "image", showIf: (d) => d.style === "split" },
      { key: "eyebrow", label: "Small text above the title", type: "text" },
      { key: "title", label: "Title", type: "text" },
      { key: "logo", label: "Logo", type: "image" },
      { key: "description", label: "Description", type: "richtext" },
      { key: "show_meta", label: "Show date / time / venue", type: "switch" },
      { key: "date", label: "Date", type: "text", showIf: (d) => !!d.show_meta, help: "Empty: taken from the linked event." },
      { key: "time", label: "Time", type: "text", showIf: (d) => !!d.show_meta },
      { key: "venue", label: "Venue", type: "text", showIf: (d) => !!d.show_meta },
      { key: "countdown", label: "Show a countdown", type: "switch" },
      { key: "countdown_to", label: "Count down to", type: "datetime", showIf: (d) => !!d.countdown },
      { key: "height", label: "Height", type: "radio", options: opt(["auto", "Fit content"], ["md", "Medium"], ["full", "Full screen"]) },
      { key: "text_align", label: "Text alignment", type: "radio", options: opt(["center", "Centre"], ["left", "Left"]) },
      { key: "buttons", label: "Buttons", type: "repeater", itemLabel: "Button", fields: BUTTON_FIELDS },
    ],
    defaults: { data: { style: "center", show_meta: true, height: "md", text_align: "center", buttons: [] }, settings: { bg_type: "color", bg_color: "#7a0f3d", text_scheme: "light", padding: "lg" } },
  },
  text: {
    label: "Text",
    description: "Heading and formatted text, in one or two columns.",
    fields: [...HEADING, { key: "content", label: "Content", type: "richtext" }, { key: "columns", label: "Columns", type: "radio", options: opt(["1", "One"], ["2", "Two"]) }],
    defaults: { data: { align: "center", columns: "1" }, settings: { bg_type: "none", padding: "md" } },
  },
  image_text: {
    label: "Image + text",
    description: "An image beside text, with optional buttons.",
    fields: [
      ...HEADING,
      { key: "image", label: "Image", type: "image" },
      { key: "content", label: "Text", type: "richtext" },
      { key: "image_position", label: "Image position", type: "radio", options: opt(["left", "Left"], ["right", "Right"]) },
      { key: "image_ratio", label: "Image width", type: "radio", options: opt(["40", "40%"], ["50", "50%"]) },
      { key: "buttons", label: "Buttons", type: "repeater", itemLabel: "Button", fields: BUTTON_FIELDS },
    ],
    defaults: { data: { align: "left", image_position: "left", image_ratio: "50", buttons: [] }, settings: { bg_type: "none", padding: "md" } },
  },
  image: {
    label: "Image",
    description: "One full-width image, with optional link and caption.",
    fields: [
      { key: "image", label: "Image", type: "image" },
      { key: "image_mobile", label: "Mobile image (optional)", type: "image" },
      { key: "link", label: "Link", type: "text" },
      { key: "caption", label: "Caption", type: "text" },
    ],
    defaults: { data: {}, settings: { bg_type: "none", padding: "md" } },
  },
  gallery: {
    label: "Image gallery",
    description: "Grid, masonry or sliding carousel of photos.",
    fields: [
      ...HEADING,
      { key: "intro", label: "Intro", type: "richtext" },
      { key: "images", label: "Images", type: "images" },
      { key: "style", label: "Style", type: "radio", options: opt(["grid", "Grid"], ["masonry", "Masonry"], ["carousel", "Carousel"]) },
      { key: "columns", label: "Columns", type: "radio", options: opt(["2", "2"], ["3", "3"], ["4", "4"], ["5", "5"]) },
    ],
    defaults: { data: { align: "center", style: "grid", columns: "3", images: [] }, settings: { bg_type: "none", padding: "md" } },
  },
  speakers: {
    label: "Speakers / people",
    description: "Photos, names, titles and bios.",
    fields: [
      ...HEADING,
      { key: "intro", label: "Intro", type: "richtext" },
      { key: "style", label: "Style", type: "radio", options: opt(["cards", "Cards"], ["list", "List"]) },
      { key: "items", label: "People", type: "repeater", itemLabel: "Person", fields: [
        { key: "photo", label: "Photo", type: "image" },
        { key: "name", label: "Name", type: "text" },
        { key: "role", label: "Title / organisation", type: "text" },
        { key: "bio", label: "Bio", type: "richtext" },
      ] },
    ],
    defaults: { data: { align: "center", style: "cards", items: [] }, settings: { bg_type: "none", padding: "md" } },
  },
  agenda: {
    label: "Programme / agenda",
    description: "Time-table of the day.",
    fields: [
      ...HEADING,
      { key: "intro", label: "Intro", type: "richtext" },
      { key: "items", label: "Items", type: "repeater", itemLabel: "Item", fields: [
        { key: "time", label: "Time", type: "text" },
        { key: "title", label: "Title", type: "text" },
        { key: "description", label: "Details / speaker", type: "textarea" },
      ] },
    ],
    defaults: { data: { align: "center", items: [] }, settings: { bg_type: "none", padding: "md", container: "narrow" } },
  },
  cards: {
    label: "Cards",
    description: "Info boxes: hotels, venues, highlights…",
    fields: [
      ...HEADING,
      { key: "intro", label: "Intro", type: "richtext" },
      { key: "columns", label: "Columns", type: "radio", options: opt(["1", "1"], ["2", "2"], ["3", "3"], ["4", "4"]) },
      { key: "items", label: "Cards", type: "repeater", itemLabel: "Card", fields: [
        { key: "image", label: "Image", type: "image" },
        { key: "title", label: "Title", type: "text" },
        { key: "content", label: "Content", type: "richtext" },
        { key: "link_label", label: "Link label", type: "text" },
        { key: "link_url", label: "Link address", type: "text" },
      ] },
    ],
    defaults: { data: { align: "center", columns: "3", items: [] }, settings: { bg_type: "none", padding: "md" } },
  },
  logos: {
    label: "Logos / sponsors",
    description: "A row of partner logos.",
    fields: [...HEADING, { key: "images", label: "Logos", type: "images" }],
    defaults: { data: { align: "center", images: [] }, settings: { bg_type: "none", padding: "md" } },
  },
  video: {
    label: "Video",
    description: "YouTube or Vimeo video.",
    fields: [...HEADING, { key: "intro", label: "Intro", type: "richtext" }, { key: "embed", label: "Video address (YouTube or Vimeo)", type: "text" }],
    defaults: { data: { align: "center" }, settings: { bg_type: "none", padding: "md", container: "narrow" } },
  },
  contact: {
    label: "Contact persons",
    description: "WhatsApp, phone, email or link buttons.",
    fields: [
      ...HEADING,
      { key: "intro", label: "Intro", type: "richtext" },
      { key: "items", label: "Contacts", type: "repeater", itemLabel: "Contact", fields: [
        { key: "type", label: "Type", type: "select", options: opt(["whatsapp", "WhatsApp"], ["phone", "Phone"], ["email", "Email"], ["link", "Link"]) },
        { key: "name", label: "Name / label", type: "text" },
        { key: "value", label: "Number / email / address", type: "text" },
      ] },
    ],
    defaults: { data: { align: "center", items: [] }, settings: { bg_type: "none", padding: "md" } },
  },
  form: {
    label: "Registration form",
    description: "An event's registration form, inside the page.",
    fields: [...HEADING, { key: "intro", label: "Intro", type: "richtext" }, { key: "event_id", label: "Event", type: "event", help: "Empty: the microsite's linked event." }],
    defaults: { data: { align: "center", heading: "Register" }, settings: { bg_type: "color", bg_color: "#f7f3ef", padding: "lg", container: "narrow", anchor: "register", nav_label: "Register" } },
  },
  html: {
    label: "Custom HTML / embed",
    description: "Paste embed code (maps, forms, widgets). Only for trusted code.",
    fields: [{ key: "code", label: "HTML", type: "code" }],
    defaults: { data: {}, settings: { bg_type: "none", padding: "md" } },
  },
};

export const APPEARANCE_FIELDS: FieldDef[] = [
  { key: "bg_type", label: "Background", type: "radio", options: opt(["none", "None"], ["color", "Colour"], ["gradient", "Gradient"], ["image", "Image"], ["video", "Video"]) },
  { key: "bg_color", label: "Colour", type: "color", showIf: (s) => s.bg_type === "color" || s.bg_type === "gradient" },
  { key: "bg_color2", label: "Gradient to", type: "color", showIf: (s) => s.bg_type === "gradient" },
  { key: "bg_angle", label: "Gradient angle (°)", type: "number", showIf: (s) => s.bg_type === "gradient" },
  { key: "bg_image", label: "Image", type: "image", showIf: (s) => s.bg_type === "image" },
  { key: "bg_image_mobile", label: "Mobile image (optional)", type: "image", showIf: (s) => s.bg_type === "image" },
  { key: "bg_fit", label: "Image fit", type: "select", options: opt(["cover", "Cover"], ["contain", "Contain"], ["repeat", "Repeat"], ["fixed", "Fixed (parallax)"]), showIf: (s) => s.bg_type === "image" },
  { key: "bg_video", label: "Video (MP4)", type: "video", showIf: (s) => s.bg_type === "video" },
  { key: "overlay", label: "Overlay colour", type: "color", showIf: (s) => s.bg_type === "image" || s.bg_type === "video" },
  { key: "text_scheme", label: "Text colour", type: "radio", options: opt(["auto", "Automatic"], ["light", "Light"], ["dark", "Dark"], ["custom", "Custom"]) },
  { key: "text_color", label: "Custom text colour", type: "color", showIf: (s) => s.text_scheme === "custom" },
  { key: "padding", label: "Spacing", type: "radio", options: opt(["none", "None"], ["sm", "Small"], ["md", "Medium"], ["lg", "Large"]) },
  { key: "container", label: "Content width", type: "radio", options: opt(["narrow", "Narrow"], ["default", "Normal"], ["wide", "Wide"], ["full", "Full"]) },
  { key: "anchor", label: "Anchor ID", type: "text", help: "Lets buttons scroll here with #anchor." },
  { key: "nav_label", label: "Top-menu label", type: "text", help: "Adds this section to the microsite's top menu." },
  { key: "hidden", label: "Hide this section", type: "switch" },
];
