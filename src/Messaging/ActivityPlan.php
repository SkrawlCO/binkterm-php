<?php

namespace BinktermPHP\Messaging;

/**
 * The immutable result of {@see ActivityService::plan()} — visit-based
 * "what happened since your last call?" activity, plus a visit-independent
 * unread orientation figure.
 *
 * IDs and counts only — never message bodies, never a subject/sender preview.
 * Resolving an id into display content is a future presentation layer's job,
 * through the normal, already-filtered message read path (this keeps
 * read-state and visibility live at render time instead of caching a stale
 * snapshot of them here).
 *
 * `personal`/`ambient` are `null`, not an empty structure, when the caller has
 * no previous visit (`hasPreviousVisit === false`) — there is no since-visit
 * period to report on at all, which is a different fact from "checked, and
 * nothing happened." `unread` is always populated: it depends only on
 * read-state (`message_read_status`), never on visit state, so it is
 * meaningful even for a caller's first-ever tracked visit.
 */
final class ActivityPlan
{
    /**
     * `replyIds` = direct replies to a message the caller themselves
     * authored (unchanged since Slice 2). `participatedIds` = Messaging
     * Evolution Phase 1's addition: broader activity in a conversation the
     * caller has participated in (authored the root or any reply within
     * it), excluding anything already in `replyIds` — a direct reply is
     * never double-counted as participation. See
     * /root/L33TEST_Messaging_Phase1_Personal_Relevance_Design_2026-09-14.md
     * (Track D/E) for the full design rationale.
     *
     * @param array{netmailIds:int[],netmailTruncated:bool,replyIds:int[],repliesTruncated:bool,participatedIds:int[],participatedTruncated:bool}|null $personal
     * @param array{areas:list<array{echoareaId:int,tag:string,domain:string,isLocal:bool,sinceBoundaryCount:int}>,areasTruncated:bool}|null $ambient
     * @param array{netmailUnread:int,bulletinUnread:int} $unread
     */
    public function __construct(
        public readonly bool $hasPreviousVisit,
        public readonly ?string $boundaryAt,
        public readonly ?array $personal,
        public readonly ?array $ambient,
        public readonly array $unread,
    ) {
    }

    /** First-ever tracked visit: no since-visit period exists yet. */
    public static function firstVisit(array $unread): self
    {
        return new self(false, null, null, null, $unread);
    }
}
