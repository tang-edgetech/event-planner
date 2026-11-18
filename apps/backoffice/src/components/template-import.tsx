"use client";

import { Alert, Modal, Radio, Select, Upload } from "antd";
import { InboxOutlined } from "@ant-design/icons";
import { useState } from "react";

/** Saves data as a .json file on the user's computer. */
export function downloadJson(filename: string, data: unknown) {
  const blob = new Blob([JSON.stringify(data, null, 2)], { type: "application/json" });
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = filename;
  a.click();
  URL.revokeObjectURL(url);
}

export const slugify = (s: string) =>
  s
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-|-$/g, "")
    .slice(0, 60) || "template";

type Mode = "replace" | "append";

/**
 * Import a template from a .json file (exported from this or another Event Plus system),
 * or copy it from an existing item. Nothing is saved until the user presses Save.
 */
export function TemplateImport<T>({
  open,
  onClose,
  what,
  itemNoun,
  sources,
  loadFrom,
  parse,
  describe,
  onApply,
}: {
  open: boolean;
  onClose: () => void;
  /** e.g. "registration form" */
  what: string;
  /** e.g. "event" */
  itemNoun: string;
  sources: { value: number; label: string }[];
  loadFrom: (id: number) => Promise<T>;
  /** Turns the file's JSON into the template, or throws an Error with a plain-language reason. */
  parse: (json: unknown) => T;
  /** e.g. "12 fields" */
  describe: (t: T) => string;
  onApply: (t: T, mode: Mode) => void;
}) {
  const [from, setFrom] = useState<"file" | "item">("file");
  const [mode, setMode] = useState<Mode>("replace");
  const [template, setTemplate] = useState<T | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [fileName, setFileName] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  const reset = () => {
    setTemplate(null);
    setError(null);
    setFileName(null);
  };

  const readFile = async (file: File) => {
    reset();
    setFileName(file.name);
    try {
      if (file.size > 2_000_000) throw new Error("The file is larger than 2 MB, so it is probably not a template.");
      setTemplate(parse(JSON.parse(await file.text())));
    } catch (e) {
      setError(e instanceof SyntaxError ? "This file is not valid JSON. Choose a template exported from Event Plus." : (e as Error).message);
    }
    return false; // never upload: the file is read in the browser
  };

  const pick = async (id: number) => {
    reset();
    setLoading(true);
    try {
      setTemplate(await loadFrom(id));
    } catch (e) {
      setError((e as Error).message || `That ${itemNoun} could not be loaded.`);
    } finally {
      setLoading(false);
    }
  };

  return (
    <Modal
      open={open}
      title={`Import ${what} template`}
      okText="Import"
      okButtonProps={{ disabled: !template }}
      confirmLoading={loading}
      onOk={() => {
        if (template) onApply(template, mode);
        reset();
        onClose();
      }}
      onCancel={() => {
        reset();
        onClose();
      }}
      destroyOnHidden
    >
      <div className="flex flex-col gap-4">
        <Radio.Group
          optionType="button"
          value={from}
          onChange={(e) => {
            setFrom(e.target.value);
            reset();
          }}
          options={[
            { value: "file", label: "From a file (.json)" },
            { value: "item", label: `Copy from another ${itemNoun}` },
          ]}
        />
        {from === "file" ? (
          <Upload.Dragger accept=".json,application/json" maxCount={1} showUploadList={false} beforeUpload={readFile}>
            <p className="ep-ant-upload-drag-icon">
              <InboxOutlined />
            </p>
            <p className="ep-ant-upload-text">{fileName ?? "Click or drop a template file here"}</p>
            <p className="ep-ant-upload-hint">Use “Export template” to create one. The file stays on your computer.</p>
          </Upload.Dragger>
        ) : (
          <Select showSearch={{ optionFilterProp: "label" }} placeholder={`Choose ${/^[aeiou]/i.test(itemNoun) ? "an" : "a"} ${itemNoun}`} options={sources} onChange={pick} className="!w-full" />
        )}
        {error ? <Alert type="error" showIcon title={error} /> : null}
        {template ? <Alert type="success" showIcon title={`Ready to import: ${describe(template)}.`} /> : null}
        <div>
          <div className="mb-1 text-sm font-medium">What happens to what is there now</div>
          <Radio.Group
            value={mode}
            onChange={(e) => setMode(e.target.value)}
            options={[
              { value: "replace", label: "Replace it with the template" },
              { value: "append", label: "Keep it and add the template at the end" },
            ]}
          />
        </div>
        <p className="ep-muted m-0 text-xs">Nothing is saved until you press Save. Leave the page without saving to undo.</p>
      </div>
    </Modal>
  );
}
