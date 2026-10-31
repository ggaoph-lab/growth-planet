import { useEffect, useRef } from "react";

type Star = { x: number; y: number; r: number; a: number; tw: number; ph: number; warm: boolean };

/** Soft night-sky gradient stops keyed by local hour. */
const SKY: [number, string, string, string, number][] = [
  [0, "#05070f", "#080b19", "#0d0c1a", 0.08],
  [4, "#070b18", "#0d1426", "#1c2740", 0.22],
  [6, "#080c1b", "#0c1224", "#141a30", 0.12],
  [17, "#0a0f22", "#121429", "#2a1a2c", 0.32],
  [19, "#070a16", "#0a0e1f", "#110f21", 0.2],
  [23, "#05070f", "#080b19", "#0d0c1a", 0.08],
];

function seedStars(w: number, h: number): Star[] {
  let s = 7;
  const rnd = () => {
    s = (s * 16807) % 2147483647;
    return (s - 1) / 2147483646;
  };
  const n = Math.round(Math.min(220, (w * h) / 5200));
  return Array.from({ length: n }, (_, i) => {
    const y = Math.pow(rnd(), 2.2);
    return {
      x: rnd() * w,
      y: Math.pow(rnd(), 1.35) * h,
      r: 0.35 + y * 1.05,
      a: 0.18 + y * 0.55,
      tw: i % 9 === 0 ? 0.6 + rnd() * 1.2 : 0,
      ph: rnd() * 6.28,
      warm: rnd() < 0.12,
    };
  });
}

function paintSky() {
  const hour = new Date().getHours();
  const [, top, mid, horizon, glow] = [...SKY].reverse().find(([h]) => hour >= h) ?? SKY[0];
  const style = document.documentElement.style;
  style.setProperty("--sky-top", top);
  style.setProperty("--sky-mid", mid);
  style.setProperty("--sky-horizon", horizon);
  style.setProperty("--sky-glow", String(glow));
}

/** Twinkling canvas backdrop used by the starlight skin. */
export function Starfield() {
  const canvas = useRef<HTMLCanvasElement>(null);

  useEffect(() => {
    const el = canvas.current;
    if (!el) return;
    const ctx = el.getContext("2d");
    if (!ctx) return;
    const reduce = matchMedia("(prefers-reduced-motion: reduce)").matches;
    let stars: Star[] = [];
    let layer: HTMLCanvasElement | null = null;
    let w = 0;
    let h = 0;
    let dpr = 1;
    let raf = 0;
    let last = 0;

    const draw = (now: number) => {
      ctx.setTransform(1, 0, 0, 1, 0, 0);
      ctx.clearRect(0, 0, el.width, el.height);
      if (layer?.width && layer.height) ctx.drawImage(layer, 0, 0);
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      const t = now / 1000;
      for (const star of stars) {
        if (!star.tw) continue;
        const pulse = reduce ? 0.8 : 0.55 + 0.45 * Math.sin(t * star.tw + star.ph);
        ctx.globalAlpha = star.a * pulse;
        ctx.fillStyle = star.warm ? "#ffe2b4" : "#f3ecdf";
        ctx.beginPath();
        ctx.arc(star.x, star.y, star.r * (0.9 + 0.2 * pulse), 0, Math.PI * 2);
        ctx.fill();
      }
      ctx.globalAlpha = 1;
    };

    const layout = () => {
      dpr = Math.min(window.devicePixelRatio || 1, 2);
      w = innerWidth;
      h = innerHeight;
      el.width = Math.round(w * dpr);
      el.height = Math.round(h * dpr);
      if (!w || !h) {
        layer = null;
        stars = [];
        return;
      }
      stars = seedStars(w, h);
      layer = document.createElement("canvas");
      layer.width = el.width;
      layer.height = el.height;
      const g = layer.getContext("2d")!;
      g.setTransform(dpr, 0, 0, dpr, 0, 0);
      for (const star of stars) {
        if (star.tw) continue;
        g.globalAlpha = star.a;
        g.fillStyle = star.warm ? "#ffe2b4" : "#f3ecdf";
        g.beginPath();
        g.arc(star.x, star.y, star.r, 0, Math.PI * 2);
        g.fill();
      }
      draw(performance.now());
    };

    const tick = (now: number) => {
      if (now - last > 80) {
        draw(now);
        last = now;
      }
      raf = requestAnimationFrame(tick);
    };
    const start = () => {
      if (reduce || raf || document.hidden) return;
      raf = requestAnimationFrame(tick);
    };
    const stop = () => {
      cancelAnimationFrame(raf);
      raf = 0;
    };

    let resizeTimer = 0;
    const onResize = () => {
      clearTimeout(resizeTimer);
      resizeTimer = window.setTimeout(layout, 150);
    };
    const onVis = () => (document.hidden ? stop() : start());

    layout();
    start();
    paintSky();
    const skyTimer = window.setInterval(paintSky, 600_000);
    addEventListener("resize", onResize);
    document.addEventListener("visibilitychange", onVis);
    return () => {
      stop();
      clearInterval(skyTimer);
      clearTimeout(resizeTimer);
      removeEventListener("resize", onResize);
      document.removeEventListener("visibilitychange", onVis);
    };
  }, []);

  return <canvas ref={canvas} className="starfield" aria-hidden="true" />;
}
