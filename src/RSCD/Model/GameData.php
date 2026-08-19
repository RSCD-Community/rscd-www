<?php

namespace RSCD\Model;

/**
 * Static access to the generated game-data maps and RSC formulae.
 *
 * The data files under src/RSCD/Data/ are generated from the game server's
 * own definition sources by tools/generate-game-data.py — item names come
 * from conf/server/defs/ItemDef.xml and quest names/final stages from the
 * quest classes. Regenerate them (never hand-edit) when the server changes.
 */
class GameData {

    /**
     * The 19 skills in the order the game database stores them: each is both
     * a cur_<key> column in rscd_curstats and an exp_<key> column in
     * rscd_experience.
     */
    const SKILLS = [
        'attack'     => 'Attack',
        'defense'    => 'Defense',
        'strength'   => 'Strength',
        'hits'       => 'Hits',
        'ranged'     => 'Ranged',
        'prayer'     => 'Prayer',
        'magic'      => 'Magic',
        'cooking'    => 'Cooking',
        'woodcut'    => 'Woodcutting',
        'fletching'  => 'Fletching',
        'fishing'    => 'Fishing',
        'firemaking' => 'Firemaking',
        'crafting'   => 'Crafting',
        'smithing'   => 'Smithing',
        'mining'     => 'Mining',
        'herblaw'    => 'Herblaw',
        'agility'    => 'Agility',
        'thieving'   => 'Thieving',
        'runecrafting' => 'Runecrafting',
    ];

    /**
     * Cumulative experience required for levels 2..99 — the standard RSC
     * table the server's Formulae class and the legacy site both use.
     */
    const XP_TABLE = [
        83, 174, 276, 388, 512, 650, 801, 969, 1154, 1358, 1584, 1833,
        2107, 2411, 2746, 3115, 3523, 3973, 4470, 5018, 5624, 6291, 7028,
        7842, 8740, 9730, 10824, 12031, 13363, 14833, 16456, 18247, 20224,
        22406, 24815, 27473, 30408, 33648, 37224, 41171, 45529, 50339,
        55649, 61512, 67983, 75127, 83014, 91721, 101333, 111945, 123660,
        136594, 150872, 166636, 184040, 203254, 224466, 247886, 273742,
        302288, 333804, 368599, 407015, 449428, 496254, 547953, 605032,
        668051, 737627, 814445, 899257, 992895, 1096278, 1210421, 1336443,
        1475581, 1629200, 1798808, 1986068, 2192818, 2421087, 2673114,
        2951373, 3258594, 3597792, 3972294, 4385776, 4842295, 5346332,
        5902831, 6517253, 7195629, 7944614, 8771558, 9684577, 10692629,
        11805606, 13034431, 14391160,
    ];

    /** @var array|null Lazy-loaded item id => name map. */
    protected static $itemNames = null;

    /** @var array|null Lazy-loaded quest id => [name, final] map. */
    protected static $questData = null;

    /** @var array|null Lazy-loaded npc id => quest slugs map. */
    protected static $npcQuests = null;

    /** @var array|null Lazy-loaded slug => quest metadata map. */
    protected static $manualQuests = null;

    /** @var array|null uid => slug, built from manualQuests(). */
    protected static $questSlugByUid = null;

    /** @var array|null Lazy-loaded Data/manual-pages.php. */
    protected static $manualPages = null;

    /** @var array|null Lazy-loaded Data/npc-data.php. */
    protected static $npcData = null;

    /**
     * Convert raw experience points to a skill level.
     *
     * The exp_* columns store quarter-unit experience, the way real RSC
     * kept it: the displayed value times four, so fractional awards like
     * 3.75 per bone are exact integers. XP_TABLE is displayed-scale, so
     * divide before comparing — the same thing the server's
     * Formulae.experienceToLevel does.
     *
     * @param  int $experience Raw exp_* column value (quarter-units).
     * @return int             Level 1-99.
     */
    public static function experienceToLevel($experience) {
        $experience = intdiv((int)$experience, 4);
        foreach(static::XP_TABLE as $index => $required) {
            if($experience < $required) {
                return $index + 1;
            }
        }
        return 99;
    }

    /**
     * The experience figure a player is shown for a raw exp_* column value —
     * quarter-units divided down to displayed scale. Every place the site
     * prints an experience number goes through here.
     *
     * @param  int $experience Raw exp_* column value (quarter-units).
     * @return int             Displayed experience.
     */
    public static function displayExperience($experience) {
        return intdiv((int)$experience, 4);
    }

    /**
     * The experience floor for a skill level — the inverse of
     * experienceToLevel, used when an admin sets a level directly. XP_TABLE
     * is displayed-scale, so multiply up to the quarter-units the exp_*
     * columns store.
     *
     * @param  int $level Level 1-99.
     * @return int        Raw exp_* column value (quarter-units).
     */
    public static function experienceForLevel($level) {
        $level = max(1, min(99, (int)$level));
        return $level === 1 ? 0 : static::XP_TABLE[$level - 2] * 4;
    }

    /**
     * Display name for an item id.
     *
     * @param  int    $itemId Item id (== index in the server's def array).
     * @return string         Item name, or 'Unknown item #<id>'.
     */
    public static function itemName($itemId) {
        if(static::$itemNames === null) {
            static::$itemNames = require __DIR__ . '/../Data/item-names.php';
        }
        return static::$itemNames[$itemId] ?? ('Unknown item #' . (int)$itemId);
    }

    /**
     * The vanilla quest table: quest id => ['name' => ..., 'final' => [...]].
     *
     * @return array
     */
    public static function questData() {
        if(static::$questData === null) {
            static::$questData = require __DIR__ . '/../Data/quest-data.php';
        }
        return static::$questData;
    }

    /**
     * The quests (and minigames written as quest classes) that associate an
     * npc, from the quest classes' own define() calls.
     *
     * @param  int $npcId
     * @return string[] Quest slugs, alphabetical; empty when none. Names
     *                  live in manualQuest($slug)['name'].
     */
    public static function questsForNpc($npcId) {
        if(static::$npcQuests === null) {
            static::$npcQuests = require __DIR__ . '/../Data/npc-quests.php';
        }
        return static::$npcQuests[$npcId] ?? [];
    }

    /**
     * Every quest's declared metadata, slug-keyed — the manual's quest
     * pages, generated from the quest classes' own define() declarations
     * (the same ones Quest.grantRewards() pays out of).
     *
     * @return array slug => [name, uid, vanilla, points, members,
     *               description, start, speak, length, req_levels,
     *               req_quests, req_other, reward_items, reward_exp,
     *               reward_other]
     */
    public static function manualQuests() {
        if(static::$manualQuests === null) {
            static::$manualQuests = require __DIR__ . '/../Data/manual-quests.php';
        }
        return static::$manualQuests;
    }

    /**
     * One quest's declared metadata by slug, or null.
     *
     * @param  string $slug
     * @return array|null
     */
    public static function manualQuest($slug) {
        return static::manualQuests()[$slug] ?? null;
    }

    /**
     * The 2003 manual's archived pages, slug => title. Generated by
     * tools/build-manual.py from the Wayback mirror; the sanitised
     * fragment for a slug lives at ui/html/manual/site/<slug>.html.
     *
     * @return array
     */
    public static function manualPages() {
        if(static::$manualPages === null) {
            static::$manualPages = require __DIR__ . '/../Data/manual-pages.php';
        }
        return static::$manualPages;
    }

    /**
     * A quest's manual slug by its uid, or null — how uid-keyed views
     * (the account manager's quest list) link into the manual.
     *
     * @param  int $uid
     * @return string|null
     */
    public static function questSlugByUid($uid) {
        if(static::$questSlugByUid === null) {
            static::$questSlugByUid = [];
            foreach(static::manualQuests() as $slug => $quest) {
                static::$questSlugByUid[$quest['uid']] = $slug;
            }
        }
        return static::$questSlugByUid[$uid] ?? null;
    }

    /**
     * A skill's display name from its index in the standard 18-skill order
     * (the same order as the server's Formulae.statArray).
     *
     * @param  int $index
     * @return string
     */
    public static function skillName($index) {
        $names = array_values(static::SKILLS);
        return $names[$index] ?? ('Skill #' . (int)$index);
    }

    /**
     * NPC definitions and the shared rare drop table, generated from the
     * game server's own defs: ['npcs' => [id => [...]], 'rare' => [...]].
     *
     * @return array
     */
    public static function npcData() {
        if(static::$npcData === null) {
            static::$npcData = require __DIR__ . '/../Data/npc-data.php';
        }
        return static::$npcData;
    }

    /**
     * An npc's combat level, exactly as the server computes it for the
     * right-click label: Formulae.getCombatLevel(att, def, str, hits, 0, 0, 0)
     * — magic, prayer and ranged are always zero for an npc.
     *
     * @param  array $npc One entry from npcData()['npcs'].
     * @return int
     */
    public static function npcCombatLevel($npc) {
        return (int)(($npc['att'] + $npc['str']) / 4 + ($npc['def'] + $npc['hits']) / 4);
    }

}
