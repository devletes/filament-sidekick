<?php

namespace Workbench\Database\Seeders;

use Devletes\Sidekick\Agents\ChatAgent;
use Devletes\Sidekick\Enums\ConfirmationMode;
use Devletes\Sidekick\Models\Conversation;
use Devletes\Sidekick\Models\ConversationMessage;
use Devletes\Sidekick\Models\PendingAction;
use Devletes\Sidekick\Models\Run;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Workbench\App\Models\LeavePolicy;
use Workbench\App\Models\LeaveRequest;
use Workbench\App\Models\User;

/**
 * Seeds one user per panel state so screenshots are deterministic and need no
 * AI provider: log in as the user whose state you want to capture.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $annual = LeavePolicy::query()->create(['name' => 'Annual Leave', 'allowance' => 22]);
        $sick = LeavePolicy::query()->create(['name' => 'Sick Leave', 'allowance' => 10]);
        LeavePolicy::query()->create(['name' => 'Unpaid Leave', 'allowance' => 30, 'paid' => false]);

        $aria = $this->user('Aria Whitfield', 'aria@example.com');
        $this->user('Mateo Rivas', 'mateo@example.com');
        $hana = $this->user('Hana Sato', 'hana@example.com');
        $curt = $this->user('Curt Delaney', 'curt@example.com');
        $noor = $this->user('Noor Haddad', 'noor@example.com');
        $ivan = $this->user('Ivan Petrov', 'ivan@example.com');

        $this->leave($aria, $annual, '-40 days', '-36 days', 5, 'approved');
        $this->leave($aria, $sick, '-14 days', '-14 days', 1, 'approved');
        $this->leave($hana, $annual, '+3 days', '+7 days', 3, 'approved');
        $this->leave($curt, $annual, '+4 days', '+4 days', 1, 'approved');
        $this->leave($noor, $sick, '+2 days', '+3 days', 2, 'approved');
        $this->leave($ivan, $annual, '+30 days', '+34 days', 5, 'pending');

        $this->answeredConversation($aria);
        $this->pendingCardConversation($hana);
        $this->streamingConversation($curt);
        $this->executedActionConversation($noor);
        $this->modalCardConversation($ivan);
    }

    protected function user(string $name, string $email): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
    }

    protected function leave(User $user, LeavePolicy $policy, string $from, string $to, float $days, string $status): void
    {
        LeaveRequest::query()->create([
            'user_id' => $user->id,
            'leave_policy_id' => $policy->id,
            'from' => now()->modify($from)->toDateString(),
            'to' => now()->modify($to)->toDateString(),
            'days' => $days,
            'status' => $status,
        ]);
    }

    protected function conversation(User $user, string $title): Conversation
    {
        $conversation = new Conversation([
            'user_id' => $user->id,
            'channel' => 'web',
            'title' => $title,
        ]);

        $conversation->id = (string) Str::uuid7();
        $conversation->save();

        return $conversation;
    }

    protected function message(Conversation $conversation, User $user, string $role, string $content, array $toolCalls = []): void
    {
        $message = new ConversationMessage([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'agent' => ChatAgent::class,
            'role' => $role,
            'content' => $content,
            'attachments' => [],
            'tool_calls' => $toolCalls,
            'tool_results' => [],
            'usage' => [],
            'meta' => [],
        ]);

        $message->id = (string) Str::uuid7();
        $message->save();
    }

    /** A finished exchange: tool activity, a markdown answer, and shortcut buttons. */
    protected function answeredConversation(User $user): void
    {
        $conversation = $this->conversation($user, 'How much leave do I have left?');

        $this->message($conversation, $user, 'user', 'How much annual leave do I have left this year?');

        $this->message(
            $conversation,
            $user,
            'assistant',
            "You have **17 of 22 days** of annual leave left.\n\n".
            "- **Annual Leave** — 5 taken, 17 remaining\n".
            "- **Sick Leave** — 1 taken, 9 remaining\n".
            "- **Unpaid Leave** — none taken\n\n".
            'Your last time off was five days in July.',
            [
                ['name' => 'LeaveBalances', 'arguments' => []],
                [
                    'name' => 'PresentActions',
                    'arguments' => [
                        'actions' => [
                            ['label' => 'Open time off', 'target' => 'time_off'],
                            ['label' => 'Back to dashboard', 'target' => 'dashboard'],
                        ],
                    ],
                ],
            ],
        );
    }

    /** A short proposal awaiting confirmation — the card replaces the composer. */
    protected function pendingCardConversation(User $user): void
    {
        $conversation = $this->conversation($user, 'Cancel my Friday off');

        $this->message($conversation, $user, 'user', 'Cancel my sick day on Friday, I am back at work.');
        $this->message(
            $conversation,
            $user,
            'assistant',
            'Review the card — Confirm to proceed or Cancel to abort.',
            [['name' => 'ProposeWithdrawLeave', 'arguments' => ['leave_request_id' => 5]]],
        );

        PendingAction::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'type' => 'withdraw_leave',
            'status' => PendingAction::STATUS_PROPOSED,
            'summary' => 'Withdraw 1 day of Sick Leave',
            'payload' => ['leave_request_id' => 3],
            'preview' => [
                ['label' => 'Policy', 'value' => 'Sick Leave'],
                ['label' => 'Day', 'value' => 'Fri, Aug 21'],
                ['label' => 'Balance after', 'value' => '10 of 10 days'],
            ],
            'expires_at' => now()->addDay(),
        ]);
    }

    /** After Confirm: the card is gone and its system-verified outcome is in the timeline. */
    protected function executedActionConversation(User $user): void
    {
        $conversation = $this->conversation($user, 'Book a day off');

        $this->message($conversation, $user, 'user', 'Book me the Monday after next off, annual leave.');
        $this->message(
            $conversation,
            $user,
            'assistant',
            'Review the card — Confirm to proceed or Cancel to abort.',
            [['name' => 'ProposeRequestTimeOff', 'arguments' => ['policy' => 'annual']]],
        );

        PendingAction::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'type' => 'request_time_off',
            'status' => PendingAction::STATUS_EXECUTED,
            'summary' => 'Book 1 day of Annual Leave',
            'result' => 'Submitted for approval.',
            'payload' => ['leave_policy_id' => 1, 'days' => 1],
            'preview' => [
                ['label' => 'Policy', 'value' => 'Annual Leave'],
                ['label' => 'Day', 'value' => 'Mon, Aug 31'],
            ],
            'expires_at' => now()->addDay(),
            'executed_at' => now(),
        ]);

        $this->message($conversation, $user, 'assistant', 'Done — Book 1 day of Annual Leave. Submitted for approval.');
    }

    /** A modal-mode proposal: dates, working days, balance impact and approver. */
    protected function modalCardConversation(User $user): void
    {
        $conversation = $this->conversation($user, 'Two weeks off in September');

        $this->message($conversation, $user, 'user', 'I want the first two weeks of September off for a family trip.');
        $this->message(
            $conversation,
            $user,
            'assistant',
            'Review the card — Confirm to proceed or Cancel to abort.',
            [['name' => 'ProposeRequestTimeOff', 'arguments' => ['policy' => 'annual', 'from' => '2026-09-01']]],
        );

        PendingAction::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'type' => 'request_time_off',
            'status' => PendingAction::STATUS_PROPOSED,
            'confirmation' => ConfirmationMode::Modal->value,
            'summary' => 'Book 10 days of Annual Leave',
            'payload' => ['leave_policy_id' => 1, 'days' => 10],
            'preview' => [
                ['label' => 'Policy', 'value' => 'Annual Leave (paid)'],
                ['label' => 'First day', 'value' => 'Tue, Sep 1'],
                ['label' => 'Last day', 'value' => 'Fri, Sep 11'],
                ['label' => 'Working days', 'value' => '10'],
                ['label' => 'Public holidays skipped', 'value' => 'None in range'],
                ['label' => 'Balance after', 'value' => '7 of 22 days'],
                ['label' => 'Approver', 'value' => 'Hana Sato (People Ops)'],
                ['label' => 'Reason', 'value' => 'Family trip'],
            ],
            'expires_at' => now()->addDay(),
        ]);
    }

    /** Mid-turn: tool activity plus a partially streamed reply. */
    protected function streamingConversation(User $user): void
    {
        $conversation = $this->conversation($user, 'Who is off next week?');

        Run::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'prompt' => 'Who is off next week?',
            'status' => Run::STATUS_RUNNING,
            'partial_content' => 'Two people are away next week — Hana Sato is on annual leave from Monday',
            'activity' => [
                ['type' => 'call', 'name' => 'WhosOut', 'at' => now()->toIso8601String()],
                ['type' => 'result', 'name' => 'WhosOut', 'successful' => true, 'at' => now()->toIso8601String()],
                ['type' => 'call', 'name' => 'LeaveBalances', 'at' => now()->toIso8601String()],
            ],
            'started_at' => now(),
        ]);
    }
}
