export function formatTime(totalSeconds: number): string {
  const mins = Math.floor(totalSeconds / 60);
  const secs = totalSeconds % 60;
  return `${String(mins).padStart(2, "0")}:${String(secs).padStart(2, "0")}`;
}

const SHORT_MONTHS = [
  "Jan",
  "Feb",
  "Mar",
  "Apr",
  "May",
  "Jun",
  "Jul",
  "Aug",
  "Sep",
  "Oct",
  "Nov",
  "Dec",
];

export function formatShortDate(isoDate: string): string {
  const parts = isoDate.split("-");
  const monthIndex = Number.parseInt(parts[1] ?? "1", 10) - 1;
  const monthName = SHORT_MONTHS[monthIndex] ?? "Jan";
  return `${monthName} ${Number.parseInt(parts[2] ?? "1", 10)}`;
}
