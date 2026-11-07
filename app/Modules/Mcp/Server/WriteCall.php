<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Server;

use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\Exception\NotFoundHttpException;
use SeoControlCenter\Domain\Shared\ValidationException;

/**
 * Helpers for write tools: service exceptions become safe tool errors, and
 * `expected_version` is read the same way everywhere.
 */
final class WriteCall
{
    public const string CONFLICT = 'conflict';

    /**
     * Runs a service call. Validation, permission, feature, not-found and
     * conflict answers become ToolError with the service's safe message.
     *
     * A 409 is a version conflict only for a versioned write (one that passes
     * `$current`); it gets the "Conflict:" prefix, `$conflict` in place of the
     * service's browser wording when given, and the current version. Any other
     * 409 is a limit or state rejection and keeps the service's message as is.
     *
     * @template T
     * @param \Closure(): T $write
     * @param (\Closure(): (int|string|null))|null $current reads the current version for a conflict answer
     * @param string|null $conflict neutral conflict text for MCP clients
     * @return T
     * @throws ToolError
     */
    public static function run(\Closure $write, string $notFound = 'Not found.', ?\Closure $current = null, ?string $conflict = null): mixed
    {
        try {
            return $write();
        } catch (ValidationException $e) {
            $fields = [];
            foreach ($e->errors() as $field => $message) {
                $fields[] = $field . ': ' . $message;
            }
            throw new ToolError('Invalid input. ' . implode(' ', $fields));
        } catch (NotFoundHttpException) {
            throw new ToolError($notFound);
        } catch (HttpException $e) {
            throw new ToolError(match ($e->statusCode()) {
                403 => $e->getMessage() !== '' && !str_starts_with($e->getMessage(), 'You do not have') ? $e->getMessage() : 'The token\'s user is not allowed to change this project (edit permission is required).',
                409 => $current === null ? $e->getMessage() : 'Conflict: ' . ($conflict ?? $e->getMessage()) . self::currentVersion($current),
                default => $e->getMessage() !== '' ? $e->getMessage() : 'The change was rejected.',
            });
        }
    }

    /** @param (\Closure(): (int|string|null))|null $current */
    private static function currentVersion(?\Closure $current): string
    {
        if ($current === null) {
            return '';
        }
        try {
            $value = $current();
        } catch (\Throwable) {
            return '';
        }

        return $value === null ? '' : ' Current version: ' . $value . '.';
    }

    /**
     * @param array<string, mixed> $arguments
     * @throws ToolError
     */
    public static function expectedVersion(array $arguments): int
    {
        $value = $arguments['expected_version'] ?? null;
        if (!is_int($value) || $value < 0 || $value > 999_999_999) {
            throw new ToolError('expected_version is required: pass the version from the latest read of this record (0 when it does not exist yet).');
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    public static function versionSchema(string $description = 'The version from your latest read. A different current version answers a conflict instead of overwriting.'): array
    {
        return ['type' => 'integer', 'minimum' => 0, 'description' => $description];
    }

    /**
     * @return array<string, mixed>
     */
    public static function requestIdSchema(): array
    {
        return ['type' => 'string', 'minLength' => 8, 'maxLength' => 64, 'pattern' => '^[A-Za-z0-9._:-]+$', 'description' => 'Optional. A unique id per intended change; a retry with the same id within 24 hours returns the first result instead of writing twice.'];
    }

    /**
     * @param array<string, mixed> $arguments
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    public static function only(array $arguments, array $keys): array
    {
        return array_intersect_key($arguments, array_flip($keys));
    }
}
