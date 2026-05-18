<?php

function jsonResponse(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

function jsonError(string $message, int $statusCode = 400, array $extra = []): void
{
    jsonResponse(array_merge(['error' => $message], $extra), $statusCode);
}

function jsonSuccess(array $payload = [], int $statusCode = 200): void
{
    jsonResponse(array_merge(['success' => true], $payload), $statusCode);
}

function decodeJsonRequestBody(): array
{
    $rawBody = file_get_contents('php://input');

    if ($rawBody === false) {
        jsonError('Unable to read request body.', 400);
    }

    if (trim($rawBody) === '') {
        jsonError('Request body is required.', 400);
    }

    $decoded = json_decode($rawBody, true);

    if (!is_array($decoded)) {
        jsonError('Invalid JSON payload.', 400);
    }

    return $decoded;
}

function requirePositiveInt($value, string $fieldName): int
{
    $intValue = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if ($intValue === false) {
        jsonError("Invalid {$fieldName}.", 400);
    }

    return $intValue;
}

function reportServerException(Throwable $exception, string $publicMessage = 'An unexpected server error occurred.'): void
{
    error_log($exception->getMessage());
    jsonError($publicMessage, 500);
}
