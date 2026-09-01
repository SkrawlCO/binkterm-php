'use strict';

function isProcessRunning(pid) {
    try {
        process.kill(pid, 0);
        return true;
    } catch (err) {
        return err.code !== 'ESRCH';
    }
}

async function waitForProcessExit(pid, gracefulTimeoutMs, forceTimeoutMs, logger = console) {
    if (!pid || !isProcessRunning(pid)) {
        return true;
    }

    const gracefulDeadline = Date.now() + gracefulTimeoutMs;
    while (Date.now() < gracefulDeadline) {
        if (!isProcessRunning(pid)) {
            return true;
        }
        await new Promise(resolve => setTimeout(resolve, 25));
    }

    logger.warn(`Runtime PID ${pid} did not exit after SIGTERM; force killing`);
    try {
        process.kill(pid, 'SIGKILL');
    } catch (err) {
        if (err.code === 'ESRCH') {
            return true;
        }
        logger.error(`Failed to force kill runtime PID ${pid}: ${err.message}`);
        return false;
    }

    const forcedDeadline = Date.now() + forceTimeoutMs;
    while (Date.now() < forcedDeadline) {
        if (!isProcessRunning(pid)) {
            return true;
        }
        await new Promise(resolve => setTimeout(resolve, 25));
    }
    return !isProcessRunning(pid);
}

module.exports = { isProcessRunning, waitForProcessExit };
