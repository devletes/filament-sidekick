<?php

namespace Workbench\App\Sidekick\Actions;

use Devletes\Sidekick\Enums\ConfirmationMode;
use Devletes\Sidekick\Support\SidekickAction;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Workbench\App\Models\LeavePolicy;
use Workbench\App\Models\LeaveRequest;

class RequestTimeOff extends SidekickAction
{
    public function description(): string
    {
        return 'Book time off for the signed-in employee. The card shows the working days and balance impact before anything is submitted.';
    }

    public function label(): string
    {
        return 'Preparing your time-off request';
    }

    /** The preview carries dates, working days, balance impact and the approver — more than the panel dock fits. */
    public function confirmation(): ConfirmationMode
    {
        return ConfirmationMode::Modal;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'policy' => $schema->string()->description('Leave policy name, or part of it, e.g. "annual".')->required(),
            'from' => $schema->string()->description('First day off, YYYY-MM-DD.')->required(),
            'to' => $schema->string()->description('Last day off, YYYY-MM-DD. Omit for a single day.'),
            'reason' => $schema->string()->description('Optional note for the approver.'),
        ];
    }

    public function prepare(array $payload, Authenticatable $user): array
    {
        $policy = LeavePolicy::query()->where('name', 'like', '%'.($payload['policy'] ?? '').'%')->first();

        if (! $policy) {
            throw new InvalidArgumentException('No leave policy matches that name.');
        }

        $from = Carbon::parse($payload['from']);
        $to = filled($payload['to'] ?? null) ? Carbon::parse($payload['to']) : $from->copy();

        if ($to->lt($from)) {
            throw new InvalidArgumentException('The last day is before the first day.');
        }

        $days = $this->workingDays($from, $to);

        if ($days <= 0) {
            throw new InvalidArgumentException('That range contains no working days.');
        }

        $taken = (float) LeaveRequest::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('leave_policy_id', $policy->id)
            ->where('status', 'approved')
            ->sum('days');

        $remaining = $policy->allowance - $taken;

        if ($days > $remaining) {
            throw new InvalidArgumentException("That is {$days} days but only {$remaining} remain on {$policy->name}.");
        }

        return [
            'payload' => [
                'leave_policy_id' => $policy->id,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'days' => $days,
                'reason' => $payload['reason'] ?? null,
            ],
            'summary' => "Book {$days} days of {$policy->name}",
            'preview' => array_values(array_filter([
                ['label' => 'Policy', 'value' => $policy->name],
                ['label' => 'First day', 'value' => $from->format('D, M j')],
                ['label' => 'Last day', 'value' => $to->format('D, M j')],
                ['label' => 'Working days', 'value' => (string) $days],
                ['label' => 'Balance after', 'value' => ($remaining - $days).' of '.$policy->allowance.' days'],
                ['label' => 'Approver', 'value' => 'Hana Sato (People Ops)'],
                filled($payload['reason'] ?? null) ? ['label' => 'Reason', 'value' => $payload['reason']] : null,
            ])),
        ];
    }

    public function execute(array $payload, Authenticatable $user): string
    {
        LeaveRequest::query()->create([
            ...$payload,
            'user_id' => $user->getAuthIdentifier(),
            'status' => 'pending',
        ]);

        return 'Submitted for approval.';
    }

    protected function workingDays(Carbon $from, Carbon $to): float
    {
        $days = 0;

        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            if ($day->isWeekday()) {
                $days++;
            }
        }

        return $days;
    }
}
