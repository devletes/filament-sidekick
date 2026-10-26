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
use Workbench\App\Models\Order;
use Workbench\App\Models\Product;
use Workbench\App\Models\User;

/** One account per panel state, so screenshots are deterministic and need no AI provider. */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $salman = $this->user('salman@example.com');
        $this->user('salman.new@example.com');
        $card = $this->user('salman.card@example.com');
        $streaming = $this->user('salman.streaming@example.com');
        $done = $this->user('salman.done@example.com');
        $modal = $this->user('salman.modal@example.com');

        foreach ([
            ['Linen tote bag', 'TOTE-01', 4, 10],
            ['Ceramic mug', 'MUG-02', 7, 12],
            ['Notebook, dotted', 'NOTE-03', 38, 15],
        ] as [$name, $sku, $stock, $reorderAt]) {
            Product::query()->create(['name' => $name, 'sku' => $sku, 'stock' => $stock, 'reorder_at' => $reorderAt]);
        }

        foreach ([
            [1042, 'Hana Sato', 2, 86.00, 'paid', 0],
            [1041, 'Curt Delaney', 1, 24.50, 'paid', 1],
            [1040, 'Noor Haddad', 3, 142.00, 'paid', 1],
            [1039, 'Ivan Petrov', 1, 59.00, 'shipped', 2],
            [1038, 'Aria Bennett', 4, 218.75, 'shipped', 3],
            [1037, 'Mateo Rivas', 2, 64.00, 'shipped', 4],
            [1036, 'Lena Fischer', 2, 120.00, 'paid', 5],
            [1035, 'Priya Nair', 1, 39.99, 'refunded', 6],
        ] as [$number, $customer, $items, $total, $status, $daysAgo]) {
            Order::query()->create([
                'number' => $number,
                'customer' => $customer,
                'items' => $items,
                'total' => $total,
                'refunded' => $status === 'refunded' ? $total : 0,
                'status' => $status,
                'placed_at' => now()->subDays($daysAgo)->setTime(9 + $number % 10, 30),
                'shipped_at' => $status === 'shipped' ? now()->subDays(max(0, $daysAgo - 1)) : null,
            ]);
        }

        // Seeded before the panel states so theirs stay the newest conversations each account resumes.
        $this->insightsHistory();

        $this->answeredConversation($salman);
        $this->pendingCardConversation($card);
        $this->streamingConversation($streaming);
        $this->executedActionConversation($done);
        $this->modalCardConversation($modal);
    }

    protected function user(string $email, string $name = 'Salman Hijazi'): User
    {
        // updateOrCreate: testbench's workbench.user may already have created the main account.
        return User::query()->updateOrCreate(['email' => $email], [
            'name' => $name,
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
    }

    protected function conversation(User $user, string $title): Conversation
    {
        $conversation = new Conversation([
            'user_id' => $user->id,
            'participant_type' => Conversation::participantType($user),
            'participant_id' => Conversation::participantKey($user),
            'channel' => 'web',
            'title' => $title,
        ]);

        $conversation->id = (string) Str::uuid7();
        $conversation->save();

        return $conversation;
    }

    /** Stored as laravel/ai stores a turn: tool calls in one step, the reply in the next. */
    protected function message(Conversation $conversation, User $user, string $role, string $content, array $toolCalls = []): void
    {
        $calls = collect($toolCalls)->values()->map(fn (array $call, int $i): array => [
            'id' => 'call_'.($i + 1),
            ...$call,
            'result' => '',
        ])->all();

        $steps = match (true) {
            $role === 'user' => [],
            $calls === [] => [$this->step($content)],
            default => [$this->step('', $calls), $this->step($content)],
        };

        $message = new ConversationMessage([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'participant_type' => Conversation::participantType($user),
            'participant_id' => Conversation::participantKey($user),
            'agent' => ChatAgent::class,
            'role' => $role,
            'content' => $content,
            'attachments' => [],
            'steps' => $steps,
            'usage' => [],
            'meta' => [],
            'status' => 'completed',
        ]);

        $message->id = (string) Str::uuid7();
        $message->save();
    }

    protected function step(string $content, array $calls = []): array
    {
        return ['content' => $content, 'tool_calls' => $calls, 'reasoning' => '', 'replay_blocks' => [], 'provider_tool_calls' => []];
    }

    protected function order(int $number): Order
    {
        return Order::query()->where('number', $number)->firstOrFail();
    }

    protected function answeredConversation(User $user): void
    {
        $week = Order::query()->where('placed_at', '>=', now()->subDays(6)->startOfDay())->get();
        $biggest = $week->sortByDesc('total')->first();
        $statuses = $week->countBy('status');

        $conversation = $this->conversation($user, 'How did sales do this week?');

        $this->message($conversation, $user, 'user', 'How did sales do this week?');

        $this->message(
            $conversation,
            $user,
            'assistant',
            'This week brought in **'.Order::money($week->sum('total') - $week->sum('refunded')).'** across **'.$week->count()." orders**.\n\n".
            '- **Paid** — '.($statuses['paid'] ?? 0)." waiting to ship\n".
            '- **Shipped** — '.($statuses['shipped'] ?? 0)."\n".
            '- **Refunded** — '.($statuses['refunded'] ?? 0)."\n\n".
            "The biggest was #{$biggest->number} from {$biggest->customer}, at ".Order::money($biggest->total).'.',
            [
                ['name' => 'SalesSummary', 'arguments' => ['from' => now()->subDays(6)->toDateString(), 'to' => now()->toDateString()]],
                [
                    'name' => 'PresentActions',
                    'arguments' => [
                        'actions' => [
                            ['label' => 'Open orders', 'target' => 'orders'],
                            ['label' => 'Back to dashboard', 'target' => 'dashboard'],
                        ],
                    ],
                ],
            ],
        );
    }

    protected function pendingCardConversation(User $user): void
    {
        $order = $this->order(1042);

        $conversation = $this->conversation($user, "Cancel order #{$order->number}");

        $this->message($conversation, $user, 'user', "Cancel order #{$order->number}, the customer changed their mind.");
        $this->message(
            $conversation,
            $user,
            'assistant',
            'Review the card — Confirm to proceed or Cancel to abort.',
            [['name' => 'ProposeCancelOrder', 'arguments' => ['number' => $order->number]]],
        );

        PendingAction::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'type' => 'cancel_order',
            'status' => PendingAction::STATUS_PROPOSED,
            'summary' => "Cancel order #{$order->number}",
            'payload' => ['number' => $order->number],
            'preview' => [
                ['label' => 'Customer', 'value' => $order->customer],
                ['label' => 'Items', 'value' => (string) $order->items],
                ['label' => 'Refund', 'value' => Order::money($order->total).' in full'],
            ],
            'expires_at' => now()->addDay(),
        ]);
    }

    protected function executedActionConversation(User $user): void
    {
        $order = $this->order(1039);

        $conversation = $this->conversation($user, "Ship order #{$order->number}");

        $this->message($conversation, $user, 'user', "Mark order #{$order->number} as shipped.");
        $this->message(
            $conversation,
            $user,
            'assistant',
            'Review the card — Confirm to proceed or Cancel to abort.',
            [['name' => 'ProposeShipOrder', 'arguments' => ['number' => $order->number]]],
        );

        PendingAction::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'type' => 'ship_order',
            'status' => PendingAction::STATUS_EXECUTED,
            'summary' => "Mark order #{$order->number} as shipped",
            'result' => 'Shipped, and the customer has been emailed.',
            'payload' => ['number' => $order->number],
            'preview' => [
                ['label' => 'Customer', 'value' => $order->customer],
                ['label' => 'Items', 'value' => (string) $order->items],
            ],
            'expires_at' => now()->addDay(),
            'executed_at' => now(),
        ]);

        $this->message($conversation, $user, 'assistant', "Done — order #{$order->number} is marked as shipped and {$order->customer} has been emailed.");
    }

    protected function modalCardConversation(User $user): void
    {
        $order = $this->order(1036);
        $amount = $order->total / 2;

        $conversation = $this->conversation($user, "Partial refund on #{$order->number}");

        $this->message($conversation, $user, 'user', "Refund half of order #{$order->number}, one of the two items arrived damaged.");
        $this->message(
            $conversation,
            $user,
            'assistant',
            'Review the card — Confirm to proceed or Cancel to abort.',
            [['name' => 'ProposeRefundOrder', 'arguments' => ['number' => $order->number, 'amount' => $amount]]],
        );

        PendingAction::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'type' => 'refund_order',
            'status' => PendingAction::STATUS_PROPOSED,
            'confirmation' => ConfirmationMode::Modal->value,
            'summary' => 'Refund '.Order::money($amount)." on order #{$order->number}",
            'payload' => ['number' => $order->number, 'amount' => $amount, 'restock' => false, 'reason' => 'Damaged in transit'],
            'preview' => [
                ['label' => 'Customer', 'value' => $order->customer],
                ['label' => 'Placed', 'value' => $order->placed_at->format('D, M j')],
                ['label' => 'Order total', 'value' => Order::money($order->total)],
                ['label' => 'Refund', 'value' => Order::money($amount)],
                ['label' => 'Refund to', 'value' => 'Original payment method'],
                ['label' => 'Restock items', 'value' => 'No'],
                ['label' => 'Reason', 'value' => 'Damaged in transit'],
            ],
            'expires_at' => now()->addDay(),
        ]);
    }

    protected function insightsHistory(): void
    {
        mt_srand(42);

        $prompts = [
            ['How did sales do yesterday?', 'SalesSummary'],
            ['Which orders are still waiting to ship?', 'FindOrders'],
            ['Refund order #1031, it never arrived.', 'ProposeRefundOrder'],
            ['What is running low in stock?', 'StockLevels'],
            ['Show me every order from Hana Sato.', 'FindOrders'],
            ['Cancel order #1028, the customer changed their mind.', 'ProposeCancelOrder'],
            ['How many orders came in this morning?', 'SalesSummary'],
            ['Mark order #1030 as shipped.', 'ProposeShipOrder'],
            ['Compare this week with last week.', 'SalesSummary'],
            ['Which customers ordered more than once this month?', 'FindOrders'],
        ];

        $team = collect(['Salman Hijazi', 'Julia Romero', 'Ken Watanabe', 'Amara Okafor'])
            ->map(fn (string $name): User => $this->user(Str::slug($name, '.').'@team.example.com', $name))
            ->map(fn (User $user): array => [$user, $this->conversation($user, 'Order questions')]);

        for ($daysAgo = 29; $daysAgo >= 0; $daysAgo--) {
            $turns = ($daysAgo % 7 >= 5 ? 2 : 6) + mt_rand(0, 6);

            for ($i = 0; $i < $turns; $i++) {
                [$user, $conversation] = $team[mt_rand(0, $team->count() - 1)];
                $at = now()->subDays($daysAgo)->setTime(mt_rand(8, 18), mt_rand(0, 59));
                $failed = mt_rand(1, 40) === 1;
                $input = mt_rand(1800, 6400);
                $output = $failed ? 0 : mt_rand(120, 900);
                [$prompt, $tool] = $prompts[mt_rand(0, count($prompts) - 1)];

                Run::query()->create([
                    'conversation_id' => $conversation->id,
                    'user_id' => $user->id,
                    'prompt' => $prompt,
                    'activity' => [
                        ['type' => 'call', 'name' => $tool, 'at' => $at->toIso8601String()],
                        ['type' => 'result', 'name' => $tool, 'successful' => ! $failed, 'at' => $at->toIso8601String()],
                    ],
                    'status' => $failed ? Run::STATUS_FAILED : Run::STATUS_COMPLETED,
                    'error' => $failed ? 'The provider timed out.' : null,
                    'usage' => ['prompt_tokens' => $input, 'completion_tokens' => $output, 'cache_write_tokens' => 0, 'cache_read_tokens' => mt_rand(0, 4000)],
                    'tokens' => $input + $output,
                    'metered' => true,
                    'started_at' => $at,
                    'finished_at' => $at->copy()->addSeconds(mt_rand(3, 14)),
                    'created_at' => $at,
                    'updated_at' => $at,
                ]);
            }
        }

        // Older than every panel state's conversation, so no account resumes these.
        Conversation::query()->whereIn('id', $team->map(fn (array $pair): string => $pair[1]->id))->update(['updated_at' => now()->subDays(30)]);
    }

    protected function streamingConversation(User $user): void
    {
        $waiting = Order::query()->where('status', 'paid')->oldest('placed_at')->get();
        $oldest = $waiting->first();

        $conversation = $this->conversation($user, 'Which orders still need to ship?');

        Run::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'prompt' => 'Which orders still need to ship?',
            'status' => Run::STATUS_RUNNING,
            'partial_content' => (['One', 'Two', 'Three', 'Four', 'Five'][$waiting->count() - 1] ?? $waiting->count())
                ." orders are waiting to ship — the oldest is #{$oldest->number} for {$oldest->customer}, placed on "
                .$oldest->placed_at->format('l'),
            'activity' => [
                ['type' => 'call', 'name' => 'FindOrders', 'at' => now()->toIso8601String()],
                ['type' => 'result', 'name' => 'FindOrders', 'successful' => true, 'at' => now()->toIso8601String()],
                ['type' => 'call', 'name' => 'StockLevels', 'at' => now()->toIso8601String()],
            ],
            'started_at' => now(),
        ]);
    }
}
