import dayjs from "dayjs";
import relativeTime from "dayjs/plugin/relativeTime";

dayjs.extend(relativeTime);

let dateFormat = "D MMM YYYY";

/** Settings → General → date format, set once the session loads. */
export const setDateFormat = (f?: string) => {
  if (f) dateFormat = f;
};

export const fmtDate = (v?: string | null) => (v ? dayjs(v).format(dateFormat) : "—");
export const fmtDateTime = (v?: string | null) => (v ? dayjs(v).format(`${dateFormat}, h:mm A`) : "—");
export const fromNow = (v?: string | null) => (v ? dayjs(v).fromNow() : "never");
export const fmtNumber = (n?: number | null) => (n === null || n === undefined ? "—" : n.toLocaleString());
