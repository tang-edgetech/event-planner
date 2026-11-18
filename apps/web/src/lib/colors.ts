/** A colour from saved settings, only when it is a plain hex value (it ends up in an inline style). */
export const hexColor = (v: unknown): string | undefined => (typeof v === "string" && /^#[0-9a-fA-F]{3,8}$/.test(v) ? v : undefined);
