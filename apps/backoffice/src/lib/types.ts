export type Permission =
  | "dashboard.view"
  | "events.view"
  | "events.manage"
  | "microsites.view"
  | "microsites.manage"
  | "applicants.view"
  | "applicants.manage"
  | "applicants.export"
  | "reports.view"
  | "users.view"
  | "users.manage"
  | "audit.view"
  | "audit.export"
  | "settings.manage";

export type Me = {
  user: {
    id: number;
    name: string;
    username: string;
    email: string;
    role: string;
    role_label: string;
    theme: string | null;
    must_change_password: boolean;
    has_two_factor: boolean;
  };
  permissions: Permission[];
  roles: { value: string; label: string; rank: number }[];
  assignable_roles: string[];
  is_developer: boolean;
  settings: {
    site_name: string;
    logo: string | null;
    favicon: string | null;
    date_format: string;
    timezone: string;
    theme: string;
    menu_position: "sidebar" | "top";
    allow_user_theme: boolean;
    idle_minutes: number;
    require_2fa: boolean;
    login_method: "username" | "email" | "either";
    web_url: string;
  };
};

export type Paged<T> = { data: T[]; meta: { total: number; page: number; per_page: number } };

export type RegistrationState = {
  state: "open" | "waitlist" | "full" | "closed" | "not_open";
  label: string;
  count: number;
  max: number | null;
  remaining: number | null;
};

export type UserRow = {
  id: number;
  name: string;
  username: string;
  email: string;
  role: string;
  role_label: string;
  status: "active" | "inactive";
  theme: string | null;
  must_change_password: boolean;
  two_factor_count: number;
  last_login_at: string | null;
  last_login_ip: string | null;
  created_at: string | null;
  online: boolean;
  is_self: boolean;
  can_manage: boolean;
};

export type EventRow = {
  id: number;
  title: string;
  slug: string;
  status: "draft" | "published";
  starts_at: string | null;
  date_text: string | null;
  venue: string | null;
  capacity_max: number | null;
  applicants_count: number;
  seated_count: number;
  waitlist_count: number;
  registration: RegistrationState;
  updated_at: string;
};

export type FormFieldRow = {
  type: string;
  label?: string;
  name?: string;
  width?: string;
  required?: boolean;
  placeholder?: string;
  help?: string;
  default?: string;
  phone_format?: string;
  nric_format?: string;
  options_source?: "custom" | "preset";
  options?: string[] | string;
  preset?: string;
  allow_other?: boolean;
  unique?: boolean;
  content?: string;
  conditional?: boolean;
  cond_field?: string;
  cond_operator?: string;
  cond_value?: string;
};

export type EventDetail = Record<string, unknown> & {
  id: number;
  title: string;
  slug: string;
  status: "draft" | "published";
  form_fields: FormFieldRow[];
  registration: RegistrationState;
  counts: { total: number; by_status: Record<string, number> };
};

export type ApplicantRow = {
  id: number;
  event_id: number;
  event_title: string | null;
  status: string;
  status_label: string;
  name: string | null;
  email: string | null;
  phone: string | null;
  source: string;
  answers: Record<string, unknown> | null;
  created_at: string;
};

export type AuditRow = {
  id: number;
  created_at: string;
  status: "success" | "error";
  category: string;
  category_label: string;
  action: string;
  user_id: number | null;
  user_name: string;
  summary: string;
  description: string | null;
  subject_type: string | null;
  subject_id: number | null;
  subject_label: string | null;
  properties: Record<string, unknown> | null;
  ip_address: string | null;
  user_agent: string | null;
  device: string | null;
};

export type Section = {
  id: string;
  layout: string;
  data: Record<string, unknown>;
  settings: Record<string, unknown>;
};

export type MicrositeDetail = {
  id: number;
  title: string;
  slug: string;
  status: "draft" | "published";
  event_id: number | null;
  sections: Section[];
  settings: Record<string, unknown>;
  updated_at: string;
};

export type MediaItem = { id: number; path: string; url: string; original_name: string; mime: string; size: number; width: number | null; height: number | null };
