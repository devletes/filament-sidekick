<?php

namespace Workbench\App\Sidekick\Actions;

use Devletes\Sidekick\Support\SidekickAction;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Workbench\App\Models\LeaveRequest;

class WithdrawLeave extends SidekickAction
{
    public function description(): string
    {
        return 'Withdraw one of the signed-in employee\'s own booked leave requests.';
    }

    public function label(): string
    {
        return 'Preparing the withdrawal';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'leave_request_id' => $schema->integer()->description('Id of the leave request to withdraw.')->required(),
        ];
    }

    public function prepare(array $payload, Authenticatable $user): array
    {
        $leave = LeaveRequest::query()
            ->with('policy')
            ->where('user_id', $user->getAuthIdentifier())
            ->find($payload['leave_request_id'] ?? null);

        if (! $leave) {
            throw new InvalidArgumentException('No leave request of yours matches that id.');
        }

        return [
            'payload' => ['leave_request_id' => $leave->id],
            'summary' => "Withdraw {$leave->days} day of {$leave->policy?->name}",
            'preview' => [
                ['label' => 'Policy', 'value' => (string) $leave->policy?->name],
                ['label' => 'Day', 'value' => $leave->from->format('D, M j')],
                ['label' => 'Balance after', 'value' => ($leave->policy->allowance).' of '.$leave->policy->allowance.' days'],
            ],
        ];
    }

    public function execute(array $payload, Authenticatable $user): string
    {
        LeaveRequest::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->whereKey($payload['leave_request_id'])
            ->delete();

        return 'Withdrawn.';
    }
}
