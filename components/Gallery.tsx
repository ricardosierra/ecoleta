type Item = {
  label: string;
  caption: string;
};

type Props = {
  items: Item[];
};

/**
 * Placeholder responsivo para galeria. Quando as imagens reais chegarem,
 * trocar o <div> de placeholder por <Image src={item.src} ...>.
 */
export default function Gallery({ items }: Props) {
  return (
    <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      {items.map((it) => (
        <li
          key={it.label}
          className="group relative aspect-[4/3] rounded-[10px] overflow-hidden border border-(--color-border-light) bg-(--color-bg-light)"
        >
          <div
            className="absolute inset-0 flex items-center justify-center bg-gradient-to-br from-(--color-bg-light) to-white"
            style={{
              backgroundImage:
                "radial-gradient(circle at 30% 20%, rgba(126,217,87,0.18) 0%, transparent 60%), radial-gradient(circle at 80% 80%, rgba(45,89,52,0.12) 0%, transparent 60%)",
            }}
          >
            <span className="text-xs uppercase tracking-widest text-(--color-secondary) font-semibold text-center px-4">
              {it.label}
            </span>
          </div>
          <div className="absolute inset-x-0 bottom-0 px-4 py-3 bg-gradient-to-t from-black/60 to-transparent text-white text-xs">
            {it.caption}
          </div>
          <div className="absolute top-3 right-3 px-2.5 py-1 rounded-full bg-white/90 text-[10px] uppercase tracking-widest text-(--color-secondary) font-semibold">
            placeholder
          </div>
        </li>
      ))}
    </ul>
  );
}
