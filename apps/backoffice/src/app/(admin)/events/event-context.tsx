"use client";

import { App, Button, Form, type FormInstance } from "antd";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import dayjs, { type Dayjs } from "dayjs";
import { createContext, useContext, useEffect, useState } from "react";
import { api, errorMessage, formErrors } from "@/lib/api";
import { useCan } from "@/lib/session";
import type { EventDetail } from "@/lib/types";

export const EventContext = createContext<EventDetail | null>(null);
export const useEventDetail = () => {
  const e = useContext(EventContext);
  if (!e) throw new Error("useEventDetail outside event layout");
  return e;
};

export function useEventQuery(id: string) {
  return useQuery({ queryKey: ["event", id], queryFn: async () => (await api.get<{ data: EventDetail }>(`/events/${id}`)).data.data });
}

const DATE_KEYS = ["starts_at", "ends_at", "reg_open_at", "reg_close_at"];
const COLOR_KEYS = ["accent_color", "page_bg"];

// eslint-disable-next-line @typescript-eslint/no-explicit-any
type Values = Record<string, any>;

export function toFormValues(e: Values): Values {
  const v: Values = { ...e };
  DATE_KEYS.forEach((k) => (v[k] = e[k] ? dayjs(e[k] as string) : null));
  return v;
}

export function fromFormValues(values: Values): Values {
  const v: Values = { ...values };
  DATE_KEYS.forEach((k) => {
    if (k in v) v[k] = v[k] ? (v[k] as Dayjs).format("YYYY-MM-DD HH:mm:ss") : null;
  });
  COLOR_KEYS.forEach((k) => {
    if (k in v && v[k] && typeof v[k] === "object") v[k] = (v[k] as { toHexString: () => string }).toHexString();
  });
  return v;
}

/** One event tab: a form that saves only its own fields in the background. */
export function EventTabForm({ children, transformIn, transformOut }: { children: (form: FormInstance) => React.ReactNode; transformIn?: (v: Values) => Values; transformOut?: (v: Values) => Values }) {
  const event = useEventDetail();
  const can = useCan();
  const qc = useQueryClient();
  const { message } = App.useApp();
  const [form] = Form.useForm();
  const [saving, setSaving] = useState(false);
  const editable = can("events.manage");

  useEffect(() => {
    const v = toFormValues(event);
    form.setFieldsValue(transformIn ? transformIn(v) : v);
  }, [event, form, transformIn]);

  const save = async (values: Values) => {
    setSaving(true);
    try {
      // With a transform (the form builder), read the whole form store: Ant Design leaves out list rows it has not rendered yet.
      const body = fromFormValues(transformOut ? transformOut(form.getFieldsValue(true)) : values);
      const res = await api.put<{ data: EventDetail }>(`/events/${event.id}`, body);
      qc.setQueryData(["event", String(event.id)], res.data.data);
      qc.invalidateQueries({ queryKey: ["events"] });
      message.success("Event saved");
    } catch (e) {
      form.setFields(formErrors(e));
      message.error(errorMessage(e, "Please check the highlighted fields."));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Form form={form} layout="vertical" requiredMark={false} onFinish={save} disabled={!editable} initialValues={transformIn ? transformIn(toFormValues(event)) : toFormValues(event)} scrollToFirstError>
      <div className="ep-card">{children(form)}</div>
      {editable ? (
        <div className="ep-sticky-actions">
          <Button type="primary" htmlType="submit" loading={saving} disabled={false}>
            Save changes
          </Button>
        </div>
      ) : null}
    </Form>
  );
}
