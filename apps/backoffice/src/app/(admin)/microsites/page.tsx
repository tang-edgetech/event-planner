"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { App, Button, Dropdown, Form, Input, Modal, Select, Table } from "antd";
import { CopyOutlined, DeleteOutlined, EditOutlined, ExportOutlined, MoreOutlined, PlusOutlined } from "@ant-design/icons";
import { keepPreviousData, useQuery, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { api, errorMessage, formErrors } from "@/lib/api";
import { fromNow } from "@/lib/format";
import { useCan, useMe } from "@/lib/session";
import type { Paged } from "@/lib/types";
import { PageHead, PublishTag } from "@/components/ui";
import { EventSelect } from "./schema-fields";

type Row = { id: number; title: string; slug: string; status: string; event: { id: number; title: string } | null; sections_count: number; updated_at: string };

export default function MicrositesPage() {
  const can = useCan();
  const me = useMe();
  const router = useRouter();
  const qc = useQueryClient();
  const { message, modal } = App.useApp();
  const [p, setP] = useState<{ q?: string; status?: string; page: number }>({ page: 1 });
  const [creating, setCreating] = useState(false);
  const [form] = Form.useForm();
  const { data, isFetching } = useQuery({ queryKey: ["microsites", p], queryFn: async () => (await api.get<Paged<Row>>("/microsites", { params: p })).data, placeholderData: keepPreviousData });

  const create = async (v: { title: string; slug?: string; event_id?: number }) => {
    try {
      const res = await api.post("/microsites", v);
      message.success("Microsite created. Add and arrange its sections.");
      router.push(`/microsites/${res.data.data.id}/builder`);
    } catch (e) {
      form.setFields(formErrors(e));
      message.error(errorMessage(e));
    }
  };
  const duplicate = async (r: Row) => {
    const res = await api.post(`/microsites/${r.id}/duplicate`);
    message.success("Copy created as a draft");
    router.push(`/microsites/${res.data.data.id}/builder`);
  };
  const remove = (r: Row) =>
    modal.confirm({
      title: `Delete "${r.title}"?`,
      content: `/e/${r.slug} will show "Page not found".`,
      okText: "Delete",
      okButtonProps: { danger: true },
      onOk: async () => {
        await api.delete(`/microsites/${r.id}`);
        message.success("Microsite deleted");
        qc.invalidateQueries({ queryKey: ["microsites"] });
      },
    });

  return (
    <>
      <PageHead
        title="Microsites"
        sub="Event websites built from sections, at /e/{address}."
        actions={
          can("microsites.manage") ? (
            <Button type="primary" icon={<PlusOutlined />} onClick={() => setCreating(true)}>
              New microsite
            </Button>
          ) : null
        }
      />
      <div className="ep-filters">
        <Input.Search allowClear placeholder="Search title or address" onSearch={(q) => setP({ ...p, q, page: 1 })} style={{ width: 280 }} />
        <Select
          allowClear
          placeholder="All statuses"
          style={{ width: 150 }}
          options={[
            { value: "published", label: "Published" },
            { value: "draft", label: "Draft" },
          ]}
          onChange={(status) => setP({ ...p, status, page: 1 })}
        />
      </div>
      <Table<Row>
        rowKey="id"
        loading={isFetching}
        dataSource={data?.data}
        scroll={{ x: 760 }}
        pagination={{ current: p.page, pageSize: 20, total: data?.meta.total, onChange: (page) => setP({ ...p, page }) }}
        columns={[
          {
            title: "Microsite",
            render: (_, r) => (
              <div>
                <Link href={`/microsites/${r.id}/builder`} className="font-semibold">
                  {r.title}
                </Link>
                <div className="ep-muted text-xs">/e/{r.slug}</div>
              </div>
            ),
          },
          { title: "Linked event", render: (_, r) => (r.event ? <Link href={`/events/${r.event.id}/details`}>{r.event.title}</Link> : <span className="ep-muted">—</span>) },
          { title: "Sections", dataIndex: "sections_count", width: 100 },
          { title: "Status", width: 110, render: (_, r) => <PublishTag status={r.status} /> },
          { title: "Updated", width: 140, render: (_, r) => fromNow(r.updated_at) },
          {
            title: "",
            width: 60,
            render: (_, r) => (
              <Dropdown
                trigger={["click"]}
                menu={{
                  items: [
                    { key: "e", icon: <EditOutlined />, label: <Link href={`/microsites/${r.id}/builder`}>{can("microsites.manage") ? "Edit" : "View"}</Link> },
                    { key: "o", icon: <ExportOutlined />, label: <a href={`${me.settings.web_url}/e/${r.slug}`} target="_blank" rel="noreferrer">Open public page</a>, disabled: r.status !== "published" },
                    ...(can("microsites.manage")
                      ? [
                          { key: "d", icon: <CopyOutlined />, label: "Duplicate", onClick: () => duplicate(r) },
                          { type: "divider" as const },
                          { key: "x", icon: <DeleteOutlined />, label: "Delete", danger: true, onClick: () => remove(r) },
                        ]
                      : []),
                  ],
                }}
              >
                <Button type="text" icon={<MoreOutlined />} aria-label={`Actions for ${r.title}`} />
              </Dropdown>
            ),
          },
        ]}
      />
      <Modal open={creating} title="New microsite" onCancel={() => setCreating(false)} onOk={() => form.submit()} okText="Create" destroyOnHidden>
        <Form form={form} layout="vertical" requiredMark={false} onFinish={create}>
          <Form.Item label="Title" name="title" rules={[{ required: true, message: "Give it a title." }]}>
            <Input autoFocus maxLength={255} />
          </Form.Item>
          <Form.Item label="Web address" name="slug" extra="Leave empty to create it from the title." rules={[{ pattern: /^[a-z0-9]+(?:-[a-z0-9]+)*$/, message: "Lower-case letters, numbers and dashes." }]}>
            <Input addonBefore="/e/" />
          </Form.Item>
          <Form.Item label="Linked event (optional)" name="event_id" extra="Used for the banner date/venue and registration-form sections.">
            <EventSelect value={null} onChange={(v) => form.setFieldValue("event_id", v)} />
          </Form.Item>
        </Form>
      </Modal>
    </>
  );
}
