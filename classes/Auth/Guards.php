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

declare(strict_types=1);

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
     * @param array<string, mixed>  $auth_actor Row of can_* capability flags.
     * @param string $flag       Column name, e.g. 'can_read_inbox'.
     * @param string $label      Phrase for the message, e.g. 'read inbox'.
     * @return array{code: int, error: string}|null null if permitted, else ['code'=>403,'error'=>...].
     */
    public function permission(array $auth_actor, string $flag, string $label): ?array
    {
        if (empty($auth_actor[$flag])) {
            return ['code' => 403, 'error' => "This API key does not have permission to $label"];
        }
        return null;
    }

    /**
     * Allow repository writes for a human key on an admin account, or for
     * any actor granted can_write_repos.
     *
     * Agents on an admin account do not inherit the admin's right: the
     * account role says nothing about which of its agents to trust.
     *
     * @param array<string, mixed> $auth_actor       agent_inbox_user row of the key.
     * @param bool                 $is_admin_account users.role is 'admin'.
     * @param string               $label            Phrase for the message, e.g. 'create repositories'.
     * @return array{code: int, error: string}|null null if permitted, else ['code'=>403,'error'=>...].
     */
    public function repoWrite(array $auth_actor, bool $is_admin_account, string $label): ?array
    {
        $is_human = ($auth_actor['actor_type'] ?? null) === 'human';
        if (($is_human && $is_admin_account) || !empty($auth_actor['can_write_repos'])) {
            return null;
        }
        return ['code' => 403, 'error' => "This API key does not have permission to $label"];
    }

    /**
     * Enforce the byte-length cap on a text field. Length is measured in BYTES
     * (strlen), so a multi-byte string counts each byte — matching the wire
     * limit the endpoints enforce.
     *
     * @param string $field Field name for the message, e.g. 'message'.
     * @param string $value Raw value to measure.
     * @param int    $max   Byte cap (default MAX_MESSAGE_BYTES).
     * @return array{code: int, error: string}|null null if within limit, else ['code'=>400,'error'=>...].
     */
    public function byteLimit(string $field, string $value, int $max = self::MAX_MESSAGE_BYTES): ?array
    {
        $len = strlen($value);
        if ($len > $max) {
            return ['code' => 400, 'error' => "$field exceeds $max byte limit ($len bytes)"];
        }
        return null;
    }

    /**
     * The text of a request field, decoded per the request's optional
     * `encoding` ('base64' or 'gzip+base64'; default plain).
     *
     * A caller behind the request firewall posts source code encoded, because
     * the firewall refuses it as plain text; this turns it back into the text
     * the endpoint would have been sent. Plain text comes back exactly as
     * before, untrimmed, '' when the field is missing, so an endpoint's own
     * trim, empty and byte-limit checks run unchanged on the result. A gzip
     * body may not inflate past MAX_MESSAGE_BYTES, so the byte limit cannot be
     * dodged by compressing.
     *
     * @param array<mixed> $input Decoded JSON request body.
     * @param string $field Field to read, e.g. 'message' or 'response'.
     * @return array{code: int, error: string}|string the text, or a 400 verdict.
     */
    public function decodedText(array $input, string $field): array|string
    {
        try {
            return \Input\Input::encoding($input, 'encoding')->decode(
                \Field::string($input, $field),
                self::MAX_MESSAGE_BYTES,
                $field,
            );
        } catch (\Input\InvalidInput $e) {
            return ['code' => 400, 'error' => $e->getMessage()];
        }
    }
}
