<?php

return [

    'header' => [
        'new_conversation' => 'New conversation',
        'close' => 'Close assistant',
        'history' => 'Recent conversations',
    ],

    'history' => [
        'heading' => 'Recent conversations',
        'empty' => 'Nothing here yet — your conversations will show up as you have them.',
        'untitled' => 'Untitled conversation',
        'current' => 'Current',
    ],

    'empty_state' => [
        'greeting' => "I'm :assistant",
        'greeting_named' => "Hi :name — I'm :assistant",
        'resume' => 'Resume last conversation',
        'resuming' => 'Loading conversation…',
    ],

    'composer' => [
        'placeholder' => 'Message :assistant…',
        'waiting' => 'Waiting for :assistant…',
        'attach' => 'Attach files',
        'send' => 'Send',
        'remove' => 'Remove :name',
    ],

    'attachments' => [
        'heading' => 'Attachments',
        'required' => '(required)',
        'browse' => 'Browse to select your file',
        'too_many' => 'You can attach up to :count files.',
        'failed' => 'That upload didn\'t come through — please try again.',
        'missing' => 'Attach the required file before confirming.',
    ],

    'card' => [
        'cancel' => 'Cancel',
        'confirm' => 'Confirm',
        'note' => 'Nothing is submitted until you confirm.',
        'waiting_heading' => 'A confirmation is waiting',
        'review' => 'Review it',
    ],

    'outcome' => [
        'done' => 'done',
        'failed' => 'failed: :reason',
        'cancelled' => 'cancelled — nothing was done.',
        'expired' => 'card expired without a response — nothing was done.',
    ],

    // Written into the conversation timeline, so the user reads these back later.
    'acknowledge' => [
        'done' => 'Done — :summary. :result',
        'failed' => 'That didn\'t go through — :reason',
        'cancelled' => 'Okay, cancelled — :summary. Nothing was submitted.',
    ],

    'errors' => [
        'denied_heading' => 'Not available right now',
        'heading' => 'Something went wrong',
        'description' => 'The assistant couldn\'t finish that reply.',
        'retry' => 'Try again',
        'action_failed' => 'Something went wrong executing this action.',
        'stale_run' => 'The assistant took too long to respond.',
    ],

    // Shown to the person verbatim when a turn is refused, so they say what ran out and when it comes back.
    'limits' => [
        'tenant_requests_day' => 'Your organisation has used its assistant allowance for today. It resets at midnight.',
        'tenant_requests_month' => 'Your organisation has used its assistant allowance for this month.',
        'tenant_tokens_day' => 'Your organisation has used its assistant allowance for today. It resets at midnight.',
        'tenant_tokens_month' => 'Your organisation has used its assistant allowance for this month.',
        'user_requests_day' => 'You have used your assistant allowance for today. It resets at midnight.',
        'user_requests_month' => 'You have used your assistant allowance for this month.',
        'user_tokens_day' => 'You have used your assistant allowance for today. It resets at midnight.',
        'user_tokens_month' => 'You have used your assistant allowance for this month.',
    ],

    'insights' => [
        'title' => 'Assistant insights',
        'turns_today' => 'Turns today',
        'turns_month' => 'Turns this month',
        'people_month' => 'People this month',
        'failure_rate' => 'Failure rate',
        'tokens_n' => ':tokens tokens',
        'denied_n' => ':count refused by limits',
        'failed_n' => ':count failed',
        'chart_heading' => 'Turns and tokens, last 30 days',
        'recent_heading' => 'Recent turns',
        'turns' => 'Turns',
        'tokens' => 'Tokens',
        'when' => 'When',
        'user' => 'User',
        'prompt' => 'Prompt',
        'status' => 'Status',
        'tools' => 'Tools used',
        'denied' => 'refused',
        'by_tenant' => 'Usage by tenant, this month',
        'tenant' => 'Tenant',
        'people' => 'People',
        'failed' => 'Failed',
        'no_tenant' => 'No tenant',
        'no_usage' => 'No assistant usage recorded this month.',
    ],

    'activity' => [
        'thinking' => 'Thinking',
        'using' => 'Using :tool',
        'catalog' => 'Checking what it can do',
        'running' => 'Running a tool',
    ],

];
