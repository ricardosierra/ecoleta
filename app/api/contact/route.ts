import { NextRequest, NextResponse } from "next/server";
import { contactSchema } from "@/lib/contact-schema";
import { checkRateLimit } from "@/lib/rate-limit";
import { siteConfig } from "@/lib/site.config";

export const runtime = "nodejs";

function escapeHtml(s: string) {
  return s
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

/**
 * Remove caracteres usados em header injection (CR/LF) — defesa em profundidade.
 */
function sanitizeHeaderValue(s: string) {
  return s.replace(/[\r\n]/g, " ").trim();
}

export async function POST(req: NextRequest) {
  // Rate limit por IP
  const ip =
    req.headers.get("x-forwarded-for")?.split(",")[0]?.trim() ||
    req.headers.get("x-real-ip") ||
    "unknown";
  const rl = checkRateLimit(`contact:${ip}`);
  if (!rl.allowed) {
    return NextResponse.json(
      { error: "Muitas tentativas. Tente novamente em instantes." },
      { status: 429, headers: { "Retry-After": String(rl.retryAfterSec) } }
    );
  }

  let payload: unknown;
  try {
    payload = await req.json();
  } catch {
    return NextResponse.json({ error: "Requisição inválida." }, { status: 400 });
  }

  const parsed = contactSchema.safeParse(payload);
  if (!parsed.success) {
    return NextResponse.json(
      {
        error: "Dados inválidos.",
        issues: parsed.error.issues.map((i) => ({
          path: i.path.join("."),
          message: i.message,
        })),
      },
      { status: 400 }
    );
  }

  const data = parsed.data;

  // Honeypot — campo "website" deve vir vazio. Se preenchido, é bot.
  if (data.website && data.website.length > 0) {
    // Resposta intencionalmente "200 OK" para não dar feedback ao bot.
    return NextResponse.json({ ok: true });
  }

  const toEmail =
    process.env.CONTACT_TO_EMAIL ||
    siteConfig.contact.email ||
    "contato@ecoleta.com";
  const fromEmail = process.env.CONTACT_FROM_EMAIL || "noreply@ecoleta.com";
  const fromName = process.env.CONTACT_FROM_NAME || "Site Ecoleta";

  const subject = "Novo contato pelo site Ecoleta";

  const safe = {
    nome: sanitizeHeaderValue(data.nome),
    email: sanitizeHeaderValue(data.email),
    telefone: sanitizeHeaderValue(data.telefone),
    empresa: sanitizeHeaderValue(data.empresa),
    tipoOperacao: sanitizeHeaderValue(data.tipoOperacao),
    mensagem: data.mensagem,
  };

  const textBody = [
    "Novo contato recebido pelo site Ecoleta",
    "",
    `Nome: ${safe.nome}`,
    `E-mail: ${safe.email}`,
    `Telefone/WhatsApp: ${safe.telefone}`,
    `Empresa: ${safe.empresa}`,
    `Tipo de operação: ${safe.tipoOperacao}`,
    "",
    "Mensagem:",
    safe.mensagem,
    "",
    `Data/Hora: ${new Date().toLocaleString("pt-BR", { timeZone: "America/Sao_Paulo" })}`,
    "Origem: Site Ecoleta",
  ].join("\n");

  const htmlBody = `
    <div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif; max-width: 560px; margin: 0 auto; padding: 24px; color: #242424;">
      <h1 style="font-size: 18px; margin: 0 0 16px; color: #0D1F0F;">Novo contato pelo site Ecoleta</h1>
      <table style="width: 100%; border-collapse: collapse; margin-bottom: 16px;">
        <tr><td style="padding: 8px 0; color: #5a5a5a; width: 160px;">Nome</td><td style="padding: 8px 0; font-weight: 600;">${escapeHtml(safe.nome)}</td></tr>
        <tr><td style="padding: 8px 0; color: #5a5a5a;">E-mail</td><td style="padding: 8px 0;"><a href="mailto:${escapeHtml(safe.email)}" style="color: #2D5934;">${escapeHtml(safe.email)}</a></td></tr>
        <tr><td style="padding: 8px 0; color: #5a5a5a;">Telefone/WhatsApp</td><td style="padding: 8px 0;">${escapeHtml(safe.telefone)}</td></tr>
        <tr><td style="padding: 8px 0; color: #5a5a5a;">Empresa</td><td style="padding: 8px 0; font-weight: 600;">${escapeHtml(safe.empresa)}</td></tr>
        <tr><td style="padding: 8px 0; color: #5a5a5a;">Tipo de operação</td><td style="padding: 8px 0;">${escapeHtml(safe.tipoOperacao)}</td></tr>
      </table>
      <div style="background: #ECF5FB; border-left: 4px solid #7ED957; padding: 16px; border-radius: 6px; margin-bottom: 16px;">
        <p style="margin: 0 0 8px; color: #5a5a5a; font-size: 13px; text-transform: uppercase; letter-spacing: 0.1em;">Mensagem</p>
        <p style="margin: 0; white-space: pre-wrap;">${escapeHtml(safe.mensagem)}</p>
      </div>
      <p style="margin: 0; color: #5a5a5a; font-size: 12px;">Origem: Site Ecoleta · ${new Date().toLocaleString("pt-BR", { timeZone: "America/Sao_Paulo" })}</p>
    </div>
  `;

  // --- Envio ---
  // Estratégia: tenta Resend primeiro; se RESEND_API_KEY não estiver setada,
  // tenta SMTP via Nodemailer (precisa instalar nodemailer e setar SMTP_*).
  // Em dev, sem nada configurado, apenas loga e responde 200 OK.
  try {
    const resendApiKey = process.env.RESEND_API_KEY;
    if (resendApiKey) {
      const { Resend } = await import("resend");
      const resend = new Resend(resendApiKey);
      const { error } = await resend.emails.send({
        from: `${fromName} <${fromEmail}>`,
        to: [toEmail],
        replyTo: safe.email,
        subject,
        text: textBody,
        html: htmlBody,
      });
      if (error) {
        console.error("[contact] Resend error:", error);
        return NextResponse.json(
          { error: "Falha ao enviar a mensagem." },
          { status: 500 }
        );
      }
      return NextResponse.json({ ok: true });
    }

    // SMTP fallback (requer `npm install nodemailer @types/nodemailer`)
    if (process.env.SMTP_HOST && process.env.SMTP_USER && process.env.SMTP_PASS) {
      try {
        // Import dinâmico opcional. Indireção via variável evita erro de
        // typecheck quando nodemailer não estiver instalado.
        const nodemailerSpec = "nodemailer";
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const nodemailer: any = await import(/* webpackIgnore: true */ nodemailerSpec).catch(
          () => null
        );
        if (!nodemailer) {
          console.error(
            "[contact] SMTP configurado mas nodemailer não está instalado. Rode: npm install nodemailer @types/nodemailer"
          );
          return NextResponse.json(
            { error: "Serviço de e-mail indisponível." },
            { status: 500 }
          );
        }
        const transporter = nodemailer.createTransport({
          host: process.env.SMTP_HOST,
          port: Number(process.env.SMTP_PORT || 587),
          secure: process.env.SMTP_SECURE === "true",
          auth: {
            user: process.env.SMTP_USER,
            pass: process.env.SMTP_PASS,
          },
        });
        await transporter.sendMail({
          from: `${fromName} <${fromEmail}>`,
          to: toEmail,
          replyTo: safe.email,
          subject,
          text: textBody,
          html: htmlBody,
        });
        return NextResponse.json({ ok: true });
      } catch (e) {
        console.error("[contact] SMTP error:", e);
        return NextResponse.json(
          { error: "Falha ao enviar a mensagem." },
          { status: 500 }
        );
      }
    }

    // Modo dev: nenhum provider configurado
    if (process.env.NODE_ENV !== "production") {
      console.log("[contact] (dev) Email seria enviado para:", toEmail);
      console.log(textBody);
      return NextResponse.json({ ok: true, dev: true });
    }

    console.error("[contact] Nenhum provider de e-mail configurado.");
    return NextResponse.json(
      { error: "Serviço de e-mail não configurado." },
      { status: 500 }
    );
  } catch (e) {
    console.error("[contact] unexpected error:", e);
    return NextResponse.json(
      { error: "Falha ao enviar a mensagem." },
      { status: 500 }
    );
  }
}
