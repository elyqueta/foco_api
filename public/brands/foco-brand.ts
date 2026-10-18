/**
 * Marca Foco em string SVG com cor "hard-coded".
 * Usa isto onde CSS variables/currentColor não funcionam:
 * PDFs, favicon dinâmico, canvas, e-mails.
 */
const SYMBOL =
  '<path d="M56 46 32 60 8 46V18L32 4l14.4 8.4"/><path d="M23 46V22h33M23 34h20"/>';
const STROKE = 'stroke-width="6" stroke-linecap="round" stroke-linejoin="round"';

export const focoSymbolSvg = (color: string): string =>
  `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" fill="none"><g stroke="${color}" ${STROKE}>${SYMBOL}</g></svg>`;

/** Ícone de app / favicon: quadrado arredondado com símbolo branco. */
export const focoBadgeSvg = (color: string, glyph = '#fff'): string =>
  `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" fill="none"><rect width="64" height="64" rx="16" fill="${color}"/><g transform="translate(12 12) scale(.625)" stroke="${glyph}" ${STROKE}>${SYMBOL}</g></svg>`;

/** Logo horizontal (símbolo + "Foco" desenhado em traços, sem depender de fontes). */
export const focoLogoSvg = (color: string, textColor = '#111827'): string =>
  `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 204 64" fill="none"><g stroke="${color}" ${STROKE}>${SYMBOL}</g><g stroke="${textColor}" ${STROKE} transform="translate(-8 0)"><path d="M90 48V16h20M90 31h14"/><circle cx="132" cy="38" r="10"/><path d="M171.07 30.93A10 10 0 1 0 171.07 45.07"/><circle cx="193" cy="38" r="10"/></g></svg>`;

/** Marca de água: tile 240x240 para repetir no fundo de páginas/PDF. */
export const focoWatermarkSvg = (color: string, opacity = 0.07): string =>
  `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 240 240" fill="none"><g opacity="${opacity}" stroke="${color}" ${STROKE}><g transform="translate(24 24) rotate(-12 32 32)">${SYMBOL}</g><g transform="translate(144 144) rotate(-12 32 32)">${SYMBOL}</g></g></svg>`;

export const svgToDataUri = (svg: string): string =>
  'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);

/** SVG -> PNG (data URL). Necessário para jsPDF / pdfmake / pdf-lib. */
export function svgToPngDataUrl(svg: string, width: number, height: number, scale = 3): Promise<string> {
  return new Promise((resolve, reject) => {
    const img = new Image();
    img.onload = () => {
      const canvas = document.createElement('canvas');
      canvas.width = width * scale;
      canvas.height = height * scale;
      const ctx = canvas.getContext('2d');
      if (!ctx) return reject(new Error('Canvas indisponível'));
      ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
      resolve(canvas.toDataURL('image/png'));
    };
    img.onerror = () => reject(new Error('Falha ao rasterizar o SVG'));
    img.src = svgToDataUri(svg);
  });
}
