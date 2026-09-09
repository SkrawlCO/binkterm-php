<?php

namespace BinktermPHP\Terminal\Navigation;

/**
 * The set of named platform actions a sysop's declarative navigation may bind
 * to. Ids and availability rules are derived from the existing terminal main
 * menu ({@see \BinktermPHP\TelnetServer\BbsSession}) so a declarative definition
 * expresses the same capabilities without touching PHP.
 *
 * The runtime attaches behaviour to these ids via {@see ActionRegistry::bind()};
 * this catalog only describes what exists.
 */
final class TerminalActionCatalog
{
    /**
     * @return array<int,array{id:string,key:string,fallback:string,availability:string|array,hotkey:?string,group:string,terminates:bool}>
     */
    public static function descriptors(): array
    {
        return [
            ['id' => 'netmail',      'key' => 'ui.terminalserver.server.menu.netmail',     'fallback' => 'Netmail',              'availability' => 'authenticated',         'hotkey' => 'n', 'group' => 'messaging', 'terminates' => false],
            ['id' => 'echomail',     'key' => 'ui.terminalserver.server.menu.echomail',    'fallback' => 'Echomail',             'availability' => 'authenticated',         'hotkey' => 'e', 'group' => 'messaging', 'terminates' => false],
            ['id' => 'bulletins',    'key' => 'ui.terminalserver.server.menu.bulletins',   'fallback' => 'Bulletins',            'availability' => 'authenticated',         'hotkey' => 'u', 'group' => 'messaging', 'terminates' => false],
            ['id' => 'qwk',          'key' => 'ui.terminalserver.server.menu.qwk',         'fallback' => 'QWK Offline Mail',     'availability' => 'feature:qwk',           'hotkey' => 'k', 'group' => 'messaging', 'terminates' => false],
            ['id' => 'shoutbox',     'key' => 'ui.terminalserver.server.menu.shoutbox',    'fallback' => 'Shoutbox',             'availability' => 'feature:shoutbox',      'hotkey' => 's', 'group' => 'community', 'terminates' => false],
            ['id' => 'localchat',    'key' => 'ui.terminalserver.server.menu.chat',        'fallback' => 'Local Chat',           'availability' => 'feature:chat',          'hotkey' => 'c', 'group' => 'community', 'terminates' => false],
            ['id' => 'polls',        'key' => 'ui.terminalserver.server.menu.polls',       'fallback' => 'Polls',                'availability' => 'feature:voting_booth',  'hotkey' => 'p', 'group' => 'community', 'terminates' => false],
            ['id' => 'whosonline',   'key' => 'ui.terminalserver.server.menu.whos_online', 'fallback' => "Who's Online",         'availability' => 'authenticated',         'hotkey' => 'w', 'group' => 'community', 'terminates' => false],
            ['id' => 'doors',        'key' => 'ui.terminalserver.server.menu.doors',       'fallback' => 'Games & Experiences',  'availability' => 'feature:webdoors',      'hotkey' => 'd', 'group' => 'community', 'terminates' => false],
            ['id' => 'interests',    'key' => 'ui.terminalserver.server.menu.interests',   'fallback' => 'Interests',            'availability' => 'feature:interests',     'hotkey' => 'i', 'group' => 'community', 'terminates' => false],
            ['id' => 'files',        'key' => 'ui.terminalserver.server.menu.files',       'fallback' => 'Files',                'availability' => 'feature:file_areas',    'hotkey' => 'f', 'group' => 'files',     'terminates' => false],
            ['id' => 'freqrequests', 'key' => 'ui.terminalserver.server.menu.freqrequests','fallback' => 'File Requests',        'availability' => 'feature:freq',          'hotkey' => 'r', 'group' => 'files',     'terminates' => false],
            ['id' => 'bbslist',      'key' => 'ui.terminalserver.server.menu.bbs_list',    'fallback' => 'BBS Directory',        'availability' => 'feature:bbs_directory', 'hotkey' => 'b', 'group' => 'explore',   'terminates' => false],
            ['id' => 'nodelist',     'key' => 'ui.terminalserver.server.menu.nodelist',    'fallback' => 'Node List',            'availability' => 'feature:nodelist',      'hotkey' => 'l', 'group' => 'explore',   'terminates' => false],
            ['id' => 'settings',     'key' => 'ui.terminalserver.server.menu.settings',    'fallback' => 'Settings',             'availability' => 'authenticated',         'hotkey' => 't', 'group' => 'account',   'terminates' => false],
            ['id' => 'quit',         'key' => 'ui.terminalserver.server.menu.quit',        'fallback' => 'Quit',                 'availability' => 'always',                'hotkey' => 'q', 'group' => 'account',   'terminates' => true],
        ];
    }

    /**
     * A fresh registry populated with the platform action metadata (no bindings).
     */
    public static function defaultRegistry(): ActionRegistry
    {
        $registry = new ActionRegistry();
        foreach (self::descriptors() as $d) {
            $registry->register(new TerminalAction(
                $d['id'],
                $d['key'],
                $d['fallback'],
                AccessExpression::fromConfig($d['availability'], "action:{$d['id']}:availability"),
                $d['hotkey'],
                $d['group'],
                $d['terminates'],
            ));
        }

        return $registry;
    }
}
