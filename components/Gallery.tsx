import Image from "next/image";

type Item = {
  src: string;
  label: string;
  caption: string;
};

type Props = {
  items: Item[];
};

export default function Gallery({ items }: Props) {
  return (
    <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      {items.map((it) => (
        <li
          key={it.src}
          className="group relative aspect-[4/3] rounded-[10px] overflow-hidden border border-(--color-border-light)"
        >
          <Image
            src={it.src}
            alt={it.caption}
            fill
            sizes="(max-width: 640px) 100vw, (max-width: 1024px) 50vw, 25vw"
            className="object-cover transition-transform duration-500 group-hover:scale-105"
          />
          <div className="absolute inset-x-0 bottom-0 px-4 py-3 bg-gradient-to-t from-black/70 to-transparent">
            <p className="text-white text-xs font-semibold leading-snug">
              {it.label}
            </p>
            <p className="text-white/70 text-[11px] mt-0.5">{it.caption}</p>
          </div>
        </li>
      ))}
    </ul>
  );
}
