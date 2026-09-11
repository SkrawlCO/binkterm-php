function vibrate(pattern: number | number[]) {
  if (typeof navigator !== "undefined" && navigator.vibrate) {
    navigator.vibrate(pattern);
  }
}

export const haptics = {
  tap: () => vibrate(10),
  light: () => vibrate(5),
  conflict: () => vibrate([10, 50, 10]),
  success: () => vibrate([50, 50, 50, 50, 100]),
};
