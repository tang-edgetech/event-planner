"use client";

import { App, Button, Empty, Image, Input, Modal, Spin, Upload } from "antd";
import { DeleteOutlined, PictureOutlined, UploadOutlined } from "@ant-design/icons";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { api, errorMessage, mediaUrl } from "@/lib/api";
import type { MediaItem } from "@/lib/types";

/**
 * Pick or upload an image. Value is the stored relative path ("media/2026/11/x.jpg").
 * Works as an Ant Design form control.
 */
export function MediaPicker({ value, onChange, accept = "image/*" }: { value?: string | null; onChange?: (v: string | null) => void; accept?: string }) {
  const [open, setOpen] = useState(false);
  const isVideo = !!value && /\.mp4$/i.test(value);
  return (
    <div className="flex flex-wrap items-center gap-3">
      {value ? (
        isVideo ? (
          <video src={mediaUrl(value)} className="h-20 rounded border" muted />
        ) : (
          <Image src={mediaUrl(value)} alt="" width={120} height={72} style={{ objectFit: "cover", borderRadius: 6 }} />
        )
      ) : null}
      <Button icon={<PictureOutlined />} onClick={() => setOpen(true)}>
        {value ? "Change" : "Choose file"}
      </Button>
      {value ? (
        <Button danger type="text" icon={<DeleteOutlined />} onClick={() => onChange?.(null)}>
          Remove
        </Button>
      ) : null}
      {open ? (
        <MediaLibrary
          accept={accept}
          onClose={() => setOpen(false)}
          onPick={(m) => {
            onChange?.(m.url);
            setOpen(false);
          }}
        />
      ) : null}
    </div>
  );
}

/** Multiple images (gallery, logos). */
export function MediaListPicker({ value, onChange }: { value?: string[] | null; onChange?: (v: string[]) => void }) {
  const [open, setOpen] = useState(false);
  const list = value ?? [];
  return (
    <div className="flex flex-wrap gap-2">
      {list.map((src, i) => (
        <div key={`${src}-${i}`} className="relative">
          <Image src={mediaUrl(src)} alt="" width={90} height={64} style={{ objectFit: "cover", borderRadius: 6 }} />
          <Button size="small" danger shape="circle" icon={<DeleteOutlined />} aria-label="Remove image" className="!absolute -right-2 -top-2" onClick={() => onChange?.(list.filter((_, j) => j !== i))} />
        </div>
      ))}
      <Button icon={<PictureOutlined />} onClick={() => setOpen(true)} style={{ height: 64 }}>
        Add images
      </Button>
      {open ? (
        <MediaLibrary
          accept="image/*"
          onClose={() => setOpen(false)}
          onPick={(m) => {
            onChange?.([...list, m.url]);
          }}
          keepOpen
        />
      ) : null}
    </div>
  );
}

function MediaLibrary({ onClose, onPick, accept, keepOpen }: { onClose: () => void; onPick: (m: MediaItem) => void; accept: string; keepOpen?: boolean }) {
  const { message } = App.useApp();
  const qc = useQueryClient();
  const [q, setQ] = useState("");
  const [uploading, setUploading] = useState(false);
  const { data, isLoading } = useQuery({
    queryKey: ["media", q],
    queryFn: async () => (await api.get<{ data: MediaItem[] }>("/media", { params: { q } })).data.data,
  });

  const upload = async (file: File) => {
    setUploading(true);
    try {
      const body = new FormData();
      body.append("file", file);
      const res = await api.post<{ data: MediaItem }>("/media", body);
      await qc.invalidateQueries({ queryKey: ["media"] });
      message.success("Uploaded");
      onPick(res.data.data);
    } catch (e) {
      message.error(errorMessage(e, "Upload failed."));
    } finally {
      setUploading(false);
    }
    return false;
  };

  return (
    <Modal open title="Media library" onCancel={onClose} footer={<Button onClick={onClose}>{keepOpen ? "Done" : "Cancel"}</Button>} width={{ xs: "100%", md: 820 }}>
      <div className="mb-3 flex flex-wrap gap-2">
        <Upload accept={accept === "image/*" ? "image/*" : `${accept},image/*`} showUploadList={false} beforeUpload={(f) => upload(f as unknown as File)}>
          <Button type="primary" icon={<UploadOutlined />} loading={uploading}>
            Upload new
          </Button>
        </Upload>
        <Input.Search allowClear placeholder="Search by file name" onSearch={setQ} style={{ maxWidth: 280 }} />
      </div>
      <p className="ep-muted mb-3 text-xs">JPG, PNG, WebP, GIF or SVG up to 10 MB{accept.includes("mp4") ? ", or MP4 video" : ""}.</p>
      {isLoading ? (
        <Spin />
      ) : !data?.length ? (
        <Empty description="No files yet" />
      ) : (
        <div className="grid max-h-[55vh] grid-cols-2 gap-3 overflow-y-auto sm:grid-cols-4">
          {data.map((m) => (
            <button key={m.id} type="button" className="ep-card cursor-pointer !p-1 text-left hover:opacity-80" onClick={() => onPick(m)}>
              {m.mime.startsWith("video") ? (
                <video src={mediaUrl(m.url)} className="h-24 w-full rounded object-cover" muted />
              ) : (
                // eslint-disable-next-line @next/next/no-img-element
                <img src={mediaUrl(m.url)} alt={m.original_name} className="h-24 w-full rounded object-cover" />
              )}
              <span className="block truncate px-1 pt-1 text-xs">{m.original_name}</span>
            </button>
          ))}
        </div>
      )}
    </Modal>
  );
}
