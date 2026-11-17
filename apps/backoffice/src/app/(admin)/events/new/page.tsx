"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { App, Button, DatePicker, Form, Input } from "antd";
import { ArrowLeftOutlined } from "@ant-design/icons";
import type { Dayjs } from "dayjs";
import { useState } from "react";
import { api, errorMessage, formErrors } from "@/lib/api";
import { useCan } from "@/lib/session";
import { NotFoundView, PageHead } from "@/components/ui";

/** Create an event with the basics; everything else is set in its tabs afterwards. */
export default function NewEventPage() {
  const can = useCan();
  const router = useRouter();
  const { message } = App.useApp();
  const [form] = Form.useForm();
  const [saving, setSaving] = useState(false);
  if (!can("events.manage")) return <NotFoundView />;

  const submit = async (v: { title: string; slug?: string; venue?: string; starts_at?: Dayjs; ends_at?: Dayjs }) => {
    setSaving(true);
    try {
      const res = await api.post("/events", {
        title: v.title,
        slug: v.slug || undefined,
        venue: v.venue,
        starts_at: v.starts_at?.format("YYYY-MM-DD HH:mm:ss"),
        ends_at: v.ends_at?.format("YYYY-MM-DD HH:mm:ss"),
        status: "draft",
      });
      message.success("Event created as a draft with a starter registration form");
      router.push(`/events/${res.data.data.id}/details`);
    } catch (e) {
      form.setFields(formErrors(e));
      message.error(errorMessage(e));
    } finally {
      setSaving(false);
    }
  };

  return (
    <>
      <PageHead
        title="New event"
        actions={
          <Link href="/events">
            <Button icon={<ArrowLeftOutlined />}>All events</Button>
          </Link>
        }
      />
      <div className="ep-card">
        <Form form={form} layout="vertical" requiredMark={false} onFinish={submit} style={{ maxWidth: 720 }}>
          <Form.Item label="Event title" name="title" rules={[{ required: true, message: "Give the event a title." }]}>
            <Input maxLength={255} autoFocus />
          </Form.Item>
          <Form.Item label="Web address" name="slug" extra="Leave empty to create it from the title. Lower-case letters, numbers and dashes." rules={[{ pattern: /^[a-z0-9]+(?:-[a-z0-9]+)*$/, message: "Use lower-case letters, numbers and dashes only." }]}>
            <Input addonBefore="/event/" />
          </Form.Item>
          <div className="grid gap-x-4 md:grid-cols-2">
            <Form.Item label="Starts" name="starts_at">
              <DatePicker showTime={{ format: "h:mm A" }} format="D MMM YYYY, h:mm A" className="!w-full" />
            </Form.Item>
            <Form.Item label="Ends" name="ends_at">
              <DatePicker showTime={{ format: "h:mm A" }} format="D MMM YYYY, h:mm A" className="!w-full" />
            </Form.Item>
          </div>
          <Form.Item label="Venue" name="venue">
            <Input maxLength={255} />
          </Form.Item>
          <Button type="primary" htmlType="submit" loading={saving}>
            Create event
          </Button>
        </Form>
      </div>
    </>
  );
}
