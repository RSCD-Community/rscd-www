<?php

namespace RSCD\Model;

use RSCD\Model\Object\Metadata;
use RSCD\Model\Object\User;
use RSCD\Util\Strings;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * AuthToken — short-lived, single-use authentication tokens.
 *
 * Replaces the ERP's dedicated pin_2fa table using the existing metadata /
 * user_metadata tables: each token is one Metadata row with metakey
 * 'auth_token' attached to its user through the user_metadata pivot. The
 * metavalue is a JSON document:
 *
 *   { "hash": sha256(token), "type": "...", "expires_at": "Y-m-d H:i:s",
 *     "payload": {...} }
 *
 * Only the SHA-256 hash of the token is stored, so a database read alone
 * cannot forge a valid link — the same principle the session table uses for
 * cookie seeds. Tokens are deleted on use and swept opportunistically on
 * every issue()/find() call, so no cron job is required.
 *
 * Token types:
 *  - TYPE_SESSION_ACTIVATION: two-step sign-in handoff (5 minutes). The POST
 *    handler verifies credentials and issues this token; the GET handler at
 *    /activate-session/ exchanges it for the real session cookie.
 *  - TYPE_PASSWORD_RESET: forgot-password email link (15 minutes, as stated
 *    in the reset email copy).
 */
class AuthToken {

    /** metadata.metakey shared by every auth token row. */
    const METAKEY = 'auth_token';

    /** Two-step sign-in activation handoff. */
    const TYPE_SESSION_ACTIVATION = 'session_activation';

    /** Forgot-password reset link. */
    const TYPE_PASSWORD_RESET = 'password_reset';

    /** Lifetime in seconds per token type. */
    const LIFETIMES = [
        self::TYPE_SESSION_ACTIVATION => 300,
        self::TYPE_PASSWORD_RESET => 900
    ];

    /**
     * Issue a new token for a user.
     *
     * Any previous live token of the same type for the same user is removed
     * first, so a token link can never be valid twice and re-requesting a
     * reset invalidates the earlier email.
     *
     * @param  User    $user     The user the token authenticates.
     * @param  string  $type     One of the TYPE_* constants.
     * @param  array   $payload  Optional data carried to the consumer (e.g. redirect_url).
     * @return string|null       The plain-text token for the URL, or null on failure.
     */
    public static function issue($user, $type, $payload = []) {
        if(empty($user->id) || !isset(self::LIFETIMES[$type])) {
            return null;
        }
        self::purge($user, $type);

        $token = Strings::generate(32, true);
        $metadata = Metadata::create([
            'metakey' => self::METAKEY,
            'metavalue' => json_encode([
                'hash' => hash('sha256', $token),
                'type' => $type,
                'expires_at' => date('Y-m-d H:i:s', time() + self::LIFETIMES[$type]),
                'payload' => (object)$payload
            ])
        ]);
        if(empty($metadata->id)) {
            return null;
        }
        $user->metadata()->attach($metadata->id);
        return $token;
    }

    /**
     * Look up a live token without consuming it.
     *
     * Expired rows encountered along the way are deleted. Type must match —
     * a session-activation token can never be replayed as a password reset.
     *
     * @param  string  $token  The plain-text token from the URL.
     * @param  string  $type   Expected TYPE_* constant.
     * @return object|null     {metadata, user, payload} or null when invalid/expired.
     */
    public static function find($token, $type) {
        if(empty($token) || !preg_match('/^[a-zA-Z0-9]{32}$/', (string)$token)) {
            return null;
        }
        $hash = hash('sha256', $token);
        $metadata = Metadata::where('metakey', self::METAKEY)
            ->where('metavalue', 'LIKE', '%' . $hash . '%')
            ->first();
        if(empty($metadata->id)) {
            return null;
        }
        $value = json_decode((string)$metadata->metavalue);
        if(empty($value->hash) || !hash_equals($value->hash, $hash)
            || empty($value->type) || $value->type !== $type) {
            return null;
        }
        if(empty($value->expires_at) || strtotime($value->expires_at) < time()) {
            self::destroy($metadata);
            return null;
        }
        $user = User::whereHas('metadata', function($query) use($metadata) {
            $query->where('metadata.id', $metadata->id);
        })->first();
        if(empty($user->id)) {
            self::destroy($metadata);
            return null;
        }
        return (object)[
            'metadata' => $metadata,
            'user' => $user,
            'payload' => isset($value->payload) ? $value->payload : (object)[]
        ];
    }

    /**
     * Look up a live token and consume it (single use).
     *
     * @param  string  $token  The plain-text token from the URL.
     * @param  string  $type   Expected TYPE_* constant.
     * @return object|null     {user, payload} or null when invalid/expired.
     */
    public static function consume($token, $type) {
        $found = self::find($token, $type);
        if(empty($found)) {
            return null;
        }
        self::destroy($found->metadata);
        return (object)[
            'user' => $found->user,
            'payload' => $found->payload
        ];
    }

    /**
     * Remove a user's live and expired tokens of one type (or all types).
     *
     * @param  User         $user  The token owner.
     * @param  string|null  $type  TYPE_* constant, or null for every type.
     * @return void
     */
    public static function purge($user, $type = null) {
        if(empty($user->id)) {
            return;
        }
        $rows = $user->metadata()->where('metakey', self::METAKEY)->get();
        foreach($rows as $metadata) {
            $value = json_decode((string)$metadata->metavalue);
            if($type !== null && (!empty($value->type) && $value->type !== $type)
                && (!empty($value->expires_at) && strtotime($value->expires_at) >= time())) {
                continue;
            }
            self::destroy($metadata);
        }
    }

    /**
     * Delete a token row and its user_metadata pivot entries.
     *
     * @param  Metadata  $metadata  The token row to remove.
     * @return void
     */
    protected static function destroy($metadata) {
        DB::table('user_metadata')->where('metadata_id', $metadata->id)->delete();
        $metadata->delete();
    }

}
