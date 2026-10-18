<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Exports;

use App\Models\User;
use App\Services\UserClock;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Relatório de Produtividade (`GET /api/v1/exports/productivity-report`).
 *
 * Parâmetros:
 * - `period` (obrigatório): today | this_week | last_week | this_month | last_month | custom
 * - `start_date` / `end_date` (YYYY-MM-DD): obrigatórios quando period=custom
 * - `month` (YYYY-MM): opcional, mês específico para o relatório
 */
class ProductivityReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'period' => ['required', 'string', 'in:today,this_week,last_week,this_month,last_month,custom'],
            'start_date' => ['nullable', 'string', $this->plainDate()],
            'end_date' => ['nullable', 'string', $this->plainDate()],
            'month' => ['nullable', 'string', $this->plainMonth()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'period.required' => 'O período é obrigatório.',
            'period.in' => 'O período deve ser: today, this_week, last_week, this_month, last_month ou custom.',
            'start_date.required_when_custom' => 'A data de início é obrigatória quando o período é "custom".',
            'end_date.required_when_custom' => 'A data de fim é obrigatória quando o período é "custom".',
            'end_date.after_or_equal' => 'A data de fim deve ser igual ou posterior à data de início.',
            'month' => 'O mês deve estar no formato AAAA-MM.',
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['period', 'start_date', 'end_date', 'month'] as $key) {
            if ($this->has($key) && is_string($this->input($key))) {
                $this->merge([$key => trim($this->input($key))]);
            }
        }
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $period = $this->input('period');
            $startDate = $this->input('start_date');
            $endDate = $this->input('end_date');

            if ($period === 'custom') {
                if (! is_string($startDate) || $startDate === '') {
                    $validator->errors()->add('start_date', 'A data de início é obrigatória quando o período é "custom".');
                }
                if (! is_string($endDate) || $endDate === '') {
                    $validator->errors()->add('end_date', 'A data de fim é obrigatória quando o período é "custom".');
                }

                if (is_string($startDate) && $startDate !== '' && is_string($endDate) && $endDate !== '') {
                    if ($endDate < $startDate) {
                        $validator->errors()->add(
                            'end_date',
                            'A data de fim deve ser igual ou posterior à data de início.',
                        );
                    }
                }
            }
        });
    }

    /**
     * Data isolada `YYYY-MM-DD` de calendário real.
     */
    protected function plainDate(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || trim($value) === '') {
                return;
            }

            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $m) !== 1
                || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                $fail('A data não é válida (use AAAA-MM-DD).');
            }
        };
    }

    /**
     * Mês isolado `YYYY-MM`.
     */
    protected function plainMonth(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || trim($value) === '') {
                return;
            }

            if (preg_match('/^(\d{4})-(\d{2})$/', trim($value), $m) !== 1
                || ! checkdate((int) $m[2], 1, (int) $m[1])) {
                $fail('O mês não é válido (use AAAA-MM).');
            }
        };
    }

    /**
     * Devolve o período efetivamente calculado, com base nos parâmetros validados
     * e no fuso horário do utilizador.
     *
     * @return array{type: string, label: string, start_date: string, end_date: string}
     */
    public function computePeriod(User $user): array
    {
        $period = $this->input('period');
        $month = $this->input('month');
        $startDate = $this->input('start_date');
        $endDate = $this->input('end_date');

        $clock = app(UserClock::class);
        $today = $clock->today($user);
        $tz = $clock->timezone($user);

        // Se mês for especificado, calcular do mês.
        if ($month !== null && $month !== '') {
            $start = CarbonImmutable::parse($month.'-01', $tz)->startOfDay();
            $end = (clone $start)->endOfMonth();

            return [
                'type' => 'this_month',
                'label' => $start->locale('pt')->translatedFormat('F de Y'),
                'start_date' => $start->format('Y-m-d'),
                'end_date' => $end->format('Y-m-d'),
            ];
        }

        switch ($period) {
            case 'today':
                return [
                    'type' => 'today',
                    'label' => 'Hoje',
                    'start_date' => $today->format('Y-m-d'),
                    'end_date' => $today->format('Y-m-d'),
                ];

            case 'this_week':
                $start = (clone $today)->startOfWeek(CarbonImmutable::MONDAY);
                $end = (clone $start)->addDays(6);

                return [
                    'type' => 'this_week',
                    'label' => 'Esta semana',
                    'start_date' => $start->format('Y-m-d'),
                    'end_date' => $end->format('Y-m-d'),
                ];

            case 'last_week':
                $start = (clone $today)->startOfWeek(CarbonImmutable::MONDAY)->subWeek();
                $end = (clone $start)->addDays(6);

                return [
                    'type' => 'last_week',
                    'label' => 'Semana passada',
                    'start_date' => $start->format('Y-m-d'),
                    'end_date' => $end->format('Y-m-d'),
                ];

            case 'this_month':
                $start = (clone $today)->startOfMonth();
                $end = (clone $start)->endOfMonth();

                return [
                    'type' => 'this_month',
                    'label' => self::monthLabel($start),
                    'start_date' => $start->format('Y-m-d'),
                    'end_date' => $end->format('Y-m-d'),
                ];

            case 'last_month':
                $start = (clone $today)->startOfMonth()->subMonth();
                $end = (clone $start)->endOfMonth();

                return [
                    'type' => 'last_month',
                    'label' => self::monthLabel($start),
                    'start_date' => $start->format('Y-m-d'),
                    'end_date' => $end->format('Y-m-d'),
                ];

            case 'custom':
                return [
                    'type' => 'custom',
                    'label' => 'Período personalizado',
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ];

            default:
                return [
                    'type' => 'today',
                    'label' => 'Hoje',
                    'start_date' => $today->format('Y-m-d'),
                    'end_date' => $today->format('Y-m-d'),
                ];
        }
    }

    /**
     * Devolve o rótulo do mês em pt-PT (ex.: "Outubro de 2026").
     */
    private static function monthLabel(CarbonImmutable $date): string
    {
        $months = [
            1 => 'Janeiro',
            2 => 'Fevereiro',
            3 => 'Março',
            4 => 'Abril',
            5 => 'Maio',
            6 => 'Junho',
            7 => 'Julho',
            8 => 'Agosto',
            9 => 'Setembro',
            10 => 'Outubro',
            11 => 'Novembro',
            12 => 'Dezembro',
        ];

        return $months[(int) $date->month].' de '.$date->format('Y');
    }
}
