<?php

/**
 * Helpers for the Projects REST API (wwwroot/api/v1/_projects.php).
 *
 * @see \Projects\ProjectService
 */

declare(strict_types=1);

namespace Projects;

class Api
{
    /**
     * Runs one ProjectService call, answering its errors with the API's usual codes.
     *
     * @param \Closure(): void $call
     */
    public static function run(\Closure $call): void
    {
        try {
            $call();
        } catch (\Input\InvalidInput | \Issues\ValidationException $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (\Issues\NotFoundException $e) {
            http_response_code(404);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (\Issues\AccessException $e) {
            http_response_code(403);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (\Issues\ConflictException $e) {
            http_response_code(409);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * The decoded JSON body of a write.
     *
     * @return array<mixed>
     */
    public static function body(): array
    {
        return \Input\Input::json((string) file_get_contents('php://input'));
    }
}
