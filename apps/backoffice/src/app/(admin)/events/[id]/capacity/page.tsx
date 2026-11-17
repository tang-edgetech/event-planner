"use client";

import { DatePicker, Divider, Form, Input, InputNumber, Radio, Select, Switch } from "antd";
import { RichText } from "@/components/rich-text";
import { EventTabForm } from "../../event-context";

const DT = { showTime: { format: "h:mm A" }, format: "D MMM YYYY, h:mm A", className: "!w-full" } as const;

export default function CapacityTab() {
  return (
    <EventTabForm>
      {(form) => (
        <>
          <Form.Item label="Registration status" name="reg_status" extra="Automatic: open between the opening and closing times, until the event is full.">
            <Radio.Group
              optionType="button"
              options={[
                { value: "auto", label: "Automatic" },
                { value: "open", label: "Always open" },
                { value: "full", label: "Show as full" },
                { value: "closed", label: "Closed" },
              ]}
            />
          </Form.Item>
          <div className="grid gap-x-4 md:grid-cols-2">
            <Form.Item label="Maximum capacity" name="capacity_max" extra="Seats available. Leave empty for no limit. Registered, confirmed and attended applicants take a seat.">
              <InputNumber min={0} className="!w-full" />
            </Form.Item>
            <Form.Item label="Minimum capacity (for your reference)" name="capacity_min">
              <InputNumber min={0} className="!w-full" />
            </Form.Item>
            <Form.Item label="Registration opens" name="reg_open_at">
              <DatePicker {...DT} />
            </Form.Item>
            <Form.Item label="Registration closes" name="reg_close_at">
              <DatePicker {...DT} />
            </Form.Item>
            <Form.Item label="Waiting list when full" name="enable_waitlist" valuePropName="checked" extra="People can still register and are placed on the waiting list.">
              <Switch />
            </Form.Item>
            <Form.Item label="Status for new applicants" name="default_status">
              <Select
                options={[
                  { value: "registered", label: "Registered (to be confirmed)" },
                  { value: "confirmed", label: "Confirmed" },
                ]}
              />
            </Form.Item>
          </div>
          <Form.Item noStyle shouldUpdate={(a, b) => a.enable_waitlist !== b.enable_waitlist}>
            {() =>
              form.getFieldValue("enable_waitlist") ? (
                <>
                  <Divider titlePlacement="start">Waiting list</Divider>
                  <Form.Item label="Notice above the form when full" name="waitlist_message">
                    <Input.TextArea rows={2} maxLength={2000} placeholder="This event is currently full. You may still register and you will be placed on the waiting list." />
                  </Form.Item>
                  <Form.Item label="Message after joining the waiting list" name="waitlist_confirmation">
                    <RichText placeholder="You are on the waiting list…" />
                  </Form.Item>
                </>
              ) : null
            }
          </Form.Item>
          <Divider titlePlacement="start">When registration is not possible</Divider>
          <div className="grid gap-x-4 md:grid-cols-2">
            <Form.Item label="“Full” heading" name="full_title">
              <Input placeholder="Application Full" />
            </Form.Item>
            <Form.Item label="“Closed” heading" name="closed_title">
              <Input placeholder="Registration Closed" />
            </Form.Item>
            <Form.Item label="“Full” message" name="full_message">
              <RichText minHeight={90} />
            </Form.Item>
            <Form.Item label="“Closed” message" name="closed_message">
              <RichText minHeight={90} />
            </Form.Item>
          </div>
        </>
      )}
    </EventTabForm>
  );
}
