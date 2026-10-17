import { ChangeDetectionStrategy, Component, Input } from '@angular/core';
import { NgIf } from '@angular/common';

export type FocoLogoVariant = 'symbol' | 'badge' | 'full';

/**
 * Logo Foco. A cor vem de `currentColor`, por isso basta uma classe de cor do Tailwind:
 *   <foco-logo class="text-primary" [size]="32"></foco-logo>              símbolo
 *   <foco-logo variant="badge" class="text-primary"></foco-logo>          ícone de app
 *   <foco-logo variant="full" class="text-primary" [size]="36"></foco-logo>  símbolo + "Foco"
 *   <foco-logo class="text-white"></foco-logo>                            sobre a sidebar colorida
 * `size` = altura em px.
 */
@Component({
  selector: 'foco-logo',
  standalone: true,
  imports: [NgIf],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { class: 'inline-flex shrink-0 items-center' },
  template: `
    <svg *ngIf="variant === 'symbol'" viewBox="0 0 64 64" fill="none" role="img" aria-label="Foco"
         [attr.width]="size" [attr.height]="size">
      <g stroke="currentColor" stroke-width="6" stroke-linecap="round" stroke-linejoin="round">
        <path d="M56 46 32 60 8 46V18L32 4l14.4 8.4" />
        <path d="M23 46V22h33M23 34h20" />
      </g>
    </svg>

    <svg *ngIf="variant === 'badge'" viewBox="0 0 64 64" fill="none" role="img" aria-label="Foco"
         [attr.width]="size" [attr.height]="size">
      <rect width="64" height="64" rx="16" fill="currentColor" />
      <g transform="translate(12 12) scale(.625)" stroke="#fff" stroke-width="6" stroke-linecap="round" stroke-linejoin="round">
        <path d="M56 46 32 60 8 46V18L32 4l14.4 8.4" />
        <path d="M23 46V22h33M23 34h20" />
      </g>
    </svg>

    <svg *ngIf="variant === 'full'" viewBox="0 0 204 64" fill="none" role="img" aria-label="Foco"
         [attr.width]="size * 3.1875" [attr.height]="size">
      <g stroke="currentColor" stroke-width="6" stroke-linecap="round" stroke-linejoin="round">
        <path d="M56 46 32 60 8 46V18L32 4l14.4 8.4" />
        <path d="M23 46V22h33M23 34h20" />
      </g>
      <g [class]="textClass" stroke="currentColor" stroke-width="6" stroke-linecap="round" stroke-linejoin="round" transform="translate(-8 0)">
        <path d="M90 48V16h20M90 31h14" />
        <circle cx="132" cy="38" r="10" />
        <path d="M171.07 30.93A10 10 0 1 0 171.07 45.07" />
        <circle cx="193" cy="38" r="10" />
      </g>
    </svg>
  `,
})
export class FocoLogoComponent {
  @Input() variant: FocoLogoVariant = 'symbol';
  @Input() size = 32;
  /** Cor do texto "Foco" na variante `full`. */
  @Input() textClass = 'text-slate-900 dark:text-white';
}
