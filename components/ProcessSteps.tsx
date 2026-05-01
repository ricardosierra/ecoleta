type Step = {
  title: string;
  desc: string;
};

type Props = {
  steps: Step[];
};

export default function ProcessSteps({ steps }: Props) {
  return (
    <ol className="relative grid gap-8 md:grid-cols-6 md:gap-4">
      {/* Connector line on desktop */}
      <div
        aria-hidden
        className="hidden md:block absolute top-6 left-[8.33%] right-[8.33%] h-px bg-(--color-accent)/30"
      />
      {steps.map((step, i) => (
        <li key={step.title} className="relative flex md:flex-col gap-4 md:gap-3">
          <span
            className="relative shrink-0 size-12 rounded-full bg-(--color-accent) text-(--color-bg-dark) font-bold flex items-center justify-center z-10 shadow-[0_8px_18px_-6px_rgba(126,217,87,0.7)]"
            aria-hidden
          >
            {i + 1}
          </span>
          <div className="md:text-center">
            <h3 className="font-semibold text-base text-(--color-text)">
              {step.title}
            </h3>
            <p className="mt-1 text-xs md:text-sm text-(--color-text-muted) leading-relaxed">
              {step.desc}
            </p>
          </div>
        </li>
      ))}
    </ol>
  );
}
