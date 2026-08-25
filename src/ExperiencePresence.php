<?php

/*
 * Copright Matthew Asham and BinktermPHP Contributors
 *
 * Redistribution and use in source and binary forms, with or without modification, are permitted provided that the
 * following conditions are met:
 *
 * Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
 * Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.
 * Neither the name of the copyright holder nor the names of its contributors may be used to endorse or promote products derived from this software without specific prior written permission.
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE
 *
 */

namespace BinktermPHP;

/**
 * ExperiencePresence
 *
 * Small facade for publishing Experience activity through BinkTerm's existing
 * authenticated-session presence system.
 *
 * This deliberately does not create a second presence store. user_sessions is
 * already the canonical source for live BinkTerm presence and ActivityTracker
 * remains the canonical source for historical activity.
 */
class ExperiencePresence
{
    private Auth $auth;

    public function __construct(?Auth $auth = null)
    {
        $this->auth = $auth ?? new Auth();
    }

    /**
     * Mark an authenticated BinkTerm session as playing an Experience.
     */
    public function enter(string $sessionId, array $experience): void
    {
        $name = $this->displayName($experience);

        if ($sessionId === '' || $name === '') {
            return;
        }

        $this->auth->updateSessionActivity(
            $sessionId,
            'Playing ' . $name
        );
    }

    /**
     * Replace Experience activity with a neutral BinkTerm activity.
     *
     * Auth::updateSessionActivity() intentionally ignores an empty string, so
     * clearing is represented by returning the session to the generic BBS
     * activity rather than attempting to write an empty value.
     */
    public function leave(string $sessionId): void
    {
        if ($sessionId === '') {
            return;
        }

        $this->auth->updateSessionActivity($sessionId, 'BBS');
    }

    /**
     * Resolve the human-readable Experience name from the normalized catalog
     * record while remaining tolerant of compatibility callers.
     */
    private function displayName(array $experience): string
    {
        $name = trim((string)($experience['name'] ?? ''));

        if ($name !== '') {
            return $name;
        }

        return trim((string)($experience['id'] ?? ''));
    }
}
