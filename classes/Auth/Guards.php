<?php
/**
 * Pure permission / input guards for the /api/v1 endpoints.
 *
 * Decision helpers only: each returns null when the request is allowed, or a
 * ['code' => int, 'error' => string] verdict when it must be rejected. The
 * endpoint still emits the verdict (http_response_code + echo), so Guards has
 * no side effects and is fully unit-testable without a DB, network, or
 * credentials — i.e. it belongs in the keyless, networkless commit tier.
 */
namespace Auth;

class Guards
{
    /** Max byte length for inbox message/response bodies. */
    public const MAX_MESSAGE_BYTES = 10240;

    /**
     * Require a boolean capability flag on the authenticated actor.
     *
     * Missing/0/''/false/null all read as "not granted" (empty() semantics),
     * matching the endpoints' inline !$flag / empty($flag) checks.
     *
     * @param array  $auth_actor Row of can_* capability flags.
     * @param string $flag       Column name, e.g. 'can_read_inbox'.
     * @param string $label      Phrase for the message, e.g. 'read inbox'.
     * @return array|null null if permitted, else ['code'=>403,'error'=>...].
     */
    public function permission(array $auth_actor, string $flag, string $label): ?array
    {
        if (empty($auth_actor[$flag])) {
            return ['code' => 403, 'error' => "This API key does not have permission to $label"];
        }
        return null;
    }

    /**
     * Enforce the byte-length cap on a text field. Length is measured in BYTES
     * (strlen), so a multi-byte string counts each byte — matching the wire
     * limit the endpoints enforce.
     *
     * @param string $field Field name for the message, e.g. 'message'.
     * @param string $value Raw value to measure.
     * @param int    $max   Byte cap (default MAX_MESSAGE_BYTES).
     * @return array|null null if within limit, else ['code'=>400,'error'=>...].
     */
    public function byteLimit(string $field, string $value, int $max = self::MAX_MESSAGE_BYTES): ?array
    {
        $len = strlen($value);
        if ($len > $max) {
            return ['code' => 400, 'error' => "$field exceeds $max byte limit ($len bytes)"];
        }
        return null;
    }
}
