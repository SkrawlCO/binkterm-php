<?php
/**
 * Door Session Manager
 *
 * Manages DOSBox door game sessions - spawning bridge servers, DOSBox instances,
 * tracking active sessions, and cleanup.
 *
 * @package BinktermPHP
 */

namespace BinktermPHP;

use BinktermPHP\Binkp\Logger;
use Exception;
use PDO;

class DoorSessionManager
{
    private $basePath;
    private $bridgePath; // Legacy - no longer used (multiplexing bridge runs separately)
    private $dosboxPath;
    private $configPath;
    private $db;
    private $processHandles = []; // Store process resources (not serialized)
    private $headlessMode = true; // Use production headless config by default
    private $maxSessions; // Maximum simultaneous sessions (configurable via DOSDOOR_MAX_SESSIONS)
    private Logger $logger;

    // Port ranges for multi-user support
    private const TCP_PORT_BASE = 5000;
    private const WS_PORT_BASE = 6000;

    /**
     * Transaction-level advisory lock key that serializes the authoritative
     * door-session admission and node-allocation critical section across every
     * caller and every door.
     *
     * Node numbers are drawn from a single global pool (1..DOSDOOR_MAX_SESSIONS)
     * and per-door `max_nodes` is enforced inside the same transaction, so one
     * global lock — not a per-door lock — is what protects both invariants. The
     * value is an arbitrary fixed 63-bit constant chosen not to collide with any
     * other advisory-lock user (there are currently none). It is public so that
     * operational tooling and tests can observe or serialize against the same
     * critical section (e.g. inspecting `pg_locks` for `locktype = 'advisory'`).
     *
     * @see findAvailableNode()
     */
    public const ADMISSION_LOCK_KEY = 4017538266112247;

    /**
     * Constructor
     *
     * @param string|null $basePath Base path for BinktermPHP
     * @param bool $headless Use headless mode (true) or visible window for testing (false)
     */
    public function __construct($basePath = null, bool $headless = true)
    {
        $this->basePath = $basePath ?? (defined('BINKTERMPHP_BASEDIR')
            ? BINKTERMPHP_BASEDIR
            : realpath(__DIR__ . '/..'));

        $this->bridgePath = $this->basePath . '/scripts/dosbox-bridge/multiplexing-server.js';
        $this->dosboxPath = $this->basePath . '/dosbox-bridge';
        $this->headlessMode = $headless;

        // Load max sessions from environment (default: 100)
        $this->maxSessions = (int)Config::env('DOSDOOR_MAX_SESSIONS', '10
        ');

        // Choose config file
        // 1. Check environment variable (allows custom config files)
        // 2. Fall back to headless mode parameter
        $configFile = Config::env('DOSDOOR_CONFIG');
        if (!$configFile) {
            $configFile = $headless
                ? 'dosbox-bridge-production.conf'
                : 'dosbox-bridge-test.conf';
        }
        $this->configPath = $this->basePath . '/dosbox-bridge/' . $configFile;

        // Initialize database connection
        $this->db = Database::getInstance()->getPdo();

        $this->logger = new Logger(Config::getLogPath('dosdoor.log'), Logger::LEVEL_INFO, false);
    }

    /**
     * Start a door game session
     *
     * @param int $userId User ID
     * @param string $doorName Door game name (e.g., 'lord')
     * @param array $userData User data for drop file
     * @param string $doorType Door type: 'dos', 'native', or 'rlogin'
     * @return array Session information
     * @throws Exception If session cannot be started
     */
    public function startSession(
        int $userId,
        string $doorName,
        array $userData,
        string $doorType = 'dos',
        ?string $authSessionId = null
    ): array
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? 'cli';
        $this->logger->info("[StartSession] BEGIN - User: $userId, Door: $doorName, Type: $doorType, IP: $remoteAddr");

        // Reap expired sessions before allocating a node so stale records don't
        // block new connections when users close the browser without ending the session.
        $this->cleanExpiredSessions();

        // Get door information from manifest to get the display name
        if ($doorType === 'native') {
            $doorManager = new NativeDoorManager();
        } elseif ($doorType === 'rlogin') {
            $doorManager = new RLoginDoorManager();
        } else {
            $doorManager = new DoorManager();
        }
        $doorInfo = $doorManager->getDoor($doorName);

        if (!$doorInfo) {
            $this->logger->error("[StartSession] Door not found: $doorName");
            throw new Exception("Door not found: $doorName");
        }

        $doorDisplayName = $doorInfo['name'] ?? $doorName;

        // Authoritative per-door concurrency limit, resolved exactly the way the
        // HTTP routes resolve it: manifest `max_nodes`, else `config.max_sessions`.
        // Re-checked inside the admission transaction (findAvailableNode()); any
        // route-level COUNT is only a fast-path pre-filter, never the guard.
        $maxNodes = null;
        if (isset($doorInfo['max_nodes']) && is_numeric($doorInfo['max_nodes'])) {
            $maxNodes = (int)$doorInfo['max_nodes'];
        } elseif (isset($doorInfo['config']['max_sessions']) && is_numeric($doorInfo['config']['max_sessions'])) {
            $maxNodes = (int)$doorInfo['config']['max_sessions'];
        }

        try {
            // Reserve a node inside the serialized admission transaction. This
            // enforces per-door capacity and allocates a globally-unique node
            // number in one critical section (throws DoorCapacityException when
            // the door is already full).
            $node = $this->findAvailableNode($doorName, $maxNodes);
            if ($node === null) {
                $this->logger->error("[StartSession] No available nodes (max {$this->maxSessions} sessions)");
                // Transaction already rolled back in findAvailableNode()
                throw new Exception('No available door nodes (max ' . $this->maxSessions . ' sessions)');
            }

        // Generate session ID
        $sessionId = DoorDropFile::generateSessionId($userId, $node);
        $this->logger->info("[StartSession] Session ID: $sessionId, IP: $remoteAddr");

        // Prepare user data for drop file generation (bridge will create DOOR.SYS)
        // Add session-specific data to user data
        $userData['com_port'] = 'COM1:';
        $userData['node'] = $node;
        $userData['baud_rate'] = 115200;
        $userData['time_remaining'] = 7200; // 2 hours

        // Generate WebSocket authentication token
        $wsToken = bin2hex(random_bytes(32)); // 64 character hex string

        // WebSocket port is shared (single multiplexing bridge for all sessions)
        $wsPort = (int)Config::env('DOSDOOR_WS_PORT', '6001');

        // Calculate expiration time (default 2 hours)
        $expiresAt = gmdate('Y-m-d H:i:s', time() + 7200);

        // Save session to database (bridge will create session_path, tcp_port, dosbox_pid, DOOR.SYS)
        // Transaction was started in findAvailableNode() to lock node allocation
        $stmt = $this->db->prepare("
            INSERT INTO door_sessions (
                session_id, user_id, door_id, node_number,
                ws_port, expires_at, ws_token, user_data, door_type,
                auth_session_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $sessionId,
            $userId,
            $doorName,
            $node,
            $wsPort,
            $expiresAt,
            $wsToken,
            json_encode($userData),
            $doorType,
            $authSessionId
        ]);

        // Commit transaction (releases node allocation lock)
        $this->db->commit();
        $this->logger->debug("[StartSession] Transaction committed - Node $node locked");

        // Log session creation
        $this->logSessionEvent($sessionId, 'created', [
            'door' => $doorName,
            'node' => $node,
        ]);

            $this->logger->info("[StartSession] Session created - Node $node, bridge will launch DOSBox, IP: $remoteAddr");

            // Return session info (bridge will create session_path, allocate tcp_port, launch DOSBox)
            $session = [
                'session_id' => $sessionId,
                'user_id' => $userId,
                'door_id' => $doorName,
                'door_name' => $doorDisplayName,
                'node' => $node,
                'ws_port' => $wsPort,
                'ws_token' => $wsToken,
                'started_at' => time(),
                'expires_at' => $expiresAt,
            ];

            return $session;

        } catch (Exception $e) {
            // Rollback transaction if it's still active
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
                $this->logger->error("[StartSession] Transaction rolled back due to error");
            }
            throw $e;
        }
    }

    /**
     * End a door game session
     *
     * @param string $sessionId Session identifier
     * @return bool Success
     */
    public function endSession(string $sessionId, bool $runtimeTerminationConfirmed = false): bool
    {
        $this->logger->info("[EndSession] BEGIN - Session: $sessionId");

        // Get session from database
        $session = $this->getSession($sessionId);
        if (!$session) {
            $this->logger->error("[EndSession] Session not found: $sessionId");
            return false;
        }

        $dosboxPid = $session['dosbox_pid'] ?? null;
        $bridgePid = $session['bridge_pid'] ?? null;

        // Fallback runtime kill -- only when the bridge has NOT already
        // confirmed teardown for us. Gated on the immutable identity tuple the
        // bridge persisted at launch (pgid + /proc start-time + boot id): a
        // recycled PID, or the absence of that record, means we signal nothing.
        if ($dosboxPid && !$runtimeTerminationConfirmed) {
            $identity = $this->loadRuntimeIdentity($session);
            $this->logger->debug("[EndSession] Fallback runtime kill for PID: $dosboxPid");
            $killed = $this->killProcess((int)$dosboxPid, $identity);
            if ($killed) {
                $this->logger->debug("[EndSession] Runtime terminated");
            } else {
                $this->logger->warning("[EndSession] Runtime not terminated for PID $dosboxPid (already gone, identity mismatch, or unprovable ownership)");
            }
        }

        // Clean up process handle if it exists
        if (isset($this->processHandles[$sessionId])) {
            $this->logger->debug("[EndSession] Closing process handle");
            proc_close($this->processHandles[$sessionId]);
            unset($this->processHandles[$sessionId]);
        }

        // Note: Bridge is shared multiplexing server - don't kill it

        // Cleanup drop files
        $dropFile = new DoorDropFile();
        $dropFile->cleanupSession($sessionId);

        // Update database - mark session as ended
        $stmt = $this->db->prepare("
            UPDATE door_sessions
            SET ended_at = NOW(), exit_status = ?
            WHERE session_id = ?
        ");
        $stmt->execute(['normal', $sessionId]);

        // Clear Experience presence for the BinkTerm auth session that
        // currently owns this door session's presence.
        $authSessionId = $session['auth_session_id'] ?? null;

        if (is_string($authSessionId) && $authSessionId !== '') {
            try {
                (new ExperiencePresence())->leave($authSessionId);
            } catch (\Throwable $e) {
                $this->logger->warning(
                    "[EndSession] Failed to clear Experience presence: "
                    . $e->getMessage()
                );
            }
        }

        // Log session termination
        $this->logSessionEvent($sessionId, 'terminated', ['exit_status' => 'normal']);

        $this->logger->info("[EndSession] COMPLETE - Session: $sessionId");
        return true;
    }

    /**
     * Return a session row regardless of active/ended state for authenticated
     * ownership and idempotency checks. Tokens remain server-side only.
     */
    public function getSessionRecord(string $sessionId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM door_sessions WHERE session_id = ?');
        $stmt->execute([$sessionId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        return $session ?: null;
    }

    /**
     * Mark all sessions that have passed their expiry time as ended.
     *
     * This is called automatically at the start of every new session request so
     * that sessions left open by browser-closes or crashes do not permanently
     * consume node slots.
     *
     * @return int Number of sessions expired
     */
    public function cleanExpiredSessions(): int
    {
        $stmt = $this->db->prepare("
            UPDATE door_sessions
            SET ended_at = NOW(), exit_status = 'expired'
            WHERE ended_at IS NULL AND expires_at < NOW()
        ");
        $stmt->execute();
        $count = $stmt->rowCount();
        if ($count > 0) {
            $this->logger->info("[CleanExpired] Marked $count expired session(s) as ended");
        }
        return $count;
    }

    /**
     * Get door display name from manifest
     *
     * @param string $doorId Door ID
     * @return string Display name or door ID if not found
     */
    private function getDoorDisplayName(string $doorId): string
    {
        static $doorManager = null;
        if ($doorManager === null) {
            $doorManager = new DoorManager();
        }

        $doorInfo = $doorManager->getDoor($doorId);
        return $doorInfo['name'] ?? $doorId;
    }

    /**
     * Assign the BinkTerm auth session that currently owns Experience presence.
     */
    public function setAuthSessionId(
        string $sessionId,
        ?string $authSessionId
    ): bool
    {
        $currentStmt = $this->db->prepare("
            SELECT auth_session_id
            FROM door_sessions
            WHERE session_id = ?
              AND ended_at IS NULL
        ");
        $currentStmt->execute([$sessionId]);

        $currentAuthSessionId = $currentStmt->fetchColumn();

        if ($currentAuthSessionId === false) {
            return false;
        }

        $stmt = $this->db->prepare("
            UPDATE door_sessions
            SET auth_session_id = ?
            WHERE session_id = ?
              AND ended_at IS NULL
        ");

        $stmt->execute([
            $authSessionId,
            $sessionId,
        ]);

        if (
            is_string($currentAuthSessionId)
            && $currentAuthSessionId !== ''
            && $currentAuthSessionId !== $authSessionId
        ) {
            try {
                (new ExperiencePresence())->leave(
                    $currentAuthSessionId
                );
            } catch (\Throwable $e) {
                $this->logger->warning(
                    "[PresenceOwner] Failed to clear previous "
                    . "Experience presence: "
                    . $e->getMessage()
                );
            }
        }

        return true;
    }

    /**
     * Get active session by ID
     *
     * @param string $sessionId Session identifier
     * @return array|null Session data or null if not found
     */
    public function getSession(string $sessionId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM door_sessions
            WHERE session_id = ? AND ended_at IS NULL
        ");
        $stmt->execute([$sessionId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$session) {
            return null;
        }

        // Convert database format to expected format
        return [
            'session_id' => $session['session_id'],
            'user_id' => $session['user_id'],
            'door_id' => $session['door_id'],
            'door_name' => $this->getDoorDisplayName($session['door_id']),
            'node' => $session['node_number'],
            'tcp_port' => $session['tcp_port'],
            'ws_port' => $session['ws_port'],
            'ws_token' => $session['ws_token'] ?? null,
            'auth_session_id' => $session['auth_session_id'] ?? null,
            'bridge_pid' => $session['bridge_pid'],
            'dosbox_pid' => $session['dosbox_pid'],
            'session_path' => $session['session_path'],
            'started_at' => strtotime($session['started_at']),
            'expires_at' => $session['expires_at'],
        ];
    }

    /**
     * Get all active sessions
     *
     * @return array Active sessions
     */
    public function getActiveSessions(): array
    {
        $stmt = $this->db->query("
            SELECT * FROM door_sessions
            WHERE ended_at IS NULL AND expires_at > NOW()
            ORDER BY started_at DESC
        ");

        $sessions = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $sessions[] = [
                'session_id' => $row['session_id'],
                'user_id' => $row['user_id'],
                'door_id' => $row['door_id'],
                'door_name' => $this->getDoorDisplayName($row['door_id']),
                'node' => $row['node_number'],
                'tcp_port' => $row['tcp_port'],
                'ws_port' => $row['ws_port'],
                'ws_token' => $row['ws_token'] ?? null,
                'bridge_pid' => $row['bridge_pid'],
                'dosbox_pid' => $row['dosbox_pid'],
                'session_path' => $row['session_path'],
                'started_at' => strtotime($row['started_at']),
                'expires_at' => $row['expires_at'],
            ];
        }

        return $sessions;
    }

    /**
     * Get active session for a user, optionally filtered by door ID
     *
     * @param int $userId User ID
     * @param string|null $doorId Optional door ID to filter by
     * @return array|null Session data or null if not found
     */
    public function getUserSession(int $userId, ?string $doorId = null): ?array
    {
        if ($doorId !== null) {
            $stmt = $this->db->prepare("
                SELECT * FROM door_sessions
                WHERE user_id = ? AND door_id = ? AND ended_at IS NULL AND expires_at > NOW()
                ORDER BY started_at DESC
                LIMIT 1
            ");
            $stmt->execute([$userId, $doorId]);
        } else {
            $stmt = $this->db->prepare("
                SELECT * FROM door_sessions
                WHERE user_id = ? AND ended_at IS NULL AND expires_at > NOW()
                ORDER BY started_at DESC
                LIMIT 1
            ");
            $stmt->execute([$userId]);
        }
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$session) {
            return null;
        }

        return [
            'session_id' => $session['session_id'],
            'user_id' => $session['user_id'],
            'door_id' => $session['door_id'],
            'door_name' => $this->getDoorDisplayName($session['door_id']),
            'node' => $session['node_number'],
            'tcp_port' => $session['tcp_port'],
            'ws_port' => $session['ws_port'],
            'ws_token' => $session['ws_token'] ?? null,
            'bridge_pid' => $session['bridge_pid'],
            'dosbox_pid' => $session['dosbox_pid'],
            'session_path' => $session['session_path'],
            'started_at' => strtotime($session['started_at']),
            'expires_at' => $session['expires_at'],
        ];
    }

    /**
     * Start bridge server process
     *
     * @param int $tcpPort TCP port for DOSBox connection
     * @param int $wsPort WebSocket port for browser
     * @param string $sessionId Session identifier
     * @param string $wsToken WebSocket authentication token
     * @return int Process ID
     * @throws Exception If bridge cannot be started
     */
    private function startBridge(int $tcpPort, int $wsPort, string $sessionId, string $wsToken): int
    {
        $nodeExe = $this->findNodeExecutable();
        if (!$nodeExe) {
            throw new Exception('Node.js executable not found');
        }

        if (!file_exists($this->bridgePath)) {
            throw new Exception('Bridge server not found at: ' . $this->bridgePath);
        }

        // Get disconnect timeout from environment (0 = immediate, default)
        $disconnectTimeout = (int)Config::env('DOSDOOR_DISCONNECT_TIMEOUT', '0');

        // Build command with authentication token
        // Note: On Windows, don't use escapeshellarg() - it adds single quotes that cmd.exe doesn't handle
        // Instead, wrap in double quotes for arguments with spaces
        $cmd = sprintf(
            '%s "%s" %d %d "%s" %d "%s"',
            $nodeExe,
            $this->bridgePath,
            $tcpPort,
            $wsPort,
            $sessionId,
            $disconnectTimeout,
            $wsToken
        );
        $this->logger->debug("[Bridge] Full command: $cmd");

        // Start bridge in background
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows: use start /B to run in background
            $cmd = 'start /B ' . $cmd . ' > nul 2>&1';
            pclose(popen($cmd, 'r'));

            // On Windows, we can't easily get PID from popen, so we'll find it
            // Retry for up to 5 seconds
            $pid = null;
            for ($i = 0; $i < 10; $i++) {
                usleep(500000); // Wait 0.5 seconds
                $pid = $this->findProcessByPort($wsPort);
                if ($pid) {
                    break;
                }
            }
        } else {
            // Linux/Mac: use & to background and get PID
            $cmd .= ' > /dev/null 2>&1 & echo $!';
            $pid = (int)shell_exec($cmd);
        }

        if (!$pid) {
            throw new Exception('Failed to start bridge server - process not detected on port ' . $wsPort);
        }

        return $pid;
    }

    /**
     * Start DOSBox process
     *
     * @param string $sessionId Session identifier
     * @param string $sessionPath Session directory path
     * @param string $doorName Door game name
     * @param int $node Node number
     * @return int Process ID
     * @throws Exception If DOSBox cannot be started
     */
    private function startDosBox(string $sessionId, string $sessionPath, string $doorName, int $node): int
    {
        $dosboxExe = $this->findDosBoxExecutable();
        if (!$dosboxExe) {
            throw new Exception('DOSBox-X executable not found');
        }

        if (!file_exists($this->configPath)) {
            throw new Exception('DOSBox config not found at: ' . $this->configPath);
        }

        // Copy drop file to door's directory as DOOR<node>.SYS
        $dropFileSrc = $sessionPath . '/DOOR.SYS';
        $doorDir = $this->basePath . '/dosbox-bridge/dos/DOORS/' . strtoupper($doorName);

        if (!is_dir($doorDir)) {
            throw new Exception("Door directory not found: $doorDir");
        }

        // LORD expects DOOR<node>.SYS (e.g., DOOR1.SYS for node 1)
        $dropFileDest = $doorDir . '/DOOR' . $node . '.SYS';
        if (!copy($dropFileSrc, $dropFileDest)) {
            throw new Exception("Failed to copy drop file to door directory");
        }

        // Load door manifest to get executable and path info
        $manifestScanner = new \BinktermPHP\DosBoxDoorManifest($this->basePath);
        $doorManifest = $manifestScanner->getDoorManifest($doorName);

        if (!$doorManifest) {
            throw new Exception("Door manifest not found for: $doorName");
        }

        // Extract the DOS path from the directory (remove "dosbox-bridge/dos" prefix)
        // e.g., "dosbox-bridge/dos/DOORS/LORD" becomes "\DOORS\LORD"
        $fullDir = $doorManifest['directory'];
        $dosPath = str_replace('dosbox-bridge/dos', '', $fullDir);
        $dosPath = str_replace('/', '\\', $dosPath); // Convert to DOS path separators

        // Get the launch command from manifest, or build a default one
        if (!empty($doorManifest['launch_command'])) {
            $launchCmd = $doorManifest['launch_command'];
        } else {
            // Fallback: auto-generate based on executable
            $executable = $doorManifest['executable'];
            if (strtoupper(pathinfo($executable, PATHINFO_EXTENSION)) === 'BAT') {
                $launchCmd = "call " . strtolower($executable) . " {node}";
            } else {
                $launchCmd = strtolower($executable) . " {node}";
            }
        }

        // Replace macros in launch command
        $dropFileName = "DOOR" . $node . ".SYS";
        $launchCmd = str_replace('{node}', $node, $launchCmd);
        $launchCmd = str_replace('{dropfile}', $dropFileName, $launchCmd);

        // Build the door-specific autoexec commands
        // DOSBox connects to bridge before autoexec runs, so carrier is detected
        $doorCommands = "cd $dosPath\n";
        $doorCommands .= $launchCmd;

        // Generate session-specific config
        $baseConfig = file_get_contents($this->configPath);

        // Replace the placeholder comment with door-specific commands
        $sessionConfig = str_replace(
            '# Door-specific commands will be appended here',
            $doorCommands,
            $baseConfig
        );

        // Update TCP port in serial configuration (must match bridge server port)
        $tcpPort = self::TCP_PORT_BASE + $node;
        $sessionConfig = preg_replace(
            '/port:\d+/',
            'port:' . $tcpPort,
            $sessionConfig
        );

        $sessionConfigPath = $sessionPath . '/dosbox.conf';
        file_put_contents($sessionConfigPath, $sessionConfig);

        // Build command
        if ($this->headlessMode) {
            // Headless mode: -nogui hides window, -exit closes after game exits
            $cmd = sprintf(
                '"%s" -nogui -conf "%s" -exit',
                $dosboxExe,
                $sessionConfigPath
            );
        } else {
            // Visible mode for testing: -exit closes after game exits
            $cmd = sprintf(
                '"%s" -conf "%s" -exit',
                $dosboxExe,
                $sessionConfigPath
            );
        }

        // Start DOSBox in background
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Get list of dosbox PIDs BEFORE starting (to find the new one)
            $pidsBefore = $this->getDosBoxPids();
            $this->logger->debug("[Launch] Command: $cmd");

            // Windows: use proc_open
            $descriptors = [
                0 => ['pipe', 'r'],  // stdin
                1 => ['pipe', 'w'],  // stdout
                2 => ['pipe', 'w'],  // stderr
            ];

            // HEADLESS MODE - WINDOWS
            // On Windows: Use windowposition=-2000,-2000 in config (window offscreen)
            // SDL_VIDEODRIVER=dummy breaks serial ports on Windows, so don't use it
            $env = null;

            // Linux testing: Uncomment below to test SDL_VIDEODRIVER=dummy on Linux
            // if ($this->headlessMode) {
            //     $env = array_merge($_ENV, ['SDL_VIDEODRIVER' => 'dummy']);
            // }

            // Set working directory to project root so relative paths in config work
            $options = ['bypass_shell' => false];

            $process = proc_open($cmd, $descriptors, $pipes, $this->basePath, $env, $options);
            if (!is_resource($process)) {
                $this->logger->error("[Launch] proc_open failed - not a resource");
                throw new Exception('Failed to start DOSBox');
            }

            // Close pipes immediately (don't read stderr as it can block on Windows)
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            // Wait for DOSBox to actually start
            sleep(2);

            // Find the NEW dosbox PID that wasn't there before
            $pidsAfter = $this->getDosBoxPids();
            $newPids = array_diff($pidsAfter, $pidsBefore);

            $this->logger->debug("[Launch] PIDs before: " . implode(', ', $pidsBefore) . " | after: " . implode(', ', $pidsAfter) . " | new: " . implode(', ', $newPids));

            if (empty($newPids)) {
                $this->logger->error("[Launch] FAILED - No new DOSBox process detected");
                throw new Exception('Failed to detect DOSBox-X process');
            }

            // Use the first new PID (should only be one)
            $pid = reset($newPids);
            $this->logger->info("[Launch] DOSBox started - PID: $pid");

            // Store process handle for cleanup (can't be serialized, kept in memory)
            $this->processHandles[$sessionId] = $process;
        } else {
            // HEADLESS MODE - LINUX/MAC
            // On Linux: SDL_VIDEODRIVER=dummy MAY work (needs testing)
            // Config uses windowposition=-2000,-2000 as fallback

            // Uncomment below to test SDL_VIDEODRIVER=dummy on Linux:
            // if ($this->headlessMode) {
            //     $cmd = 'SDL_VIDEODRIVER=dummy ' . $cmd;
            // }

            $cmd .= ' & echo $!';
            $pid = (int)shell_exec($cmd);
        }

        if (!$pid) {
            throw new Exception('Failed to start DOSBox');
        }

        return $pid;
    }

    /**
     * Reserve a node number for a new session inside a serialized admission
     * transaction.
     *
     * The transaction's first action is a global transaction-level advisory lock
     * ({@see self::ADMISSION_LOCK_KEY}), so only one admission runs at a time
     * across every caller and every door. Inside that critical section it:
     *
     *   1. re-checks the per-door active-session count against `$maxNodes` and
     *      throws {@see DoorCapacityException} if the door is already full, then
     *   2. allocates the lowest free node number from the global pool
     *      (1..DOSDOOR_MAX_SESSIONS), "free" meaning not held by any session
     *      with `ended_at IS NULL`.
     *
     * The caller must INSERT the `door_sessions` row and commit on this same
     * connection; the advisory lock and the transaction release together at
     * commit/rollback.
     *
     * A plain `SELECT ... FOR UPDATE` is not a sufficient mutex here: with no
     * active sessions it locks no rows, so two concurrent allocators each see an
     * empty pool and pick the same node, and each passes a per-door COUNT that
     * runs before the other's INSERT is visible under READ COMMITTED.
     *
     * @param string|null $doorId   Door being launched, for the per-door check
     * @param int|null    $maxNodes Per-door concurrency limit, or null to skip it
     * @return int|null Allocated node number, or null if the global pool is full
     * @throws DoorCapacityException If the door already has `$maxNodes` sessions
     */
    private function findAvailableNode(?string $doorId = null, ?int $maxNodes = null): ?int
    {
        $this->db->beginTransaction();

        try {
            // Global admission critical section. Held until this transaction
            // commits or rolls back; serializes every concurrent launch.
            $this->db->query('SELECT pg_advisory_xact_lock(' . self::ADMISSION_LOCK_KEY . ')')->closeCursor();

            // Authoritative per-door capacity check. Runs under the advisory
            // lock and after any expired-session cleanup, so the count cannot be
            // raced by another admission.
            if ($doorId !== null && $maxNodes !== null) {
                $capStmt = $this->db->prepare("
                    SELECT COUNT(*) FROM door_sessions
                    WHERE door_id = ? AND ended_at IS NULL AND expires_at > NOW()
                ");
                $capStmt->execute([$doorId]);
                $activeForDoor = (int)$capStmt->fetchColumn();

                if ($activeForDoor >= $maxNodes) {
                    $this->db->rollBack();
                    $this->logger->warning("[NodeAlloc] Door '$doorId' at capacity ($activeForDoor/$maxNodes)");
                    throw new DoorCapacityException($doorId, $maxNodes, $activeForDoor);
                }
            }

            // Allocate the lowest free node from the global pool. FOR UPDATE is
            // kept as defence in depth; the advisory lock is the real mutex.
            $stmt = $this->db->query("
                SELECT node_number FROM door_sessions
                WHERE ended_at IS NULL
                ORDER BY node_number
                FOR UPDATE
            ");
            $usedNodes = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $this->logger->debug("[NodeAlloc] Used nodes: " . (empty($usedNodes) ? 'none' : implode(', ', $usedNodes)));

            // Find first available node
            for ($i = 1; $i <= $this->maxSessions; $i++) {
                if (!in_array($i, $usedNodes)) {
                    $this->logger->debug("[NodeAlloc] Assigned node: $i");
                    // Don't commit yet - caller will insert the session and commit
                    return $i;
                }
            }

            // No available nodes
            $this->db->rollBack();
            $this->logger->warning("[NodeAlloc] No available nodes (max " . $this->maxSessions . ")");
            return null;

        } catch (DoorCapacityException $e) {
            // Already rolled back above; surface as-is for the HTTP layer.
            throw $e;
        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error("[NodeAlloc] Error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Find Node.js executable
     *
     * @return string|null Path to node executable
     */
    private function findNodeExecutable(): ?string
    {
        $paths = ['node', 'nodejs', '/usr/bin/node', '/usr/local/bin/node'];

        foreach ($paths as $path) {
            $result = shell_exec($path . ' --version 2>&1');
            if ($result && strpos($result, 'v') === 0) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Find DOSBox executable
     *
     * @return string|null Full path to dosbox executable
     */
    private function findDosBoxExecutable(): ?string
    {
        // Check for env variable first
        $envPath = Config::env('DOSBOX_EXECUTABLE');
        if ($envPath && file_exists($envPath)) {
            return $envPath;
        }

        // Fallback to auto-detection
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

        if ($isWindows) {
            // Windows: try default location first
            $defaultPath = 'c:\\dosbox-x\\dosbox-x.exe';
            if (file_exists($defaultPath)) {
                return $defaultPath;
            }

            // Windows: use 'where' command to find full path
            $result = shell_exec('where dosbox-x 2>&1');
            if ($result && stripos($result, 'Could not find') === false) {
                // 'where' might return multiple paths, use the first one
                $lines = explode("\n", trim($result));
                return trim($lines[0]);
            }

            $result = shell_exec('where dosbox 2>&1');
            if ($result && stripos($result, 'Could not find') === false) {
                $lines = explode("\n", trim($result));
                return trim($lines[0]);
            }
        } else {
            // Linux/Mac: try default location first
            $defaultPath = '/usr/bin/dosbox-x';
            if (file_exists($defaultPath)) {
                return $defaultPath;
            }

            // Linux/Mac: use 'which' command
            $result = shell_exec('which dosbox-x 2>&1');
            if ($result && strpos($result, '/') === 0) {
                return trim($result);
            }

            $result = shell_exec('which dosbox 2>&1');
            if ($result && strpos($result, '/') === 0) {
                return trim($result);
            }
        }

        return null;
    }

    /**
     * Check if a process is running
     *
     * @param int $pid Process ID
     * @return bool True if running
     */
    public function isProcessRunning(int $pid): bool
    {
        if (!$pid) {
            return false;
        }

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows: use tasklist
            exec("tasklist /FI \"PID eq $pid\" 2>nul", $output);
            foreach ($output as $line) {
                if (strpos($line, (string)$pid) !== false) {
                    return true;
                }
            }
            return false;
        } else {
            // Linux/Mac: send signal 0 (doesn't kill, just checks)
            exec("kill -0 $pid 2>&1", $output, $returnCode);
            return $returnCode === 0;
        }
    }

    /**
     * Load the runtime identity tuple the multiplexing bridge persisted for a
     * session at launch (data/run/door_sessions/<id>/runtime.json). Returns
     * null when there is no manifest or it has no identity (e.g. rlogin, which
     * has no local process).
     *
     * @param array $session door_sessions row
     * @return array{pid:int,pgid:int,starttime:string,bootId:?string,ownsGroup:bool}|null
     */
    private function loadRuntimeIdentity(array $session): ?array
    {
        $sessionId = (string)($session['session_id'] ?? '');
        if ($sessionId === '') {
            return null;
        }
        $manifestPath = __DIR__ . '/../data/run/door_sessions/' . $sessionId . '/runtime.json';
        if (!is_file($manifestPath) || !is_readable($manifestPath)) {
            return null;
        }
        $decoded = json_decode((string)file_get_contents($manifestPath), true);
        $identity = is_array($decoded) ? ($decoded['identity'] ?? null) : null;
        if (!is_array($identity) || empty($identity['pid'])) {
            return null;
        }
        return $identity;
    }

    /**
     * Compare a recorded runtime identity against the live process at that PID.
     *
     * @return string 'gone' | 'match' | 'mismatch'
     */
    private function verifyRuntimeIdentity(int $pid, array $identity): string
    {
        if ($pid < 1) {
            return 'gone';
        }
        // A record from a previous boot can never be re-validated (start-time
        // is ticks-since-boot); refuse to match it.
        $bootId = @trim((string)@file_get_contents('/proc/sys/kernel/random/boot_id'));
        if (!empty($identity['bootId']) && $bootId !== '' && $identity['bootId'] !== $bootId) {
            return 'mismatch';
        }
        $stat = @file_get_contents("/proc/$pid/stat");
        if ($stat === false) {
            return 'gone';
        }
        $rparen = strrpos($stat, ')');
        if ($rparen === false) {
            return 'gone';
        }
        $rest = preg_split('/\s+/', trim(substr($stat, $rparen + 2)));
        // rest[0] = field 3 (state); a zombie/dead process has already exited.
        if (in_array($rest[0] ?? '', ['Z', 'X', 'x'], true)) {
            return 'gone';
        }
        // stat field 5 (pgrp) -> rest[2]; field 22 (starttime) -> rest[19]
        $livePgid = isset($rest[2]) ? (int)$rest[2] : -1;
        $liveStart = $rest[19] ?? '';
        if ((string)$liveStart === (string)($identity['starttime'] ?? '__x')
            && $livePgid === (int)($identity['pgid'] ?? -2)) {
            return 'match';
        }
        return 'mismatch';
    }

    /**
     * Kill a managed door runtime.
     *
     * When an $identity record is supplied this fails closed unless the live
     * process at $pid is provably the same runtime the bridge launched, and
     * signals the whole owned process group when we own its leader. Without an
     * identity record (no bridge manifest) it signals nothing -- the bridge is
     * the authority for managed runtimes and PHP must not raw-kill an
     * unvalidated PID.
     *
     * @param int $pid
     * @param array|null $identity from loadRuntimeIdentity()
     * @return bool true when the runtime is confirmed gone
     */
    private function killProcess(int $pid, ?array $identity = null): bool
    {
        if ($pid < 1) {
            return false;
        }
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

        if ($identity === null) {
            $this->logger->warning("[KillProcess] No bridge runtime manifest for PID $pid - not signalling (fail closed)");
            return $isWindows ? false : !$this->isProcessRunning($pid);
        }

        if ($isWindows) {
            // No /proc on Windows; validate PID liveness only, then taskkill.
            if (!$this->isProcessRunning($pid)) {
                return true;
            }
            exec("taskkill /F /T /PID $pid 2>&1", $output, $returnCode);
            if ($returnCode !== 0) {
                $this->logger->warning("[KillProcess] taskkill failed for PID $pid - code: $returnCode");
            }
            return $returnCode === 0;
        }

        $state = $this->verifyRuntimeIdentity($pid, $identity);
        if ($state === 'gone') {
            return true;
        }
        if ($state === 'mismatch') {
            $this->logger->warning("[KillProcess] Runtime identity mismatch for PID $pid - not signalling (fail closed)");
            return false;
        }

        $ownsGroup = !empty($identity['ownsGroup']) && (int)$identity['pgid'] === $pid;
        $target = $ownsGroup ? '-' . (int)$identity['pgid'] : (string)$pid;
        exec('kill -9 ' . escapeshellarg($target) . ' 2>&1', $output, $returnCode);
        if ($returnCode !== 0) {
            $this->logger->warning("[KillProcess] kill -9 $target failed - code: $returnCode");
        }

        // Confirm the recorded runtime is actually gone.
        return $this->verifyRuntimeIdentity($pid, $identity) === 'gone';
    }

    /**
     * Find process ID by port (Windows)
     *
     * @param int $port Port number
     * @return int|null Process ID
     */
    private function findProcessByPort(int $port): ?int
    {
        // Use cmd.exe explicitly on Windows to avoid Git Bash issues
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $output = shell_exec("cmd /c \"netstat -ano | findstr :$port\"");
        } else {
            $output = shell_exec("netstat -ano | grep :$port");
        }

        if ($output && preg_match('/\s+(\d+)\s*$/', $output, $matches)) {
            return (int)$matches[1];
        }
        return null;
    }

    /**
     * Find process ID by name (Windows)
     *
     * @param string $name Process name
     * @return int|null Process ID
     */
    private function findProcessByName(string $name): ?int
    {
        $output = shell_exec("tasklist | findstr /I $name");
        if ($output && preg_match('/\s+(\d+)\s/', $output, $matches)) {
            return (int)$matches[1];
        }
        return null;
    }

    /**
     * Get all DOSBox process PIDs (Windows)
     *
     * @return array List of PIDs
     */
    private function getDosBoxPids(): array
    {
        $pids = [];
        $output = shell_exec("tasklist | findstr /I dosbox");

        if ($output) {
            $lines = explode("\n", trim($output));
            foreach ($lines as $line) {
                if (preg_match('/\s+(\d+)\s/', $line, $matches)) {
                    $pids[] = (int)$matches[1];
                }
            }
        }

        return $pids;
    }

    /**
     * Log a session event
     *
     * @param string $sessionId Session identifier
     * @param string $eventType Event type (launched, connected, disconnected, error, terminated, etc.)
     * @param array $eventData Additional event data
     * @return void
     */
    private function logSessionEvent(string $sessionId, string $eventType, array $eventData = []): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO door_session_logs (session_id, event_type, event_data)
            VALUES (?, ?, ?)
        ");

        $stmt->execute([
            $sessionId,
            $eventType,
            json_encode($eventData)
        ]);
    }
}
