import type { Metadata } from "next";
import { LoginForm } from "./login-form";

export const metadata: Metadata = { title: "Sign in · Event Plus Backoffice" };

export default function LoginPage() {
  return (
    <main className="ep-auth">
      <div className="ep-auth__card !max-w-sm">
        <div className="ep-auth__brand">
          <span className="ep-logo" aria-hidden="true" />
          Event Plus
        </div>
        <LoginForm />
      </div>
    </main>
  );
}
