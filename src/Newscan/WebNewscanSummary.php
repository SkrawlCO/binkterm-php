<?php

namespace BinktermPHP\Newscan;

/** Presentation-only projection: never query, serialize traversal IDs, or derive read rules. */
final class WebNewscanSummary
{
    /** @return array{netmail:int,echomail:int,areas:int,bulletins:int,empty:bool,truncated:bool} */
    public static function fromPlan(NewscanPlan $plan): array
    {
        return [
            'netmail' => $plan->netmailCount(),
            'echomail' => $plan->echomailCount(),
            'areas' => $plan->areaCount(),
            'bulletins' => $plan->bulletinUnread,
            'empty' => $plan->isEmpty(),
            'truncated' => $plan->truncated,
        ];
    }
}
