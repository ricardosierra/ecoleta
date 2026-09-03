import type { Metadata } from "next";
import { createPageMetadata } from "@/lib/seo";
import Section from "@/components/Section";
import PageHero from "@/components/PageHero";
import { siteConfig } from "@/lib/site.config";

export const metadata: Metadata = {
  ...createPageMetadata({
    title: "Política de Privacidade",
    description: "Política de privacidade e termos de uso dos dados na Ecoleva.",
    path: "/politica-de-privacidade",
  }),
};

export default function PrivacyPolicyPage() {
  return (
    <>
      <PageHero
        eyebrow="Legal"
        title={
          <>
            Política de <span className="text-(--color-accent)">Privacidade</span>
          </>
        }
        subtitle="Saiba como a Ecoleva trata e protege os seus dados pessoais."
      />

      <Section tone="white" id="politica">
        <div className="prose prose-slate max-w-3xl mx-auto text-(--color-text-muted)">
          <h2 className="text-2xl font-semibold text-(--color-text) mt-8 mb-4">1. Introdução</h2>
          <p className="mb-4">
            A <strong>{siteConfig.legalName}</strong> (inscrita sob o CNPJ {siteConfig.company.cnpj})
            tem o compromisso de proteger a sua privacidade. Esta política descreve como
            coletamos, usamos e protegemos os dados pessoais que você nos fornece ao utilizar
            o site {siteConfig.url} e nossos serviços.
          </p>

          <h2 className="text-2xl font-semibold text-(--color-text) mt-8 mb-4">2. Coleta de Dados</h2>
          <p className="mb-4">
            Podemos coletar as seguintes informações:
          </p>
          <ul className="list-disc pl-5 mb-4">
            <li>Nome e informações de contato (e-mail, telefone/WhatsApp);</li>
            <li>Informações da sua empresa e cargo;</li>
            <li>Dados de navegação, como endereço de IP, tipo de navegador e páginas visitadas.</li>
          </ul>

          <h2 className="text-2xl font-semibold text-(--color-text) mt-8 mb-4">3. Uso das Informações</h2>
          <p className="mb-4">
            Utilizamos as informações coletadas para:
          </p>
          <ul className="list-disc pl-5 mb-4">
            <li>Responder às suas solicitações de contato e orçamentos;</li>
            <li>Prestar nossos serviços de gestão de resíduos e conformidade ambiental;</li>
            <li>Melhorar nosso site e personalizar a sua experiência;</li>
            <li>Cumprir obrigações legais e regulatórias.</li>
          </ul>

          <h2 className="text-2xl font-semibold text-(--color-text) mt-8 mb-4">4. Compartilhamento de Dados</h2>
          <p className="mb-4">
            Não vendemos nem alugamos suas informações pessoais. Podemos compartilhar dados
            com parceiros de tecnologia e prestadores de serviço (como plataformas de e-mail)
            apenas para a execução das finalidades previstas nesta política. Todos os
            parceiros estão sujeitos a obrigações de confidencialidade e segurança.
          </p>

          <h2 className="text-2xl font-semibold text-(--color-text) mt-8 mb-4">5. Segurança</h2>
          <p className="mb-4">
            Adotamos medidas técnicas e organizacionais adequadas para proteger seus
            dados pessoais contra acessos não autorizados, perdas, destruição ou
            alterações.
          </p>

          <h2 className="text-2xl font-semibold text-(--color-text) mt-8 mb-4">6. Seus Direitos (LGPD)</h2>
          <p className="mb-4">
            De acordo com a Lei Geral de Proteção de Dados (LGPD), você tem o direito de:
          </p>
          <ul className="list-disc pl-5 mb-4">
            <li>Confirmar a existência de tratamento de seus dados;</li>
            <li>Acessar e corrigir dados incompletos, inexatos ou desatualizados;</li>
            <li>Solicitar a anonimização, bloqueio ou eliminação de dados desnecessários;</li>
            <li>Revogar o seu consentimento, quando aplicável.</li>
          </ul>

          <h2 className="text-2xl font-semibold text-(--color-text) mt-8 mb-4">7. Contato</h2>
          <p className="mb-4">
            Para exercer seus direitos ou tirar dúvidas sobre esta Política de Privacidade,
            entre em contato pelo e-mail: <a href={`mailto:${siteConfig.contact.email}`} className="text-(--color-accent) hover:underline">{siteConfig.contact.email}</a>.
          </p>

          <p className="text-sm mt-12 text-(--color-text-muted)/70">
            Última atualização: {new Date().toLocaleDateString('pt-BR')}
          </p>
        </div>
      </Section>
    </>
  );
}
