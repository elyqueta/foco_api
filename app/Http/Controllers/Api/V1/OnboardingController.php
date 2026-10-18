<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Onboarding\CompleteOnboardingRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Estado de onboarding do utilizador (persistido na API).
 *
 * O front-end armazena o estado no localStorage e sincroniza com a API no
 * login. Estes endpoints permitem ler e marcar o onboarding como concluído.
 */
class OnboardingController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'completed' => (bool) $user->onboarding_completed,
            'currentStep' => (int) $user->onboarding_current_step,
        ]);
    }

    public function complete(CompleteOnboardingRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->update([
            'onboarding_completed' => true,
            'onboarding_current_step' => $request->input('currentStep', $user->onboarding_current_step + 1),
        ]);

        return response()->json([
            'message' => 'Onboarding concluído.',
        ]);
    }
}
