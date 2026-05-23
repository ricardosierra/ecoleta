/**
 * Ícones inline em SVG, peso fino (line/outline).
 * Mantemos sem dependência externa para zero overhead.
 */

import type { SVGProps } from "react";

const baseProps: SVGProps<SVGSVGElement> = {
  viewBox: "0 0 24 24",
  fill: "none",
  stroke: "currentColor",
  strokeWidth: 1.6,
  strokeLinecap: "round",
  strokeLinejoin: "round",
  width: 24,
  height: 24,
  "aria-hidden": true,
};

const wasteIconProps: SVGProps<SVGSVGElement> = {
  viewBox: "0 0 64 64",
  fill: "none",
  width: 48,
  height: 48,
  "aria-hidden": true,
};

const wasteStrokeProps: SVGProps<SVGSVGElement> = {
  strokeWidth: 3.2,
  strokeLinecap: "round",
  strokeLinejoin: "round",
};

const wasteMainStroke = "var(--color-white)";
const wasteAccentStroke = "var(--color-accent)";

const impactIconProps: SVGProps<SVGSVGElement> = {
  viewBox: "0 0 64 64",
  fill: "none",
  stroke: "currentColor",
  strokeWidth: 2.8,
  strokeLinecap: "round",
  strokeLinejoin: "round",
  width: 56,
  height: 56,
  "aria-hidden": true,
};

export const CheckIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...baseProps} {...p}>
    <path d="m4.5 12.5 5 5 10-11" />
  </svg>
);

export const ArrowRightIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...baseProps} {...p}>
    <path d="M5 12h14" />
    <path d="m13 6 6 6-6 6" />
  </svg>
);

export const AlertTriangleIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...baseProps} {...p}>
    <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z" />
    <line x1="12" y1="9" x2="12" y2="13" />
    <line x1="12" y1="17" x2="12.01" y2="17" />
  </svg>
);

export const ShuffleIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...baseProps} {...p}>
    <path d="M16 3h5v5" />
    <path d="M4 20 21 3" />
    <path d="M21 16v5h-5" />
    <path d="m15 15 6 6" />
    <path d="M4 4l5 5" />
  </svg>
);

export const ScaleIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...baseProps} {...p}>
    <path d="M12 3v18" />
    <path d="m16 6 4 8a4 4 0 0 1-8 0l4-8Z" />
    <path d="m8 6-4 8a4 4 0 0 0 8 0L8 6Z" />
    <path d="M8 21h8" />
  </svg>
);

export const FileTextIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...baseProps} {...p}>
    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
    <polyline points="14 2 14 8 20 8" />
    <line x1="16" y1="13" x2="8" y2="13" />
    <line x1="16" y1="17" x2="8" y2="17" />
    <line x1="10" y1="9" x2="8" y2="9" />
  </svg>
);

export const BoxesIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...baseProps} {...p}>
    <path d="M2.97 12.92A2 2 0 0 0 2 14.63v3.24a2 2 0 0 0 .97 1.71l3 1.8a2 2 0 0 0 2.06 0L12 19v-5.5l-5-3-4.03 2.42Z" />
    <path d="m7 16.5-4.74-2.85" />
    <path d="m7 16.5 5-3" />
    <path d="M7 16.5v5.17" />
    <path d="M12 13.5V19l3.97 2.38a2 2 0 0 0 2.06 0l3-1.8a2 2 0 0 0 .97-1.71v-3.24a2 2 0 0 0-.97-1.71L17 10.5l-5 3Z" />
    <path d="m17 16.5-5-3" />
    <path d="m17 16.5 4.74-2.85" />
    <path d="M17 16.5v5.17" />
    <path d="M7.97 4.42A2 2 0 0 0 7 6.13v4.37l5 3 5-3V6.13a2 2 0 0 0-.97-1.71l-3-1.8a2 2 0 0 0-2.06 0Z" />
    <path d="M12 8 7.26 5.15" />
    <path d="m12 8 4.74-2.85" />
    <path d="M12 13.5V8" />
  </svg>
);

export const ShieldIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...baseProps} {...p}>
    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z" />
    <path d="m9 12 2 2 4-4" />
  </svg>
);

export const LeafIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...baseProps} {...p}>
    <path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z" />
    <path d="M2 21c0-3 1.85-5.36 5.08-6" />
  </svg>
);

export const UsersIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...baseProps} {...p}>
    <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
    <circle cx="8.5" cy="7" r="4" />
    <path d="M22 21v-2a4 4 0 0 0-3-3.87" />
    <path d="M16 3.13a4 4 0 0 1 0 7.75" />
  </svg>
);

export const SettingsIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...baseProps} {...p}>
    <circle cx="12" cy="12" r="3" />
    <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z" />
  </svg>
);

export const TrendingDownIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...baseProps} {...p}>
    <polyline points="22 17 13.5 8.5 8.5 13.5 2 7" />
    <polyline points="16 17 22 17 22 11" />
  </svg>
);

export const ClipboardIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...baseProps} {...p}>
    <rect x="8" y="2" width="8" height="4" rx="1" ry="1" />
    <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2" />
    <line x1="9" y1="12" x2="15" y2="12" />
    <line x1="9" y1="16" x2="15" y2="16" />
  </svg>
);

export const SeedlingIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...baseProps} {...p}>
    <path d="M12 21V11" />
    <path d="M21 7c-3 0-6 2-9 6 0-3 2-6 6-7 1.5-.5 3-.5 3 1Z" />
    <path d="M3 7c3 0 6 2 9 6 0-3-2-6-6-7-1.5-.5-3-.5-3 1Z" />
  </svg>
);

export const RecycleIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...baseProps} {...p}>
    <path d="M7 19H4a2 2 0 0 1-1.69-3.06L5 11" />
    <path d="m14 16-3 3 3 3" />
    <path d="M10 6.13 11.65 3.4a2 2 0 0 1 3.45.04l2.51 4.13" />
    <path d="m18 9 3-3-3-3" />
    <path d="M19.45 18.96A2 2 0 0 1 17.6 22h-3.5" />
  </svg>
);

export const WhatsAppIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg
    viewBox="0 0 24 24"
    fill="currentColor"
    width={24}
    height={24}
    aria-hidden
    {...p}
  >
    <path d="M.057 24l1.687-6.163A11.867 11.867 0 0 1 .003 11.86C0 5.328 5.32.01 11.85.01a11.81 11.81 0 0 1 8.413 3.488 11.82 11.82 0 0 1 3.48 8.41c-.003 6.532-5.323 11.85-11.853 11.85a11.9 11.9 0 0 1-5.661-1.444L.057 24zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884a9.86 9.86 0 0 0 1.51 5.26l-.999 3.648 3.978-1.607zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z" />
  </svg>
);

export const InstagramIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...baseProps} {...p}>
    <rect x="2" y="2" width="20" height="20" rx="5" ry="5" />
    <path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z" />
    <line x1="17.5" y1="6.5" x2="17.51" y2="6.5" />
  </svg>
);

export const LinkedInIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...baseProps} {...p}>
    <path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-4 0v7h-4v-7a6 6 0 0 1 6-6z" />
    <rect x="2" y="9" width="4" height="12" />
    <circle cx="4" cy="4" r="2" />
  </svg>
);

export const MenuIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...baseProps} {...p}>
    <line x1="4" y1="7" x2="20" y2="7" />
    <line x1="4" y1="12" x2="20" y2="12" />
    <line x1="4" y1="17" x2="20" y2="17" />
  </svg>
);

export const CloseIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...baseProps} {...p}>
    <line x1="6" y1="6" x2="18" y2="18" />
    <line x1="18" y1="6" x2="6" y2="18" />
  </svg>
);

export const ZapIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...baseProps} {...p}>
    <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2" />
  </svg>
);

export const CarIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...baseProps} {...p}>
    <rect x="1" y="3" width="15" height="13" rx="2" />
    <path d="M16 8h4l3 3v5h-7V8z" />
    <circle cx="5.5" cy="18.5" r="2.5" />
    <circle cx="18.5" cy="18.5" r="2.5" />
  </svg>
);

export const DropletIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...baseProps} {...p}>
    <path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z" />
  </svg>
);

export const ImpactPeopleIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...impactIconProps} {...p}>
    <path d="M14 25.5 27 15l10 7.5L52 10" />
    <path d="M52 10v10" />
    <path d="M42 10h10" />
    <circle cx="20" cy="36" r="4.5" />
    <circle cx="32" cy="33.5" r="4.5" />
    <circle cx="44" cy="36" r="4.5" />
    <path d="M12 52v-3.5c0-5 3.3-8.2 8-8.2s8 3.2 8 8.2V52" />
    <path d="M24 52v-5.3c0-5.2 3.4-8.5 8-8.5s8 3.3 8 8.5V52" />
    <path d="M36 52v-3.5c0-5 3.3-8.2 8-8.2s8 3.2 8 8.2V52" />
  </svg>
);

export const ImpactCarbonIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...impactIconProps} {...p}>
    <path d="M20.5 42h25A11.2 11.2 0 0 0 46 19.6a15.2 15.2 0 0 0-29.5 5A8.8 8.8 0 0 0 20.5 42Z" />
    <text
      x="32"
      y="34"
      fill="currentColor"
      stroke="none"
      textAnchor="middle"
      fontSize="11"
      fontWeight="700"
    >
      CO2
    </text>
    <path d="M32 45v10" />
    <path d="m26.5 49.5 5.5 5.5 5.5-5.5" />
  </svg>
);

export const ImpactEnergyIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...impactIconProps} {...p}>
    <path d="M27 8 13.5 35H28l-5 21 20-30H29l6-18Z" />
    <path d="M48 18v11" />
    <path d="M56 18v11" />
    <path d="M44 29h16" />
    <path d="M52 29v8.5c0 6-4 10-10 10h-2" />
    <path d="M32 47.5h8" />
  </svg>
);

export const ImpactTreesIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...impactIconProps} {...p}>
    <path d="M31 42H18.5A10.5 10.5 0 0 1 17 21.1a15 15 0 0 1 29.1 4.8" />
    <path d="M32 31v23" />
    <path d="m23 38 9 7 9-7" />
    <path d="M21 54h22" />
    <circle cx="47" cy="43" r="10" />
    <path d="m42.5 43 3 3 6-6" />
  </svg>
);

export const ImpactCarsIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...impactIconProps} {...p}>
    <path d="M14 35h32l-4.5-12.5a5 5 0 0 0-4.7-3.3H23.2a5 5 0 0 0-4.7 3.3L14 35Z" />
    <path d="M14 35v12h8" />
    <path d="M42 47h4" />
    <path d="M20 47a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z" />
    <path d="M40 47a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z" />
    <path d="M21 27h18" />
    <circle cx="49" cy="43" r="10" />
    <path d="M44.5 43h9" />
  </svg>
);

export const ImpactWaterIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...impactIconProps} {...p}>
    <path d="M28 9C20 20.5 14 30 14 39.5 14 49 20.2 55 28 55s14-6 14-15.5C42 30 36 20.5 28 9Z" />
    <path d="M22 43c1.5 3 3.8 4.5 7 4.5" />
    <path d="M48 13v42" />
    <path d="M48 16h7" />
    <path d="M48 25h5" />
    <path d="M48 34h7" />
    <path d="M48 43h5" />
    <path d="M48 52h7" />
  </svg>
);

export const WasteRecyclablesIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...wasteIconProps} {...p}>
    <g {...wasteStrokeProps} stroke={wasteMainStroke}>
      <path d="M19 12h10" />
      <path d="M21 12v6c-3.2 2.3-5 5.7-5 9.6V51c0 3 2.4 5 5.4 5h8.2c3 0 5.4-2 5.4-5V27.6c0-3.9-1.8-7.3-5-9.6v-6" />
      <path d="M16.5 27h18" />
      <path d="M16.5 44.5h18" />
      <path d="M40 34h8.8c2.2 0 3.8 1.6 3.8 3.8v14c0 2.2-1.6 3.8-3.8 3.8H40c-2.2 0-3.8-1.6-3.8-3.8v-14c0-2.2 1.6-3.8 3.8-3.8Z" />
      <path d="M36.8 40.5h15.1" />
    </g>
    <g {...wasteStrokeProps} stroke={wasteAccentStroke}>
      <path d="m48.5 10 3.2 5.5h-6.3" />
      <path d="m51.7 15.5-1.4-2.4" />
      <path d="m55.3 21.1-6.4.1 3.1-5.5" />
      <path d="m48.9 21.2 2.7 1.6" />
      <path d="m44.6 20.8 3.2-5.5 3.1 5.4" />
      <path d="m47.8 15.3-3.2.2" />
    </g>
  </svg>
);

export const WasteOrganicsIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...wasteIconProps} {...p}>
    <g {...wasteStrokeProps} stroke={wasteMainStroke}>
      <path d="M28.5 14c2.7 11 2.5 25.4-.6 38" />
      <path d="M23.5 17.5c3.1 9.4.8 20.2-10.6 28.2 8.5 4.1 17.4.5 20.3-11.1" />
      <path d="M35.2 17.2c-4.2 9-2.9 20.2 9.7 28.4-8.7 3.8-17.2.8-20.2-10.8" />
      <path d="M20.8 52.4c6.9 3 14.1 2.3 21.6-2.2" />
      <path d="M27.9 13.7c2.3-1.7 4.9-1.7 7.3.1" />
    </g>
    <g {...wasteStrokeProps} stroke={wasteAccentStroke}>
      <path d="M40 22.4c3.4-8.5 11.6-8.1 17.2-12.1-.5 8.8-4.6 15.1-12.3 15.7-2.4.2-4-.7-4.9-3.6Z" />
      <path d="M38.1 27.2c4.4-5.6 10.1-10.5 17.5-15.2" />
    </g>
  </svg>
);

export const WasteRejectsIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...wasteIconProps} {...p}>
    <g {...wasteStrokeProps} stroke={wasteMainStroke}>
      <path d="M24.2 17.8c1.5-5 4.1-7.2 7.8-4.9 3.7-2.3 6.3-.1 7.8 4.9" />
      <path d="M22.2 19.3h19.6" />
      <path d="M21 21.5c-6.2 10.4-8.3 23.1-4.3 29.6C19.2 55.2 24.5 57 32 57s12.8-1.8 15.3-5.9c4-6.5 1.9-19.2-4.3-29.6" />
      <path d="M22.4 22.6c5.2 2.7 14 2.7 19.2 0" />
      <path d="M18.8 50.6c2.7 2 6.9 3 13.2 3s10.5-1 13.2-3" />
    </g>
  </svg>
);

export const WasteInfectantsIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...wasteIconProps} {...p}>
    <g {...wasteStrokeProps} stroke={wasteMainStroke}>
      <circle cx="26" cy="29" r="4.8" />
      <path d="M26.2 24.2c-4.5-4.2-3.5-11 2.3-14.2 4.5 4.4 4.3 11.6.2 15" />
      <path d="M21.6 30.6c-5.8 1.8-11.6-2-12.4-8.5 6-1.9 12.2 1.5 13.7 6.7" />
      <path d="M30.4 30.6c5.8 1.8 11.6-2 12.4-8.5-6-1.9-12.2 1.5-13.7 6.7" />
      <path d="M20.2 39c3.4 3.5 8.3 3.5 11.6 0" />
      <path d="M14.4 34.6c-2.8 1.4-5 4.1-5.6 7.4" />
      <path d="M37.6 34.6c2.8 1.4 5 4.1 5.6 7.4" />
    </g>
    <g {...wasteStrokeProps} stroke={wasteMainStroke}>
      <path d="M43.4 39.8 53.8 50.2" />
      <path d="M39.6 43.6 50 54" />
      <path d="m45.3 37.9-7.6 7.6 8.8 8.8 7.6-7.6Z" />
      <path d="M53.4 34.6 58 39.2" />
      <path d="M56.1 31.9 49.8 38.2" />
    </g>
  </svg>
);

export const WasteTextilesIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...wasteIconProps} {...p}>
    <g {...wasteStrokeProps} stroke={wasteMainStroke}>
      <path d="m23.6 16.4 6.6 4.2h3.6l6.6-4.2 10.1 7.2-5.3 8.6-4.8-3V53H23.6V29.2l-4.8 3-5.3-8.6 10.1-7.2Z" />
      <path d="M30.2 20.6c.3 3.7 7.3 3.7 7.6 0" />
      <path d="M24 45.7h16" />
      <path d="M24 52.8h16.4" />
      <path d="m43.3 34.2 6.9-4.1v20.3l-6.9 4.1" />
      <path d="m44.1 39 5.4-3.2" />
    </g>
  </svg>
);

export const WasteHazardousIcon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...wasteIconProps} {...p}>
    <g {...wasteStrokeProps} stroke={wasteMainStroke}>
      <path d="M18.5 19.5h7.3l4.1-5h15.2l3.9 5h4.5v30.8c0 3.4-2 5.2-5.2 5.2H23.7c-3.2 0-5.2-1.8-5.2-5.2V19.5Z" />
      <path d="M23.2 19.3v-5.5h8.2" />
      <path d="M42.2 14h7.2v5.3" />
      <path d="M24.5 28.1h17.2" />
    </g>
    <g {...wasteStrokeProps} stroke={wasteAccentStroke}>
      <path d="M25.3 34.2v14.2h16.4V34.2Z" strokeDasharray="6 5" />
      <path d="m49.8 39.1 9.3 16.3H40.5l9.3-16.3Z" />
      <path d="M49.8 45.8v4.7" />
      <path d="M49.8 53.8h.1" />
    </g>
  </svg>
);
