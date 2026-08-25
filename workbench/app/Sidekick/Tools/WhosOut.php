<?php

namespace Workbench\App\Sidekick\Tools;

use Devletes\Sidekick\Support\ChatToolBase;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Workbench\App\Models\LeaveRequest;

class WhosOut extends ChatToolBase
{
    public function description(): string
    {
        return 'Who is on approved leave over a date range, with the dates they are away.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->description('Start of the range, YYYY-MM-DD.')->required(),
            'to' => $schema->string()->description('End of the range, YYYY-MM-DD.')->required(),
        ];
    }

    public function label(): string
    {
        return 'Checking who is out';
    }

    public function handle(Request $request): string
    {
        $away = LeaveRequest::query()
            ->with(['user', 'policy'])
            ->where('status', 'approved')
            ->whereDate('to', '>=', $request['from'])
            ->whereDate('from', '<=', $request['to'])
            ->get()
            ->map(fn (LeaveRequest $leave): array => [
                'employee' => $leave->user?->name,
                'policy' => $leave->policy?->name,
                'from' => $leave->from->toDateString(),
                'to' => $leave->to->toDateString(),
                'days' => $leave->days,
            ]);

        return $this->respond(['away' => $away]);
    }
}
