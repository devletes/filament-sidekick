<?php

namespace Workbench\App\Sidekick\Tools;

use Devletes\Sidekick\Support\ChatToolBase;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Workbench\App\Models\LeavePolicy;
use Workbench\App\Models\LeaveRequest;

class LeaveBalances extends ChatToolBase
{
    public function description(): string
    {
        return "The signed-in employee's leave balance for every policy: entitled, taken, and remaining days.";
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function label(): string
    {
        return 'Checking your leave balance';
    }

    public function instructions(): ?string
    {
        return 'Always quote leave numbers from LeaveBalances rather than adding up requests yourself.';
    }

    public function handle(Request $request): string
    {
        $balances = LeavePolicy::query()->get()->map(function (LeavePolicy $policy): array {
            $taken = (float) LeaveRequest::query()
                ->where('user_id', $this->user->getAuthIdentifier())
                ->where('leave_policy_id', $policy->id)
                ->where('status', 'approved')
                ->sum('days');

            return [
                'policy' => $policy->name,
                'entitled' => $policy->allowance,
                'taken' => $taken,
                'remaining' => $policy->allowance - $taken,
            ];
        });

        return $this->respond(['balances' => $balances]);
    }
}
