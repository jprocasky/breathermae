export const clamp = (value: number, min = 0, max = 100): number =>
  Math.min(max, Math.max(min, value));

export const mean = (values: number[]): number =>
  values.length ? values.reduce((sum, value) => sum + value, 0) / values.length : 0;

export const variance = (values: number[]): number => {
  if (values.length < 2) return 0;
  const avg = mean(values);
  return values.reduce((sum, value) => sum + (value - avg) ** 2, 0) / (values.length - 1);
};

export const stdDev = (values: number[]): number => Math.sqrt(variance(values));

export const linearRegression = (values: number[]) => {
  const n = values.length;
  if (n < 2) return { slope: 0, intercept: values[0] ?? 0, r2: 0 };

  const xs = values.map((_, i) => i);
  const xMean = mean(xs);
  const yMean = mean(values);
  const numerator = xs.reduce((sum, x, i) => sum + (x - xMean) * (values[i] - yMean), 0);
  const denominator = xs.reduce((sum, x) => sum + (x - xMean) ** 2, 0);
  const slope = denominator === 0 ? 0 : numerator / denominator;
  const intercept = yMean - slope * xMean;

  const total = values.reduce((sum, y) => sum + (y - yMean) ** 2, 0);
  const residual = values.reduce((sum, y, i) => {
    const predicted = intercept + slope * xs[i];
    return sum + (y - predicted) ** 2;
  }, 0);

  return { slope, intercept, r2: total === 0 ? 1 : Math.max(0, 1 - residual / total) };
};

export const pearson = (a: number[], b: number[]): number => {
  const n = Math.min(a.length, b.length);
  if (n < 2) return 0;
  const aa = a.slice(-n);
  const bb = b.slice(-n);
  const am = mean(aa);
  const bm = mean(bb);
  const numerator = aa.reduce((sum, value, i) => sum + (value - am) * (bb[i] - bm), 0);
  const denomA = Math.sqrt(aa.reduce((sum, value) => sum + (value - am) ** 2, 0));
  const denomB = Math.sqrt(bb.reduce((sum, value) => sum + (value - bm) ** 2, 0));
  if (!denomA || !denomB) return 0;
  return numerator / (denomA * denomB);
};
