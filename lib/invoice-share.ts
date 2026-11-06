/**
 * Link `wa.me` para mandar a cobrança pelo WhatsApp do próprio operador, sem custo.
 *
 * O texto vem pronto da API (`whatsapp_text`, montado por `billingShareText()` em
 * `public/api/billing_lib.php`, o mesmo que o robô envia). Aqui só se monta o link.
 * Ele precisa ser aberto dentro do clique, sem esperar rede, senão o navegador
 * bloqueia a janela.
 */

/** Sem número cadastrado o link sai sem destinatário: o WhatsApp pede o contato com o texto já escrito. */
export function invoiceWhatsAppLink(phone: string | null | undefined, text: string): string {
  const digits = (phone ?? "").replace(/\D/g, "");
  const query = `?text=${encodeURIComponent(text)}`;

  return digits === "" ? `https://wa.me/${query}` : `https://wa.me/${digits}${query}`;
}
