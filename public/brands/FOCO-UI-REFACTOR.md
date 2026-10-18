# Foco · Revisão de UI/UX e Identidade Visual

> Stack: **Angular + Tailwind + TypeScript** · Referência: dashboard 1 (apenas UI) · Comparado com: dashboard actual do Foco

---

## 1. Resumo

O dashboard actual tem boa estrutura de informação, mas perde para a referência em cinco pontos:

1. **Cor a mais, hierarquia a menos.** Vermelho saturado na sidebar, no card "Modo foco", nos badges e nos anéis de progresso ao mesmo tempo. Tudo grita, nada se destaca.
2. **Botões sem ícones** e sem estados (hover, focus, active).
3. **Texto demasiado pequeno** e de baixo contraste (legendas ~10 px a cinzento claro).
4. **Cards sem ritmo visual**: padding, raio e sombra inconsistentes; muito espaço vazio no "Modo foco" e em "Urgentes".
5. **Dados de teste a vazar para a UI** ("Alguma tarefa…", itens duplicados em "Próximos passos").

---

## 2. Diagnóstico

| # | Problema | Onde | Correcção | Prioridade |
|---|----------|------|-----------|-----------|
| 1 | Botões sem ícone | "Exportar", "Nova tarefa", toggle Claro/Escuro, "Começar", "Adicionar tarefa" | `<foco-icon>` (download, plus, sun/moon, play) | Alta |
| 2 | Vermelho usado para tudo (tema, urgente, categoria, prioridade) | Sidebar, badges, anéis, ícones dos stats | Cor do tema só em acções e destaque; semântica própria para estados (âmbar, rosa, verde) | Alta |
| 3 | Os 3 primeiros stats têm o mesmo vermelho | Cards Hoje / Pendentes / Urgentes | Um tom semântico por card | Alta |
| 4 | Badges inconsistentes: prioridade suave, categoria sólida e saturada | Lista "Tarefas de hoje" | Prioridade = pílula suave; categoria = ponto + texto | Alta |
| 5 | Placeholder "Alguma tarefa…" visível | Tarefas, Urgentes, Modo foco, Calendário | Estado vazio com mensagem e acção; nunca texto de teste | Alta |
| 6 | Itens duplicados | "Próximos passos" | Deduplicar por `id` (`trackBy` + `Map`) | Alta |
| 7 | Card "Modo foco" vazio e com texto minúsculo | Linha inferior | Ícone alvo, título grande, tarefa actual, temporizador e botão com ícone | Média |
| 8 | Anéis de progresso a 50 %, 60 %, 50 % com cores diferentes sem critério | "Progresso por categoria" | Mesma cor (tema) + trilho suave; cor só muda por limiar | Média |
| 9 | Micro-texto (10–11 px) e cinzento claro | Legendas, datas, meta das tarefas | Mínimo 12 px; `text-slate-500` sobre branco (contraste ≥ 4.5:1) | Média |
| 10 | Avatar com badge "4" ambíguo | Header | Sino separado com badge de notificações; avatar à parte | Média |
| 11 | Cards com paddings e sombras diferentes | Todos | Um único componente `card`: `rounded-3xl p-6` + sombra suave | Média |
| 12 | Sidebar sem rótulos | Coluna da esquerda | `title` + `aria-label` em cada item; logo no topo | Média |
| 13 | Sem estados de foco/hover | Botões, linhas, ícones | `focus-visible:ring-2 ring-primary/40`, `hover:` consistentes | Média |
| 14 | Ícones sem `aria-label` em botões só-ícone | Sidebar, chevrons | `aria-label` obrigatório | Baixa |

---

## 3. Design tokens

### 3.1 Cor (compatível com troca de tema)

`src/styles.css` (único ficheiro CSS necessário além do Tailwind):

```css
:root {
  --primary: 220 38 38;      /* r g b do tema (o ThemeService altera isto) */
  --primary-dark: 172 30 30;
  --primary-fg: 255 255 255; /* texto sobre a cor do tema */
  --foco-logo: #DC2626;      /* usado pelos .svg */
}
```

`tailwind.config.js` (v3; em v4 usa `@theme` com os mesmos nomes):

```js
theme: {
  extend: {
    colors: {
      primary: {
        DEFAULT: 'rgb(var(--primary) / <alpha-value>)',
        dark: 'rgb(var(--primary-dark) / <alpha-value>)',
        fg: 'rgb(var(--primary-fg) / <alpha-value>)',
      },
    },
    boxShadow: { card: '0 8px 30px rgb(15 23 42 / 0.06)' },
  },
}
```

Com isto, `bg-primary/10`, `text-primary`, `ring-primary/40`, `from-primary to-primary-dark` funcionam em **qualquer** cor escolhida nas configurações.

### 3.2 Cores semânticas (independentes do tema)

| Significado | Fundo suave | Texto |
|-------------|-------------|-------|
| Hoje / acção | `bg-primary/10` | `text-primary` |
| Pendentes | `bg-amber-100` | `text-amber-600` |
| Urgente / crítica | `bg-rose-100` | `text-rose-600` |
| Concluído | `bg-emerald-100` | `text-emerald-600` |

> Se o utilizador escolher vermelho como tema, "Urgente" colide com a cor do tema. Por isso o card de urgentes leva sempre o ícone `alert-triangle` e a etiqueta, nunca só a cor.

### 3.3 Tipografia, espaço e forma

| Token | Valor |
|-------|-------|
| Título de página | `text-3xl font-bold tracking-tight text-balance` |
| Título de card | `text-base font-semibold` |
| Corpo | `text-sm` |
| Meta / legendas | `text-xs text-slate-500` (mínimo 12 px) |
| Números | `tabular-nums` |
| Raio | cards `rounded-3xl`, botões/inputs `rounded-xl`, pílulas `rounded-full` |
| Espaço | grelha `gap-6`, padding de card `p-6` |

---

## 4. Componentes corrigidos

### 4.1 Card base

```html
<section class="rounded-3xl bg-white p-6 shadow-card ring-1 ring-slate-900/5
                dark:bg-slate-900 dark:ring-white/10">
  …
</section>
```

### 4.2 Header: botões com ícones

```html
<div class="flex items-center gap-3">
  <!-- Tema -->
  <div class="inline-flex rounded-xl bg-slate-100 p-1 dark:bg-white/10" role="group" aria-label="Tema">
    <button type="button" (click)="theme.setMode('light')"
      class="inline-flex h-8 items-center gap-1.5 rounded-lg px-3 text-xs font-medium transition"
      [class]="mode==='light' ? 'bg-white text-primary shadow-sm' : 'text-slate-500 hover:text-slate-700'">
      <foco-icon name="sun" [size]="14"></foco-icon> Claro
    </button>
    <button type="button" (click)="theme.setMode('dark')"
      class="inline-flex h-8 items-center gap-1.5 rounded-lg px-3 text-xs font-medium transition"
      [class]="mode==='dark' ? 'bg-white text-primary shadow-sm' : 'text-slate-500 hover:text-slate-700'">
      <foco-icon name="moon" [size]="14"></foco-icon> Escuro
    </button>
  </div>

  <!-- Exportar (secundário) -->
  <button type="button"
    class="inline-flex h-10 items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 text-sm font-medium
           text-slate-700 shadow-sm transition hover:bg-slate-50 active:scale-[.98]
           focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40
           dark:border-white/10 dark:bg-white/5 dark:text-slate-200">
    <foco-icon name="download" [size]="16"></foco-icon> Exportar
  </button>

  <!-- Nova tarefa (primário) -->
  <button type="button"
    class="inline-flex h-10 items-center gap-2 rounded-xl bg-slate-900 px-4 text-sm font-semibold text-white
           shadow-sm transition hover:bg-slate-800 active:scale-[.98]
           focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:ring-offset-2
           dark:bg-white dark:text-slate-900">
    <foco-icon name="plus" [size]="16"></foco-icon> Nova tarefa
  </button>

  <!-- Notificações (separado do avatar) -->
  <button type="button" aria-label="Notificações"
    class="relative grid h-10 w-10 place-items-center rounded-xl text-slate-600 transition hover:bg-slate-100
           focus-visible:ring-2 focus-visible:ring-primary/40">
    <foco-icon name="bell" [size]="20"></foco-icon>
    <span class="absolute right-1.5 top-1.5 grid h-4 min-w-4 place-items-center rounded-full bg-primary px-1
                 text-[10px] font-bold text-primary-fg">4</span>
  </button>
</div>
```

Campo de pesquisa com ícone:

```html
<label class="relative block w-full max-w-sm">
  <foco-icon name="search" [size]="16" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></foco-icon>
  <input type="search" placeholder="Pesquisar tarefa ou projecto"
    class="h-10 w-full rounded-xl border border-slate-200 bg-white pl-9 pr-3 text-sm placeholder:text-slate-400
           focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30" />
</label>
```

### 4.3 Cards de estatística

```html
<article class="rounded-3xl bg-white p-5 shadow-card ring-1 ring-slate-900/5">
  <span class="grid h-10 w-10 place-items-center rounded-xl" [ngClass]="tone.bg + ' ' + tone.text">
    <foco-icon [name]="icon" [size]="20"></foco-icon>
  </span>
  <p class="mt-4 text-3xl font-bold tabular-nums text-slate-900">{{ value }}</p>
  <p class="text-sm font-semibold text-slate-700">{{ label }}</p>
  <p class="text-xs text-slate-500">{{ hint }}</p>
</article>
```

Mapa de tons (ver 3.2): `clock → primary`, `check-circle → amber (pendentes)`, `alert-triangle → rose`, `trending-up → emerald`.

### 4.4 Linha de tarefa e badges

```html
<li class="group flex items-center gap-4 rounded-2xl border border-slate-100 bg-white px-4 py-3 transition
           hover:border-primary/30 hover:shadow-sm">
  <input type="checkbox" class="h-5 w-5 rounded-md border-slate-300 text-primary focus:ring-primary/40" />

  <div class="min-w-0 flex-1">
    <p class="truncate text-sm font-semibold text-slate-900">{{ t.title }}</p>
    <p class="mt-0.5 flex items-center gap-1.5 text-xs text-slate-500">
      <foco-icon name="clock" [size]="12"></foco-icon> {{ t.duration }} min · {{ t.date | date:'dd/MM/yyyy' }}
    </p>
  </div>

  <!-- Prioridade: pílula suave -->
  <span class="rounded-full px-2.5 py-1 text-xs font-medium" [ngClass]="priorityClass[t.priority]">{{ t.priority }}</span>

  <!-- Categoria: ponto + texto (menos peso visual) -->
  <span class="inline-flex items-center gap-1.5 text-xs font-medium text-slate-600">
    <span class="h-2 w-2 rounded-full" [ngClass]="categoryDot[t.category]"></span>{{ t.category }}
  </span>

  <foco-icon name="chevron-right" [size]="16" class="text-slate-300 transition group-hover:text-primary"></foco-icon>
</li>
```

```ts
priorityClass = {
  Baixa:   'bg-slate-100 text-slate-600',
  Média:   'bg-amber-100 text-amber-700',
  Alta:    'bg-orange-100 text-orange-700',
  Crítica: 'bg-rose-100 text-rose-700',
};
```

### 4.5 Cartão "Modo foco"

```html
<section class="relative flex flex-col justify-between overflow-hidden rounded-3xl bg-gradient-to-br
                from-primary to-primary-dark p-6 text-primary-fg shadow-lg shadow-primary/30">
  <foco-logo class="absolute -right-6 -top-6 opacity-10" [size]="160"></foco-logo>

  <header class="relative">
    <span class="inline-grid h-10 w-10 place-items-center rounded-xl bg-white/20"><foco-icon name="target" [size]="20"></foco-icon></span>
    <h3 class="mt-3 text-lg font-bold">Modo foco</h3>
    <p class="text-sm opacity-80">Faz só UMA coisa agora</p>
  </header>

  <div class="relative my-6">
    <p class="text-xs uppercase tracking-wide opacity-70">Tarefa actual</p>
    <p class="mt-1 text-xl font-semibold leading-snug">{{ current?.title ?? 'Escolhe uma tarefa para começar' }}</p>
  </div>

  <button type="button" [disabled]="!current"
    class="relative inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-white font-semibold text-slate-900
           transition hover:bg-white/90 active:scale-[.98] disabled:opacity-60
           focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/70">
    <foco-icon name="play" [size]="16"></foco-icon> Começar
  </button>
</section>
```

### 4.6 Anel de progresso

```html
<svg viewBox="0 0 72 72" class="h-16 w-16 -rotate-90" role="img" [attr.aria-label]="pct + '% concluído'">
  <circle cx="36" cy="36" r="30" fill="none" stroke-width="7" class="stroke-primary/15" />
  <circle cx="36" cy="36" r="30" fill="none" stroke-width="7" stroke-linecap="round" class="stroke-primary"
          [attr.stroke-dasharray]="C" [attr.stroke-dashoffset]="C * (1 - pct / 100)" />
</svg>
```

```ts
readonly C = 2 * Math.PI * 30; // ≈ 188.5
```

### 4.7 Sidebar

```html
<aside class="flex h-full w-[76px] flex-col items-center gap-2 rounded-r-[2rem] bg-primary py-6">
  <foco-logo class="mb-4 text-primary-fg" [size]="34"></foco-logo>

  <a *ngFor="let i of nav" [routerLink]="i.link" routerLinkActive="bg-white text-primary shadow-md"
     [attr.aria-label]="i.label" [title]="i.label"
     class="grid h-11 w-11 place-items-center rounded-2xl text-primary-fg/80 transition hover:bg-white/15 hover:text-primary-fg
            focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/70">
    <foco-icon [name]="i.icon" [size]="20"></foco-icon>
  </a>
</aside>
```

`nav`: `dashboard`, `folder`, `check-square`, `calendar`, `tag`, `upload`, `settings`.

### 4.8 Dados e estados vazios

- Remove "Alguma tarefa…". Quando não há dados, mostra estado vazio:

```html
<div class="grid place-items-center gap-2 rounded-2xl border border-dashed border-primary/30 bg-primary/5 p-6 text-center">
  <foco-icon name="check-circle" [size]="24" class="text-primary"></foco-icon>
  <p class="text-sm font-medium text-slate-700">Nada urgente por agora</p>
  <button type="button" class="text-xs font-semibold text-primary hover:underline">Adicionar tarefa</button>
</div>
```

- "Próximos passos" duplicados:

```ts
steps = [...new Map(rawSteps.map(s => [s.id, s])).values()];
```

---

## 5. Ícones

Componente `foco-icon` (sem dependências, estilo Lucide, `currentColor`).

| Onde | Ícone |
|------|-------|
| Nova tarefa / Adicionar | `plus` |
| Exportar | `download` |
| Importar | `upload` |
| Partilhar | `share` |
| Pesquisa | `search` |
| Tema | `sun`, `moon` |
| Sidebar | `dashboard`, `folder`, `check-square`, `calendar`, `tag`, `upload`, `settings` |
| Stats | `clock`, `check-circle`, `alert-triangle`, `trending-up` |
| Modo foco | `target`, `play` |
| Linhas / navegação | `chevron-right`, `chevron-left`, `pencil`, `trash`, `bell` |

---

## 6. Identidade visual

### 6.1 Conceito

Hexágono (estrutura, plataforma colectiva) com um **F** dentro. O braço superior do F **atravessa a abertura do hexágono**: é o "feixe" de atenção, o foco a sair do sistema. Inspirado no modelo hexagonal que enviaste, mas com traço constante, cantos arredondados e sem gradientes, para funcionar em qualquer cor.

### 6.2 Como se adapta às cores do tema

- Todos os traços usam `currentColor` (componente Angular) ou `var(--foco-logo)` (ficheiros `.svg`).
- O texto "Foco" é desenhado em **traços**, não em fonte: o resultado é igual em qualquer sistema e em PDF.
- O `ThemeService` actualiza `--primary`, `--foco-logo`, o **favicon** e o `theme-color` quando o utilizador muda a cor.

### 6.3 Ficheiros

| Ficheiro | Uso |
|----------|-----|
| `svg/foco-symbol.svg` | Símbolo isolado (header, loading, marca de água grande) |
| `svg/foco-logo-horizontal.svg` | Login, e-mails, cabeçalho de PDF |
| `svg/favicon.svg` · `svg/foco-badge.svg` | Favicon e ícone de app (símbolo branco em quadrado arredondado) |
| `svg/foco-pattern.svg` | Tile 240×240 para marca de água / fundo repetido (opacidade 7 %) |
| `svg/foco-login-art.svg` | Ilustração do ecrã de login (hexágonos concêntricos + símbolo) |
| `angular/foco-logo.component.ts` | `<foco-logo variant="symbol\|badge\|full">` |
| `angular/foco-icon.component.ts` | `<foco-icon name="...">` |
| `angular/theme.service.ts` | Cor do tema, modo escuro, favicon dinâmico |
| `angular/foco-brand.ts` | Strings SVG com cor fixa + `svgToPngDataUrl` (PDF) |

### 6.4 Regras de uso

- **Área de respeito:** metade da altura do símbolo em todos os lados.
- **Tamanho mínimo:** símbolo 16 px; logo horizontal 80 px de largura.
- **Sobre fundo colorido** (sidebar, Modo foco): `text-primary-fg` (símbolo) e texto branco.
- **Não** aplicar gradientes, sombras, nem esticar.
- Em escuro, o texto "Foco" passa a branco (`textClass` já trata disso).

### 6.5 Uso nas páginas

**Login**

```html
<div class="grid min-h-screen lg:grid-cols-2">
  <div class="grid place-items-center p-8">
    <div class="w-full max-w-sm">
      <foco-logo variant="full" class="text-primary" [size]="40"></foco-logo>
      <h1 class="mt-8 text-2xl font-bold">Bem-vindo de volta</h1>
      …
    </div>
  </div>
  <div class="relative hidden overflow-hidden bg-primary/5 lg:block"
       style="background-image:url('/assets/brand/foco-login-art.svg');background-size:cover;background-position:center"></div>
</div>
```

> Ficheiros `.svg` carregados por `<img>`/`background-image` **não** herdam variáveis CSS da página. Para o login seguir o tema, usa o SVG inline (cola o conteúdo num componente) ou gera o URI com `svgToDataUri(...)` a partir de `foco-brand.ts`.

**favicon e index.html**

```html
<link rel="icon" type="image/svg+xml" href="assets/brand/favicon.svg" />
<meta name="theme-color" content="#DC2626" />
```

**App**

```ts
// app.component.ts
constructor(private theme: ThemeService) { this.theme.init(); }
// configurações: this.theme.setColor('#7C3AED');
```

### 6.6 PDFs exportados

Os PDFs não entendem `currentColor` nem variáveis CSS: usa o utilitário com cor fixa e converte para PNG.

```ts
import { focoLogoSvg, focoWatermarkSvg, svgToPngDataUrl } from './foco-brand';

const color = getComputedStyle(document.documentElement).getPropertyValue('--foco-logo').trim() || '#DC2626';

const logo = await svgToPngDataUrl(focoLogoSvg(color), 204, 64);      // cabeçalho
const mark = await svgToPngDataUrl(focoWatermarkSvg(color), 240, 240); // fundo

// jsPDF
const w = doc.internal.pageSize.getWidth(), h = doc.internal.pageSize.getHeight();
for (let y = 0; y < h; y += 60) for (let x = 0; x < w; x += 60) doc.addImage(mark, 'PNG', x, y, 60, 60);
doc.addImage(logo, 'PNG', 14, 10, 40, 12.5); // 204:64 ≈ 3.19:1
```

Se usares **pdfmake**, passa `logo` em `images: { logo }` e usa `{ image: 'logo', width: 120 }`; o fundo vai em `background: () => ({ image: 'mark', width: ... })`.

---

## 7. Passos de integração

1. Copia `svg/` para `src/assets/brand/` e `angular/` para `src/app/shared/brand/`.
2. Adiciona as variáveis ao `styles.css` e as cores ao `tailwind.config.js` (secção 3.1).
3. Chama `ThemeService.init()` no arranque.
4. Substitui os botões e a sidebar pelos snippets da secção 4.
5. Troca os cards pelo componente base (4.1) e uniformiza `gap-6`/`p-6`.
6. Remove placeholders e deduplica "Próximos passos".
7. Corre a checklist abaixo.

## 8. Checklist de QA

- [ ] Todos os botões têm ícone **e** estados hover/focus/active
- [ ] Botões só-ícone têm `aria-label`
- [ ] Nenhum texto abaixo de 12 px; contraste ≥ 4.5:1
- [ ] Muda a cor do tema para vermelho, azul, verde, amarelo, roxo e preto: logo, botões e gradientes legíveis
- [ ] Texto sobre cor do tema usa `text-primary-fg` (amarelo claro fica com texto escuro)
- [ ] Modo escuro: cards, badges e anéis com contraste
- [ ] Favicon muda com o tema
- [ ] PDF exportado mostra logo e marca de água na cor do tema
- [ ] Sem texto de teste nem duplicados
- [ ] Navegação por teclado completa (Tab / Shift+Tab / Enter)

## 9. Notas

- `@Input({ required: true })` exige Angular 16+; em versões anteriores usa `@Input() name!: string`.
- Se o teu Tailwind for v4, os tokens passam para `@theme { --color-primary: rgb(var(--primary)); }` e as classes mantêm-se.
- As cores semânticas (âmbar, rosa, verde) e o `ThemeService` assumem Tailwind com a paleta por defeito.
