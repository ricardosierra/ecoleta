"use client";

import { useEffect, useRef } from "react";

type Props = {
  className?: string;
  playbackRate?: number;
};

export default function HeroVideo({
  className,
  playbackRate = 0.65,
}: Props) {
  const ref = useRef<HTMLVideoElement>(null);

  useEffect(() => {
    const video = ref.current;
    if (!video) return;
    video.playbackRate = playbackRate;
  }, [playbackRate]);

  return (
    <video
      ref={ref}
      aria-hidden
      autoPlay
      loop
      muted
      playsInline
      className={className}
    >
      <source src="/hero-video.webm" type="video/webm" />
      <source src="/hero-video.mp4" type="video/mp4" />
    </video>
  );
}
