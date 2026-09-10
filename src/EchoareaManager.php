<?php

namespace BinktermPHP;

use PDO;

class EchoareaManager
{
    /**
     * FTN area tags are case-insensitive opaque tokens. We accept the common
     * punctuation seen in echolist/areafix usage and keep spaces out.
     */
    public const TAG_PATTERN = "/^[A-Z0-9._'!%&-]+$/";
    public const ROUTE_ECHOAREA_PATTERN = "[-A-Za-z0-9@._'!%&]+";

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getPdo();
    }

    public static function normalizeTag(string $tag): string
    {
        return strtoupper(trim($tag));
    }

    public static function isValidTag(string $tag): bool
    {
        $normalizedTag = self::normalizeTag($tag);

        return $normalizedTag !== '' && preg_match(self::TAG_PATTERN, $normalizedTag) === 1;
    }

    public function findByTagAndDomains(string $tag, array $domains = []): ?array
    {
        $normalizedTag = self::normalizeTag($tag);
        if ($normalizedTag === '') {
            return null;
        }

        [$domainClause, $params] = $this->buildDomainWhereClause($domains);
        $sql = "
            SELECT id, tag, description, domain, uplink_address, is_active, is_local, missing_chrs_charset
            FROM echoareas
            WHERE UPPER(tag) = UPPER(?)
              AND {$domainClause}
            LIMIT 1
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_merge([$normalizedTag], $params));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function getById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT id, tag, description, domain, uplink_address, is_active, is_local, missing_chrs_charset
            FROM echoareas
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * User-scoped echoarea listing — the shared behavior behind
     * `GET /api/echoareas`. The HTTP route is a thin adapter over this, and the
     * telnet/SSH daemons call it directly so the terminal no longer round-trips
     * its own API (out to the public site URL / Cloudflare and back, with a
     * fresh TLS handshake) just to render the Echomail area picker.
     *
     * @param array $user Authenticated user row (accepts `user_id` or `id`; reads `is_admin`)
     * @param array{filter?: string, subscribed_only?: bool} $options
     *        `filter` is `active` (default), `inactive` or `all`; `subscribed_only`
     *        restricts to the user's active subscriptions.
     * @return array<int, array<string, mixed>> Echoarea rows with visible
     *         message/unread/subscriber counts, subscription status, last-post
     *         columns, `effective_posting_name_policy`, LovlyNet metadata and
     *         `interest_ids`.
     */
    public function listForUser(array $user, array $options = []): array
    {
        $filter = (string)($options['filter'] ?? 'active');
        $subscribedOnly = !empty($options['subscribed_only']) ? 'true' : 'false';
        $isAdmin = !empty($user['is_admin']);
        // Handle both 'user_id' and 'id' field names for compatibility
        $userId = $user['user_id'] ?? $user['id'] ?? null;

        $db = $this->db;
        $messageHandler = new MessageHandler();
        $ignoreFilter = $messageHandler->buildEchomailIgnoreFilter($userId, 'em');
        $moderationFilter = $messageHandler->buildModerationVisibilityFilter($userId, 'em');

        // Query with cached last-post columns and one live subquery for user-visible message counts.
        // e.message_count is the raw area total; sidebar counts must match the message list filters.
        // last_posts (was: DISTINCT ON full scan + external sort) is replaced by e.last_post_* columns.
        $sql = "SELECT
                    e.id,
                    e.tag,
                    e.description,
                    e.moderator,
                    e.uplink_address,
                    e.posting_name_policy,
                    e.missing_chrs_charset,
                    e.color,
                    e.is_active,
                    e.created_at,
                    e.domain,
                    e.is_local,
                    e.is_sysop_only,
                    COALESCE(visible_counts.message_count, 0) as message_count,
                    COALESCE(visible_counts.unread_count, 0) as unread_count,
                    COALESCE(sub_counts.subscriber_count, 0) as subscriber_count,
                    e.last_post_subject as last_subject,
                    e.last_post_author  as last_author,
                    e.last_post_date    as last_date,
                    e.allow_media,
                    ues_my.is_active as subscribed
                FROM echoareas e";

        // Add subscription filtering if requested
        if ($subscribedOnly === 'true') {
            $sql .= " INNER JOIN user_echoarea_subscriptions ues ON e.id = ues.echoarea_id AND ues.user_id = ? AND ues.is_active = TRUE";
            $params = [$userId];
        } else {
            $params = [];
        }

        $sql .= " LEFT JOIN (
                    SELECT
                        em.echoarea_id,
                        COUNT(*) as message_count,
                        COUNT(*) FILTER (WHERE mrs.read_at IS NULL) as unread_count
                    FROM echomail em
                    LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                    WHERE (em.date_written IS NULL OR em.date_written <= (NOW() AT TIME ZONE 'UTC')){$ignoreFilter['sql']}{$moderationFilter['sql']}
                    GROUP BY em.echoarea_id
                ) visible_counts ON e.id = visible_counts.echoarea_id
                LEFT JOIN (
                    SELECT echoarea_id, COUNT(*) as subscriber_count
                    FROM user_echoarea_subscriptions
                    WHERE is_active = TRUE
                    GROUP BY echoarea_id
                ) sub_counts ON e.id = sub_counts.echoarea_id
                LEFT JOIN user_echoarea_subscriptions ues_my ON e.id = ues_my.echoarea_id AND ues_my.user_id = ? AND ues_my.is_active = TRUE";

        $params[] = $userId;
        foreach ($ignoreFilter['params'] as $param) {
            $params[] = $param;
        }
        foreach ($moderationFilter['params'] as $param) {
            $params[] = $param;
        }
        $params[] = $userId; // for ues_my subscription status JOIN

        // Standard filtering (identical for subscribed-only, which already has its JOIN)
        $conditions = [];
        if (!$isAdmin) {
            $conditions[] = "COALESCE(e.is_sysop_only, FALSE) = FALSE";
        }
        if ($filter === 'active') {
            $conditions[] = "e.is_active = TRUE";
        } elseif ($filter === 'inactive') {
            $conditions[] = "e.is_active = FALSE";
        }
        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }
        // 'all' filter shows everything

        // Order: Local first, then LovlyNet domain, then others, all sorted by tag
        $sql .= " ORDER BY
            CASE
                WHEN COALESCE(e.is_local, FALSE) = TRUE THEN 0
                WHEN LOWER(e.domain) = 'lovlynet' THEN 1
                ELSE 2
            END,
            e.tag";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $echoareas = $stmt->fetchAll();

        $binkpConfig = null;
        try {
            $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
        } catch (\Throwable $e) {
            $binkpConfig = null;
        }

        foreach ($echoareas as &$echoarea) {
            $echoPolicy = strtolower(trim((string)($echoarea['posting_name_policy'] ?? '')));
            if (in_array($echoPolicy, ['real_name', 'username'], true)) {
                $echoarea['effective_posting_name_policy'] = $echoPolicy;
            } else {
                $resolvedPolicy = 'real_name';
                $domain = trim((string)($echoarea['domain'] ?? ''));
                if ($domain !== '' && $binkpConfig !== null) {
                    try {
                        $resolvedPolicy = $binkpConfig->getPostingNamePolicyForDomain($domain);
                    } catch (\Throwable $e) {
                        $resolvedPolicy = 'real_name';
                    }
                }

                $echoarea['effective_posting_name_policy'] = in_array($resolvedPolicy, ['real_name', 'username'], true)
                    ? $resolvedPolicy
                    : 'real_name';
            }
        }
        unset($echoarea);

        $lovlyNetTags = [];
        foreach ($echoareas as $echoarea) {
            if (strcasecmp(trim((string)($echoarea['domain'] ?? '')), 'lovlynet') !== 0) {
                continue;
            }

            $tag = strtoupper(trim((string)($echoarea['tag'] ?? '')));
            if ($tag !== '') {
                $lovlyNetTags[] = $tag;
            }
        }
        $lovlyNetTags = array_values(array_unique($lovlyNetTags));
        $lovlyNetMetadataByTag = [];

        if ($lovlyNetTags !== []) {
            try {
                $lovlyNetClient = new \BinktermPHP\LovlyNetClient();
                if ($lovlyNetClient->isConfigured()) {
                    $lovlyNetAreas = $lovlyNetClient->getAreas();
                    if (!empty($lovlyNetAreas['success'])) {
                        foreach (($lovlyNetAreas['echoareas'] ?? []) as $remoteArea) {
                            $remoteTag = strtoupper(trim((string)($remoteArea['tag'] ?? '')));
                            if ($remoteTag === '' || !in_array($remoteTag, $lovlyNetTags, true)) {
                                continue;
                            }

                            $metadata = $remoteArea['metadata'] ?? [];
                            $lovlyNetMetadataByTag[$remoteTag] = is_array($metadata) ? $metadata : [];
                        }
                    }
                }
            } catch (\Throwable $e) {
                $lovlyNetMetadataByTag = [];
            }
        }

        foreach ($echoareas as &$echoarea) {
            $echoarea['lovlynet_metadata'] = [];
            $echoarea['lovlynet_setting_issues'] = [];
            $echoarea['lovlynet_has_setting_issues'] = false;

            if (strcasecmp(trim((string)($echoarea['domain'] ?? '')), 'lovlynet') !== 0) {
                continue;
            }

            $tag = strtoupper(trim((string)($echoarea['tag'] ?? '')));
            $metadata = $lovlyNetMetadataByTag[$tag] ?? [];
            $issues = [];

            if (array_key_exists('sysop_only', $metadata)) {
                $recommendedSysopOnly = filter_var($metadata['sysop_only'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                $actualSysopOnly = !empty($echoarea['is_sysop_only']);
                if ($recommendedSysopOnly !== null && $recommendedSysopOnly !== $actualSysopOnly) {
                    $issues[] = [
                        'setting' => 'sysop_only',
                        'recommended' => $recommendedSysopOnly,
                        'actual' => $actualSysopOnly,
                    ];
                }
            }

            $echoarea['lovlynet_metadata'] = $metadata;
            $echoarea['lovlynet_setting_issues'] = $issues;
            $echoarea['lovlynet_has_setting_issues'] = $issues !== [];
        }
        unset($echoarea);

        // Annotate each echoarea with the interest IDs it belongs to (when feature is enabled).
        if (\BinktermPHP\Config::env('ENABLE_INTERESTS', 'true') === 'true') {
            $im  = new \BinktermPHP\InterestManager();
            $map = $im->getEchoareaInterestMap();
            foreach ($echoareas as &$echoarea) {
                $echoarea['interest_ids'] = $map[(int)$echoarea['id']] ?? [];
            }
            unset($echoarea);
        }

        return $echoareas;
    }

    /**
     * @param array<int, array<string, mixed>> $areas
     * @return array<int, array<string, mixed>>
     */
    public function annotateAreasWithLocalStatus(array $areas, array $domains = []): array
    {
        if ($areas === []) {
            return $areas;
        }

        $tags = array_values(array_unique(array_filter(array_map(static function ($area) {
            return strtoupper(trim((string)($area['tag'] ?? '')));
        }, $areas))));

        if ($tags === []) {
            return $areas;
        }

        $placeholders = implode(',', array_fill(0, count($tags), '?'));
        [$domainClause, $domainParams] = $this->buildDomainWhereClause($domains);
        $stmt = $this->db->prepare("
            SELECT UPPER(tag) AS tag_key, id, domain, description, is_sysop_only, allow_media
            FROM echoareas
            WHERE UPPER(tag) IN ($placeholders)
              AND {$domainClause}
        ");
        $stmt->execute(array_merge($tags, $domainParams));
        $localRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $localByTag = [];
        foreach ($localRows as $row) {
            $tagKey = (string)($row['tag_key'] ?? '');
            if ($tagKey !== '' && !isset($localByTag[$tagKey])) {
                $localByTag[$tagKey] = $row;
            }
        }

        foreach ($areas as &$area) {
            $tagKey = strtoupper(trim((string)($area['tag'] ?? '')));
            $local = $localByTag[$tagKey] ?? null;
            $area['local_exists'] = $local !== null;
            $area['local_echoarea_id'] = $local !== null ? (int)$local['id'] : null;
            $area['local_domain'] = $local['domain'] ?? null;
            $area['local_description'] = $local['description'] ?? null;
            $area['local_is_sysop_only'] = $local !== null ? !empty($local['is_sysop_only']) : null;
            $rawAllowMedia = $local['allow_media'] ?? null;
            $area['local_allow_media'] = ($local !== null && $rawAllowMedia !== null)
                ? filter_var($rawAllowMedia, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                : null;
            $remoteDescription = trim((string)($area['description'] ?? ''));
            $localDescription = trim((string)($local['description'] ?? ''));
            $area['description_mismatch'] = $local !== null && $remoteDescription !== '' && $remoteDescription !== $localDescription;
        }
        unset($area);

        return $areas;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createIfMissing(array $data, array $domains = []): int
    {
        $tag = self::normalizeTag((string)($data['tag'] ?? ''));
        if ($tag === '') {
            throw new \InvalidArgumentException('Echo area tag is required');
        }

        $existing = $this->findByTagAndDomains($tag, $domains);
        if ($existing) {
            return (int)$existing['id'];
        }

        $description = trim((string)($data['description'] ?? ''));
        if ($description === '') {
            $description = $tag;
        }

        $domain = $data['domain'] ?? null;
        $normalizedDomain = is_string($domain) ? trim($domain) : null;
        if ($normalizedDomain === '') {
            $normalizedDomain = null;
        }

        $uplinkAddress = $data['uplink_address'] ?? null;
        $normalizedUplinkAddress = is_string($uplinkAddress) ? trim($uplinkAddress) : null;
        if ($normalizedUplinkAddress === '') {
            $normalizedUplinkAddress = null;
        }

        $isLocal = array_key_exists('is_local', $data) ? (bool)$data['is_local'] : ($normalizedDomain === null);
        $isActive = array_key_exists('is_active', $data) ? (bool)$data['is_active'] : true;
        $isSysopOnly = array_key_exists('is_sysop_only', $data) ? (bool)$data['is_sysop_only'] : false;
        $geminiPublic = array_key_exists('gemini_public', $data) ? (bool)$data['gemini_public'] : false;
        $moderator = isset($data['moderator']) && trim((string)$data['moderator']) !== '' ? trim((string)$data['moderator']) : null;
        $postingNamePolicy = isset($data['posting_name_policy']) && trim((string)$data['posting_name_policy']) !== '' ? trim((string)$data['posting_name_policy']) : null;
        $artFormatHint = isset($data['art_format_hint']) && trim((string)$data['art_format_hint']) !== '' ? trim((string)$data['art_format_hint']) : null;
        $missingChrsCharset = \BinktermPHP\MessageCharsetConverter::normalizeSupportedCharset($data['missing_chrs_charset'] ?? null);
        $color = isset($data['color']) && trim((string)$data['color']) !== '' ? trim((string)$data['color']) : '#28a745';

        $insertStmt = $this->db->prepare("
            INSERT INTO echoareas (
                tag, description, moderator, uplink_address,
                posting_name_policy, art_format_hint, missing_chrs_charset, color,
                is_active, is_local, is_sysop_only, domain, gemini_public
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            RETURNING id
        ");
        $insertStmt->execute([
            $tag,
            $description,
            $moderator,
            $normalizedUplinkAddress,
            $postingNamePolicy,
            $artFormatHint,
            $missingChrsCharset,
            $color,
            $isActive ? 'true' : 'false',
            $isLocal ? 'true' : 'false',
            $isSysopOnly ? 'true' : 'false',
            $normalizedDomain,
            $geminiPublic ? 'true' : 'false',
        ]);

        $row = $insertStmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['id'] : 0;
    }

    public function updateDescription(int $id, string $description): bool
    {
        if ($id <= 0) {
            return false;
        }

        $normalizedDescription = trim($description);
        if ($normalizedDescription === '') {
            return false;
        }

        $stmt = $this->db->prepare("UPDATE echoareas SET description = ? WHERE id = ?");
        return $stmt->execute([$normalizedDescription, $id]);
    }

    public function updateSysopOnly(int $id, bool $isSysopOnly): bool
    {
        if ($id <= 0) {
            return false;
        }

        $stmt = $this->db->prepare("UPDATE echoareas SET is_sysop_only = ? WHERE id = ?");
        return $stmt->execute([$isSysopOnly ? 'true' : 'false', $id]);
    }

    public function updateAllowMedia(int $id, ?bool $allowMedia): bool
    {
        if ($id <= 0) {
            return false;
        }

        $value = $allowMedia === null ? null : ($allowMedia ? 'true' : 'false');
        $stmt = $this->db->prepare("UPDATE echoareas SET allow_media = ? WHERE id = ?");
        return $stmt->execute([$value, $id]);
    }

    /**
     * @return array{0:string,1:array<int,string>}
     */
    private function buildDomainWhereClause(array $domains): array
    {
        $normalized = [];
        foreach ($domains as $domain) {
            $value = strtolower(trim((string)$domain));
            if ($value === '') {
                $normalized[] = '';
            } elseif (!in_array($value, $normalized, true)) {
                $normalized[] = $value;
            }
        }

        if ($normalized === []) {
            return ['1=1', []];
        }

        $parts = [];
        $params = [];
        foreach ($normalized as $domain) {
            if ($domain === '') {
                $parts[] = "(domain IS NULL OR domain = '')";
            } else {
                $parts[] = "LOWER(domain) = ?";
                $params[] = $domain;
            }
        }

        return ['(' . implode(' OR ', $parts) . ')', $params];
    }
}
