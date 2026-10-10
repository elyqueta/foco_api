<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\TaskStatus;
use App\Exceptions\DomainRuleException;
use App\Services\TaskStateMachine;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TaskStateMachineTest extends TestCase
{
    #[Test]
    public function every_allowed_transition_passes_and_every_other_fails(): void
    {
        $machine = new TaskStateMachine;

        $allowed = [
            'todo' => ['in_progress', 'postponed', 'done'],
            'in_progress' => ['todo', 'postponed', 'done'],
            'postponed' => ['done'],
            'done' => ['todo'],
            'expired' => ['done', 'todo'],
        ];

        foreach (TaskStatus::cases() as $from) {
            foreach (TaskStatus::cases() as $to) {
                $shouldAllow = in_array($to->value, $allowed[$from->value], true);

                $this->assertSame(
                    $shouldAllow,
                    $machine->can($from, $to),
                    "can({$from->value}, {$to->value}) deveria ser ".($shouldAllow ? 'true' : 'false').'.',
                );

                if ($shouldAllow) {
                    $machine->assertCan($from, $to); // não lança

                    continue;
                }

                $this->assertInvalidTransition($machine, $from, $to);
            }
        }
    }

    #[Test]
    public function expiring_is_never_reachable_through_the_api(): void
    {
        $machine = new TaskStateMachine;

        // `expired` só é alcançado pelo sistema (ExpireOverdueTasks): pedi-lo
        // manualmente — de qualquer estado — é sempre rejeitado (G-07).
        foreach (TaskStatus::cases() as $from) {
            $this->assertFalse($machine->can($from, TaskStatus::Expired));
        }
    }

    #[Test]
    public function postponed_cannot_go_back_to_in_progress_through_the_api(): void
    {
        $machine = new TaskStateMachine;

        // `postponed → in_progress` só existe pelo timer (Fase 8): a API rejeita.
        $this->assertFalse($machine->can(TaskStatus::Postponed, TaskStatus::InProgress));
    }

    #[Test]
    public function invalid_transition_throws_domain_exception_with_pt_message_and_code(): void
    {
        $machine = new TaskStateMachine;

        $this->assertInvalidTransition($machine, TaskStatus::Done, TaskStatus::InProgress);
        $this->assertInvalidTransition($machine, TaskStatus::Todo, TaskStatus::Expired);
    }

    /**
     * Confirma que `assertCan` lança `DomainRuleException` com o código
     * `INVALID_TRANSITION` e a mensagem PT "Transição de estado inválida: …".
     */
    private function assertInvalidTransition(
        TaskStateMachine $machine,
        TaskStatus $from,
        TaskStatus $to,
    ): void {
        try {
            $machine->assertCan($from, $to);

            $this->fail("Esperava DomainRuleException para {$from->value} → {$to->value}.");
        } catch (\Throwable $e) {
            $this->assertInstanceOf(DomainRuleException::class, $e);
            $this->assertSame('INVALID_TRANSITION', $e->errorCode);
            $this->assertSame(422, $e->status);
            $this->assertSame(
                "Transição de estado inválida: {$from->value} → {$to->value}.",
                $e->getMessage(),
            );
        }
    }
}
