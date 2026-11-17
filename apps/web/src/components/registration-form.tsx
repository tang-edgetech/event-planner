"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import type { FormField, PublicEvent } from "@/lib/types";

const OTHER = "__other";
type Values = Record<string, string | string[]>;

/** Whether a field's "show only when…" condition is met by the current answers. */
function visible(f: FormField, values: Values, fields: FormField[]): boolean {
  const c = f.condition;
  if (!c?.field) return true;
  const source = fields.find((x) => x.name === c.field);
  if (source && !visible(source, values, fields)) return c.operator === "empty" || c.operator === "is_not";
  const raw = values[c.field];
  const list = (Array.isArray(raw) ? raw : raw ? [raw] : []).map((v) => String(v).toLowerCase().trim());
  const target = (c.value ?? "").toLowerCase().trim();
  const hit = list.includes(target);
  switch (c.operator) {
    case "is_not":
      return !hit;
    case "not_empty":
      return list.length > 0;
    case "empty":
      return list.length === 0;
    case "contains":
      return target !== "" && list.join(" ").includes(target);
    default:
      return hit;
  }
}

const WIDTH: Record<string, string> = { "100": "sm:col-span-12", "66": "sm:col-span-8", "50": "sm:col-span-6", "33": "sm:col-span-4", "25": "sm:col-span-3" };

/**
 * Public registration form: conditional fields, "Others – please specify", instant checks in the browser,
 * then a background request (no page reload). The server checks everything again.
 */
export function RegistrationForm({ event, id = "register" }: { event: PublicEvent; id?: string }) {
  const form = event.form;
  const fields = useMemo(() => form?.fields ?? [], [form]);
  const [values, setValues] = useState<Values>(() => Object.fromEntries(fields.filter((f) => f.default).map((f) => [f.name, f.default])));
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [summary, setSummary] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState<string | null>(null);
  const hp = useRef<HTMLInputElement>(null);
  const top = useRef<HTMLDivElement>(null);
  const [attribution, setAttribution] = useState<{ visitor: string; source: Record<string, string> }>({ visitor: "", source: {} });

  useEffect(() => {
    try {
      let visitor = localStorage.getItem("ep_vid");
      if (!visitor) {
        visitor = crypto.randomUUID();
        localStorage.setItem("ep_vid", visitor);
      }
      const params = new URLSearchParams(window.location.search);
      const stored = JSON.parse(sessionStorage.getItem("ep_src") ?? "null") as Record<string, string> | null;
      const source: Record<string, string> = stored ?? {};
      if (!stored) {
        ["utm_source", "utm_medium", "utm_campaign", "utm_content", "utm_term"].forEach((k) => params.get(k) && (source[k] = params.get(k)!.slice(0, 100)));
        if (document.referrer && !document.referrer.startsWith(window.location.origin)) source.referrer = document.referrer.slice(0, 300);
        sessionStorage.setItem("ep_src", JSON.stringify(source));
      }
      // eslint-disable-next-line react-hooks/set-state-in-effect
      setAttribution({ visitor, source });
    } catch {
      /* storage blocked: register without attribution */
    }
  }, []);

  if (!form) {
    return event.closed ? (
      <div className="ep-closed" id={id}>
        <h2 className="ep-closed__title">{event.closed.title}</h2>
        <div className="ep-prose" dangerouslySetInnerHTML={{ __html: event.closed.html }} />
      </div>
    ) : null;
  }

  if (done !== null) {
    return (
      <div className="ep-success" id={id} ref={top}>
        <div className="ep-prose" dangerouslySetInnerHTML={{ __html: done }} />
      </div>
    );
  }

  const set = (name: string, v: string | string[]) => {
    setValues((p) => ({ ...p, [name]: v }));
    if (errors[name]) setErrors((e) => ({ ...e, [name]: "" }));
  };

  const check = (): Record<string, string> => {
    const errs: Record<string, string> = {};
    for (const f of fields) {
      if (["heading", "html", "hidden"].includes(f.type) || !visible(f, values, fields)) continue;
      const v = values[f.name];
      const empty = Array.isArray(v) ? v.length === 0 : !v || !String(v).trim();
      if (empty) {
        if (f.required) errs[f.name] = f.type === "consent" ? "You must agree to continue." : "This field is required.";
        continue;
      }
      const s = String(v).trim();
      const other = String(values[`${f.name}__other`] ?? "").trim();
      if ((v === OTHER || (Array.isArray(v) && v.includes(OTHER))) && !other) errs[f.name] = 'Please specify "Others".';
      if (f.type === "email" && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(s)) errs[f.name] = "Please enter a valid email address.";
      const pattern = f.type === "phone" || f.type === "nric" ? form.patterns[f.type]?.[f.format] : null;
      if (pattern && !new RegExp(pattern).test(s)) {
        errs[f.name] = f.type === "nric" ? "Please enter a valid NRIC (e.g. 900101-14-5678)." : f.format === "intl" ? "Please include the country code (e.g. +60 12-345 6789)." : "Please enter a valid phone number.";
      }
    }
    return errs;
  };

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    const errs = check();
    setErrors(errs);
    setSummary(null);
    if (Object.keys(errs).length) {
      document.getElementById(`${id}-${Object.keys(errs)[0]}`)?.focus();
      return;
    }
    setBusy(true);
    try {
      const body: Values & Record<string, unknown> = { _token_ts: form.token, _hp: hp.current?.value ?? "" };
      for (const f of fields) {
        if (!visible(f, values, fields) || ["heading", "html"].includes(f.type)) continue;
        if (values[f.name] !== undefined) body[f.name] = values[f.name];
        if (values[`${f.name}__other`]) body[`${f.name}__other`] = values[`${f.name}__other`];
      }
      const res = await fetch(`/event/${event.slug}/register`, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ ...body, _visitor: attribution.visitor, _source: attribution.source }),
      });
      const json = (await res.json()) as { ok: boolean; message: string; errors?: Record<string, string>; redirect?: string; html?: string };
      if (json.ok) {
        if (json.redirect) {
          window.location.href = json.redirect;
          return;
        }
        setDone(json.html ?? "<h3>Thank you!</h3>");
        setTimeout(() => top.current?.scrollIntoView({ behavior: "smooth", block: "start" }), 50);
        return;
      }
      setErrors(json.errors ?? {});
      setSummary(json.message || "Please check the form.");
    } catch {
      setSummary("Network error. Please try again.");
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="ep-form-wrap" id={id}>
      {form.waitlist_message ? <div className="ep-notice-box">{form.waitlist_message}</div> : null}
      {form.intro ? <div className="ep-prose mb-4" dangerouslySetInnerHTML={{ __html: form.intro }} /> : null}
      <form className="ep-form" onSubmit={submit} noValidate>
        <div aria-hidden="true" className="ep-hp">
          <label>
            Leave empty
            <input ref={hp} type="text" name="website" tabIndex={-1} autoComplete="off" />
          </label>
        </div>
        <div className="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-12">
          {fields.map((f) =>
            visible(f, values, fields) && f.type !== "hidden" ? (
              <div key={f.name} className={`ep-field ${WIDTH[f.type === "heading" ? "100" : f.width] ?? "sm:col-span-12"}${errors[f.name] ? " is-invalid" : ""}`}>
                <Field f={f} id={`${id}-${f.name}`} value={values[f.name]} other={String(values[`${f.name}__other`] ?? "")} onChange={(v) => set(f.name, v)} onOther={(v) => set(`${f.name}__other`, v)} />
                {f.help && !["heading", "html"].includes(f.type) ? <small className="ep-help" dangerouslySetInnerHTML={{ __html: f.help }} /> : null}
                {errors[f.name] ? (
                  <span className="ep-error" role="alert">
                    {errors[f.name]}
                  </span>
                ) : null}
              </div>
            ) : null,
          )}
        </div>
        {summary ? (
          <div className="ep-form-errors" role="alert">
            {summary}
          </div>
        ) : null}
        <button type="submit" className="ep-btn ep-btn--solid ep-submit" disabled={busy}>
          {busy ? "Sending…" : form.submit_label}
        </button>
      </form>
    </div>
  );
}

function Field({ f, id, value, other, onChange, onOther }: { f: FormField; id: string; value: string | string[] | undefined; other: string; onChange: (v: string | string[]) => void; onOther: (v: string) => void }) {
  const req = f.required ? <span className="ep-req" aria-hidden="true"> *</span> : null;
  const label = (
    <label htmlFor={id} className="ep-label">
      <span dangerouslySetInnerHTML={{ __html: f.label }} />
      {req}
    </label>
  );
  const str = typeof value === "string" ? value : "";
  const arr = Array.isArray(value) ? value : [];
  const showOther = f.allow_other && (str === OTHER || arr.includes(OTHER));
  const otherInput = showOther ? <input className="ep-input mt-2" placeholder="Please specify" value={other} onChange={(e) => onOther(e.target.value)} aria-label={`${f.label}: please specify`} /> : null;

  switch (f.type) {
    case "heading":
      return (
        <div className="ep-form-heading">
          <h3>{f.label}</h3>
          {f.content ? <div className="ep-prose" dangerouslySetInnerHTML={{ __html: f.content }} /> : null}
        </div>
      );
    case "html":
      return <div className="ep-prose" dangerouslySetInnerHTML={{ __html: f.content }} />;
    case "textarea":
      return (
        <>
          {label}
          <textarea id={id} className="ep-input" rows={4} placeholder={f.placeholder} value={str} onChange={(e) => onChange(e.target.value)} required={f.required} />
        </>
      );
    case "select":
    case "salutation":
    case "country":
      return (
        <>
          {label}
          <select id={id} className="ep-input" value={str} onChange={(e) => onChange(e.target.value)} required={f.required}>
            <option value="">{f.placeholder || "— Select —"}</option>
            {f.options.map((o) => (
              <option key={o} value={o}>
                {o}
              </option>
            ))}
            {f.allow_other ? <option value={OTHER}>Others</option> : null}
          </select>
          {otherInput}
        </>
      );
    case "radio":
    case "checkboxes": {
      const multi = f.type === "checkboxes";
      const opts = [...f.options, ...(f.allow_other ? [OTHER] : [])];
      return (
        <fieldset id={id} tabIndex={-1} className="ep-fieldset">
          <legend className="ep-label">
            <span dangerouslySetInnerHTML={{ __html: f.label }} />
            {req}
          </legend>
          <div className="ep-choices">
            {opts.map((o, i) => (
              <label key={o} className="ep-choice" htmlFor={`${id}-${i}`}>
                <input
                  id={`${id}-${i}`}
                  type={multi ? "checkbox" : "radio"}
                  name={id}
                  value={o}
                  checked={multi ? arr.includes(o) : str === o}
                  onChange={(e) => (multi ? onChange(e.target.checked ? [...arr, o] : arr.filter((x) => x !== o)) : onChange(o))}
                />
                <span>{o === OTHER ? "Others" : o}</span>
              </label>
            ))}
          </div>
          {otherInput}
        </fieldset>
      );
    }
    case "consent":
      return (
        <label className="ep-choice ep-consent" htmlFor={id}>
          <input id={id} type="checkbox" checked={str === "Agreed"} onChange={(e) => onChange(e.target.checked ? "Agreed" : "")} />
          <span>
            <span dangerouslySetInnerHTML={{ __html: f.content || f.label }} />
            {req}
          </span>
        </label>
      );
    default: {
      const type = f.type === "email" ? "email" : f.type === "phone" ? "tel" : f.type === "number" ? "number" : f.type === "date" ? "date" : "text";
      const ph = f.placeholder || (f.type === "nric" && f.format === "my" ? "e.g. 900101-14-5678" : f.type === "phone" ? (f.format === "my" ? "e.g. 012-345 6789" : "e.g. +60 12-345 6789") : "");
      return (
        <>
          {label}
          <input
            id={id}
            className="ep-input"
            type={type}
            inputMode={f.type === "nric" ? "numeric" : undefined}
            autoComplete={f.type === "email" ? "email" : f.type === "phone" ? "tel" : f.name.includes("name") ? "name" : "off"}
            placeholder={ph}
            value={str}
            onChange={(e) => onChange(e.target.value)}
            required={f.required}
          />
        </>
      );
    }
  }
}
