"use client";

import { Button, Tooltip } from "antd";
import { BoldOutlined, ClearOutlined, ItalicOutlined, LinkOutlined, OrderedListOutlined, UnderlineOutlined, UnorderedListOutlined } from "@ant-design/icons";
import { useEffect, useRef } from "react";

type Tool = { title: string; icon: React.ReactNode; cmd: string; arg?: string; link?: boolean };

const TOOLS: Tool[] = [
  { title: "Bold", icon: <BoldOutlined />, cmd: "bold" },
  { title: "Italic", icon: <ItalicOutlined />, cmd: "italic" },
  { title: "Underline", icon: <UnderlineOutlined />, cmd: "underline" },
  { title: "Heading", icon: <span className="text-xs font-bold">H</span>, cmd: "formatBlock", arg: "h3" },
  { title: "Paragraph", icon: <span className="text-xs">¶</span>, cmd: "formatBlock", arg: "p" },
  { title: "Bullet list", icon: <UnorderedListOutlined />, cmd: "insertUnorderedList" },
  { title: "Numbered list", icon: <OrderedListOutlined />, cmd: "insertOrderedList" },
  { title: "Link", icon: <LinkOutlined />, cmd: "createLink", link: true },
  { title: "Clear formatting", icon: <ClearOutlined />, cmd: "removeFormat" },
];

/**
 * Small rich-text editor (bold, italic, underline, heading, lists, link) for messages and descriptions.
 * Works as an Ant Design form control (value / onChange). The server cleans the HTML before saving.
 */
export function RichText({ value, onChange, placeholder, minHeight = 120 }: { value?: string | null; onChange?: (html: string) => void; placeholder?: string; minHeight?: number }) {
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const el = ref.current;
    if (el && el.innerHTML !== (value ?? "")) el.innerHTML = value ?? "";
  }, [value]);

  const emit = () => onChange?.(ref.current?.innerHTML ?? "");

  const run = (t: Tool) => {
    let arg = t.arg;
    if (t.link) {
      const url = window.prompt("Link address (https://…)");
      if (!url || !/^(https?:|mailto:|tel:|#|\/)/i.test(url)) return;
      arg = url;
    }
    ref.current?.focus();
    document.execCommand(t.cmd, false, arg);
    emit();
  };

  return (
    <div className="ep-rte">
      <div className="ep-rte__bar" role="toolbar" aria-label="Formatting">
        {TOOLS.map((t) => (
          <Tooltip key={t.title} title={t.title}>
            <Button size="small" type="text" icon={t.icon} aria-label={t.title} onMouseDown={(e) => e.preventDefault()} onClick={() => run(t)} />
          </Tooltip>
        ))}
      </div>
      <div
        ref={ref}
        className="ep-rte__area"
        style={{ minHeight }}
        contentEditable
        suppressContentEditableWarning
        role="textbox"
        aria-multiline="true"
        aria-label={placeholder ?? "Text"}
        onInput={emit}
        onBlur={emit}
      />
    </div>
  );
}
