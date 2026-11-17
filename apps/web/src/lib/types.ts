export type FormField = {
  name: string;
  type: string;
  label: string;
  placeholder: string;
  help: string;
  required: boolean;
  width: string;
  options: string[];
  allow_other: boolean;
  format: string;
  default: string;
  content: string;
  condition: { field: string; operator: string; value: string } | null;
};

export type PublicEvent = {
  id: number;
  title: string;
  slug: string;
  starts_at: string | null;
  ends_at: string | null;
  date_text: string | null;
  time_text: string | null;
  venue: string | null;
  venue_map_url: string | null;
  summary: string | null;
  banner_desktop: string | null;
  banner_mobile: string | null;
  accent_color: string | null;
  page_bg: string | null;
  page_layout: "split" | "single";
  show_title: boolean;
  registration: { state: string; label: string; remaining: number | null };
  closed: { title: string; html: string } | null;
  form: null | {
    intro: string | null;
    fields: FormField[];
    submit_label: string;
    waitlist_message: string | null;
    token: string;
    patterns: Record<string, Record<string, string>>;
  };
};

export type Section = { id: string; layout: string; data: Record<string, unknown>; settings: Record<string, unknown> };

export type PublicMicrosite = {
  id: number;
  title: string;
  slug: string;
  preview: boolean;
  event_id: number | null;
  sections: Section[];
  settings: Record<string, unknown>;
  events: Record<string, PublicEvent>;
};
