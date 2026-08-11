import { DashboardHeader } from "@/components/DashboardHeader";
import { PowerBIViewer } from "@/components/PowerBIViewer";

export default function DashboardPage() {
  return (
    <div className="min-h-screen bg-[var(--color-bg-dark)] text-white flex flex-col font-sans">
      <DashboardHeader />
      <main className="flex-1 p-3 sm:p-6 max-w-[1800px] w-full mx-auto flex flex-col">
        <PowerBIViewer />
      </main>
    </div>
  );
}
