import { WhatsAppIcon } from "@/components/icons";
import { whatsappLink } from "@/lib/site.config";

export default function WhatsAppFloatingButton() {
  return (
    <a
      href={whatsappLink}
      target="_blank"
      rel="noopener noreferrer"
      aria-label="Falar com especialista no WhatsApp"
      className="fixed bottom-5 right-5 md:bottom-8 md:right-8 z-50 group inline-flex items-center gap-3 px-5 py-3 md:px-6 md:py-4 rounded-full bg-[#25D366] text-white shadow-[0_10px_30px_-8px_rgba(37,211,102,0.6)] hover:shadow-[0_14px_36px_-8px_rgba(37,211,102,0.8)] hover:-translate-y-0.5 transition-all duration-200"
    >
      <WhatsAppIcon width={22} height={22} />
      <span className="hidden md:inline text-sm font-semibold">
        Falar com especialista
      </span>
    </a>
  );
}
