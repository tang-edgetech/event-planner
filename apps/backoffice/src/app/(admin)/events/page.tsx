"use client";

import Link from "next/link";
import { App, Button, DatePicker, Dropdown, Input, Select, Table } from "antd";
import { CopyOutlined, DeleteOutlined, EditOutlined, ExportOutlined, MoreOutlined, PlusOutlined, TeamOutlined } from "@ant-design/icons";
import { keepPreviousData, useQuery, useQueryClient } from "@tanstack/react-query";
import { useRouter } from "next/navigation";
import type { Dayjs } from "dayjs";
import { useState } from "react";
import { api, errorMessage } from "@/lib/api";
import { fmtDate } from "@/lib/format";
import { useCan, useMe } from "@/lib/session";
import type { EventRow, Paged } from "@/lib/types";
import { PageHead, PublishTag, RegistrationTag, SeatBar } from "@/components/ui";

export default function EventsPage() {
  const can = useCan();
  const me = useMe();
  const router = useRouter();
  const qc = useQueryClient();
  const { message, modal } = App.useApp();
  const [p, setP] = useState<{ q?: string; status?: string; from?: string; to?: string; page: number; per_page: number }>({ page: 1, per_page: 20 });
  const { data, isFetching } = useQuery({ queryKey: ["events", p], queryFn: async () => (await api.get<Paged<EventRow>>("/events", { params: p })).data, placeholderData: keepPreviousData });

  const duplicate = async (e: EventRow) => {
    try {
      const res = await api.post(`/events/${e.id}/duplicate`);
      message.success("Copy created as a draft");
      router.push(`/events/${res.data.data.id}/details`);
    } catch (err) {
      message.error(errorMessage(err));
    }
  };
  const remove = (e: EventRow) =>
    modal.confirm({
      title: `Delete "${e.title}"?`,
      content: `It will disappear from the public site. Its ${e.applicants_count} applicant(s) are kept for your records.`,
      okText: "Delete",
      okButtonProps: { danger: true },
      onOk: async () => {
        await api.delete(`/events/${e.id}`);
        message.success("Event deleted");
        qc.invalidateQueries({ queryKey: ["events"] });
      },
    });

  return (
    <>
      <PageHead
        title="Events"
        sub="Each event has its own registration page at /event/{address}."
        actions={
          can("events.manage") ? (
            <Link href="/events/new">
              <Button type="primary" icon={<PlusOutlined />}>
                New event
              </Button>
            </Link>
          ) : null
        }
      />
      <div className="ep-filters">
        <Input.Search allowClear placeholder="Search title, venue or address" onSearch={(q) => setP((x) => ({ ...x, q, page: 1 }))} style={{ width: 280 }} />
        <Select
          allowClear
          placeholder="All statuses"
          style={{ width: 150 }}
          options={[
            { value: "published", label: "Published" },
            { value: "draft", label: "Draft" },
          ]}
          onChange={(status) => setP((x) => ({ ...x, status, page: 1 }))}
        />
        <DatePicker.RangePicker
          placeholder={["Event from", "to"]}
          onChange={(r) => {
            const range = r as [Dayjs | null, Dayjs | null] | null;
            setP((x) => ({ ...x, from: range?.[0]?.format("YYYY-MM-DD"), to: range?.[1]?.format("YYYY-MM-DD"), page: 1 }));
          }}
        />
      </div>
      <Table<EventRow>
        rowKey="id"
        loading={isFetching}
        dataSource={data?.data}
        scroll={{ x: 900 }}
        pagination={{ current: p.page, pageSize: p.per_page, total: data?.meta.total, showSizeChanger: true, onChange: (page, per_page) => setP((x) => ({ ...x, page, per_page })) }}
        onRow={(e) => ({ onDoubleClick: () => router.push(`/events/${e.id}/details`) })}
        columns={[
          {
            title: "Event",
            render: (_, e) => (
              <div className="min-w-[220px]">
                <Link href={`/events/${e.id}/details`} className="font-semibold">
                  {e.title}
                </Link>
                <div className="ep-muted text-xs">/event/{e.slug}</div>
              </div>
            ),
          },
          { title: "Date", width: 140, render: (_, e) => e.date_text || fmtDate(e.starts_at) },
          { title: "Status", width: 110, render: (_, e) => <PublishTag status={e.status} /> },
          { title: "Registration", width: 170, render: (_, e) => <RegistrationTag reg={e.registration} /> },
          {
            title: "Seats",
            width: 150,
            render: (_, e) => (
              <span>
                <SeatBar count={e.seated_count} max={e.capacity_max} />
                {e.waitlist_count ? <span className="ep-muted text-xs">+{e.waitlist_count} waiting</span> : null}
              </span>
            ),
          },
          {
            title: "",
            width: 60,
            render: (_, e) => (
              <Dropdown
                trigger={["click"]}
                menu={{
                  items: [
                    { key: "edit", icon: <EditOutlined />, label: <Link href={`/events/${e.id}/details`}>{can("events.manage") ? "Edit" : "View"}</Link> },
                    { key: "app", icon: <TeamOutlined />, label: <Link href={`/events/${e.id}/applicants`}>Applicants ({e.applicants_count})</Link> },
                    { key: "open", icon: <ExportOutlined />, label: <a href={`${me.settings.web_url}/event/${e.slug}`} target="_blank" rel="noreferrer">Open public page</a>, disabled: e.status !== "published" },
                    ...(can("events.manage")
                      ? [
                          { key: "dup", icon: <CopyOutlined />, label: "Duplicate", onClick: () => duplicate(e) },
                          { type: "divider" as const },
                          { key: "del", icon: <DeleteOutlined />, label: "Delete", danger: true, onClick: () => remove(e) },
                        ]
                      : []),
                  ],
                }}
              >
                <Button type="text" icon={<MoreOutlined />} aria-label={`Actions for ${e.title}`} />
              </Dropdown>
            ),
          },
        ]}
      />
    </>
  );
}
