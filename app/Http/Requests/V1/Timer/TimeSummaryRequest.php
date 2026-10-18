<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Timer;

use App\Actions\Timer\TimeSummary;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Resumo de tempo (`GET /time/summary`, doc 08): `from`/`to` em
 * `YYYY-MM-DD` (datas de calendário reais, fuso do utilizador, inclusive) e
 * `groupBy` em `day|task|category`. Sem datas, os últimos 7 dias; sem
 * `groupBy`, por dia. A janela máxima é de um ano.
 */
class TimeSummaryRequest extends FormRequest
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
            'from' => ['nullable', 'string', $this->plainDate(), 'before_or_equal:to'],
            'to' => ['nullable', 'string', $this->plainDate()],
            'groupBy' => ['nullable', 'string', 'in:day,task,category'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'groupBy.in' => 'O agrupamento só pode ser day, task ou category.',
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['from', 'to', 'groupBy'] as $key) {
            if ($this->has($key) && is_string($this->input($key))) {
                $this->merge([$key => trim($this->input($key))]);
            }
        }

        if (! is_string($this->input('groupBy')) || $this->input('groupBy') === '') {
            $this->merge(['groupBy' => 'day']);
        }
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $from = $this->input('from');
            $to = $this->input('to');

            if (! is_string($from) || ! is_string($to) || $from === '' || $to === '') {
                return;
            }

            $days = (strtotime($to) - strtotime($from)) / 86400;

            if ($days > TimeSummary::MAX_DAYS) {
                $validator->errors()->add(
                    'to',
                    'O período pedido não pode ser superior a '.TimeSummary::MAX_DAYS.' dias.',
                );
            }
        });
    }

    /**
     * Data isolada `YYYY-MM-DD` de calendário real (o PostgreSQL rejeitaria
     * "2026-02-30" na coluna `date`).
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
}
