type Props = {
  value: number; // 0-100
  label: string;
  size?: number;
};

export default function DonutChart({ value, label, size = 220 }: Props) {
  const stroke = 18;
  const radius = (size - stroke) / 2;
  const circumference = 2 * Math.PI * radius;
  const offset = circumference - (value / 100) * circumference;

  return (
    <div className="relative" style={{ width: size, height: size }}>
      <svg
        width={size}
        height={size}
        viewBox={`0 0 ${size} ${size}`}
        className="-rotate-90"
        aria-hidden
      >
        <circle
          cx={size / 2}
          cy={size / 2}
          r={radius}
          fill="none"
          stroke="rgba(255,255,255,0.08)"
          strokeWidth={stroke}
        />
        <circle
          cx={size / 2}
          cy={size / 2}
          r={radius}
          fill="none"
          stroke="var(--color-accent)"
          strokeWidth={stroke}
          strokeDasharray={circumference}
          strokeDashoffset={offset}
          strokeLinecap="round"
          style={{ transition: "stroke-dashoffset 1s ease-out" }}
        />
      </svg>
      <div
        className="absolute inset-0 flex flex-col items-center justify-center text-center"
        aria-label={`${value}% ${label}`}
      >
        <span className="text-5xl md:text-6xl font-bold text-(--color-accent) leading-none">
          {value}%
        </span>
        <span className="mt-2 text-xs uppercase tracking-widest text-white/60">
          {label}
        </span>
      </div>
    </div>
  );
}
