export function Sparkline({ values, color }: { values: number[]; color: string }) {
  const width = 90;
  const height = 28;
  const min = Math.min(...values);
  const max = Math.max(...values);
  const range = max - min || 1;
  const points = values.map((v, i) => {
    const x = (i / Math.max(1, values.length - 1)) * width;
    const y = height - ((v - min) / range) * (height - 4) - 2;
    return `${x},${y}`;
  }).join(" ");

  return (
    <svg className="sparkline" viewBox={`0 0 ${width} ${height}`} aria-label={`Trend from ${values[0]} to ${values.at(-1)}`}>
      <polyline fill="none" stroke={color} strokeWidth="2.5" points={points} />
      {values.map((v, i) => {
        const [x, y] = points.split(" ")[i].split(",");
        return <circle key={i} cx={x} cy={y} r="2.2" fill={color} />;
      })}
    </svg>
  );
}
