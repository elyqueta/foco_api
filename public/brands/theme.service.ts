import { DOCUMENT } from '@angular/common';
import { Injectable, inject } from '@angular/core';
import { focoBadgeSvg, svgToDataUri } from './foco-brand';

const KEY_COLOR = 'foco.theme.color';
const KEY_MODE = 'foco.theme.mode';
export const DEFAULT_PRIMARY = '#DC2626';

const hexToRgb = (hex: string): [number, number, number] => {
  const h = hex.replace('#', '');
  const f = h.length === 3 ? h.split('').map((c) => c + c).join('') : h;
  return [parseInt(f.slice(0, 2), 16), parseInt(f.slice(2, 4), 16), parseInt(f.slice(4, 6), 16)];
};
const shade = ([r, g, b]: number[], k: number) => [r, g, b].map((v) => Math.round(v * (1 - k)));
const luminance = ([r, g, b]: number[]) => (0.299 * r + 0.587 * g + 0.114 * b) / 255;

/**
 * Aplica a cor do tema: define as CSS variables usadas pelos tokens Tailwind
 * (primary, primary-dark, primary-fg) e actualiza o favicon + theme-color.
 */
@Injectable({ providedIn: 'root' })
export class ThemeService {
  private readonly doc = inject(DOCUMENT);

  init(): void {
    this.setColor(localStorage.getItem(KEY_COLOR) ?? DEFAULT_PRIMARY, false);
    this.setMode((localStorage.getItem(KEY_MODE) as 'light' | 'dark') ?? 'light', false);
  }

  setColor(hex: string, persist = true): void {
    const rgb = hexToRgb(hex);
    const root = this.doc.documentElement.style;
    root.setProperty('--primary', rgb.join(' '));
    root.setProperty('--primary-dark', shade(rgb, 0.22).join(' '));
    root.setProperty('--primary-fg', luminance(rgb) > 0.6 ? '17 24 39' : '255 255 255');
    root.setProperty('--foco-logo', hex); // para os .svg usados via <img>/inline
    this.setFavicon(hex);
    if (persist) localStorage.setItem(KEY_COLOR, hex);
  }

  setMode(mode: 'light' | 'dark', persist = true): void {
    this.doc.documentElement.classList.toggle('dark', mode === 'dark');
    if (persist) localStorage.setItem(KEY_MODE, mode);
  }

  private setFavicon(hex: string): void {
    let link = this.doc.querySelector<HTMLLinkElement>('link[rel="icon"]');
    if (!link) {
      link = this.doc.createElement('link');
      link.rel = 'icon';
      this.doc.head.appendChild(link);
    }
    link.type = 'image/svg+xml';
    link.href = svgToDataUri(focoBadgeSvg(hex));
    this.doc.querySelector('meta[name="theme-color"]')?.setAttribute('content', hex);
  }
}
