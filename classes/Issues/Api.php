<?php

/**
 * Helpers for the Issues REST API (wwwroot/api/v1/_issues.php and the
 * _issue_*.php routes it includes).
 *
 * @see \Issues\IssueService
 */

declare(strict_types=1);

namespace Issues;

class Api
{
    /**
     * Runs one IssueService call, answering its errors with the API's usual codes.
     *
     * @param \Closure(): void $call
     */
    public static function run(\Closure $call): void
    {
        try {
            $call();
        } catch (\Input\InvalidInput | ValidationException $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (NotFoundException $e) {
            http_response_code(404);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (AccessException $e) {
            http_response_code(403);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (ConflictException $e) {
            http_response_code(409);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * The envelope every list read answers with.
     *
     * @template T of \JsonSerializable
     * @param Page<T> $page
     * @return array<string, mixed>
     */
    public static function page(string $key, Page $page): array
    {
        return [
            $key     => $page->items,
            'total'  => $page->total,
            'limit'  => $page->limit,
            'offset' => $page->offset,
        ];
    }
}
