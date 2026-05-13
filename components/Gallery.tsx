import Image from "next/image";

type Item = {
  src: string;
  alt: string;
};

type Props = {
  items: Item[];
};

export default function Gallery({ items }: Props) {
  return (
    <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      {items.map((it) => (
        <li
          key={it.src}
          className="group relative aspect-[4/3] rounded-[10px] overflow-hidden border border-(--color-border-light) bg-(--color-bg-light)"
        >
          <Image
            src={it.src}
            alt={it.alt}
            fill
            sizes="(max-width: 640px) 100vw, (max-width: 1024px) 50vw, 33vw"
            className="object-cover transition-transform duration-500 group-hover:scale-105"
          />
        </li>
      ))}
    </ul>
  );
}
