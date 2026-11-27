<?php

namespace App\Services;

use App\Models\PlatformAuditAction;
use App\Models\PlatformAuditEvent;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Records governance events for platform and workspace administration.
 *
 * Metadata keeps only scalar, non-secret values so the trail can be shown to administrators and exported safely.
 */
class PlatformAuditLogger
{
    private const REDACTED_KEY_SEGMENTS = ['password', 'secret', 'token', 'credential', 'apikey'];

    private const MAX_STRING_LENGTH = 255;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        PlatformAuditAction $action,
        ?Model $subject = null,
        array $metadata = [],
        ?int $workspaceOwnerUserId = null,
        ?User $actor = null,
    ): PlatformAuditEvent {
        $request = request();
        $actor ??= $request->user();

        return PlatformAuditEvent::query()->create([
            'workspace_id' => $workspaceOwnerUserId !== null ? Workspace::idForOwnerUserId($workspaceOwnerUserId) : null,
            'actor_user_id' => $actor instanceof User ? $actor->getKey() : null,
            'action' => $action,
            'subject_type' => $subject !== null ? $this->subjectType($subject) : null,
            'subject_id' => $subject?->getKey(),
            'metadata' => $this->sanitize($metadata),
            'ip_address' => $request->ip(),
        ]);
    }

    /**
     * Describe changed fields as before/after pairs, keeping only fields that actually changed.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{before: mixed, after: mixed}>
     */
    public function changes(array $before, array $after): array
    {
        $changes = [];

        foreach ($after as $field => $value) {
            $previous = $before[$field] ?? null;

            if ($this->normalize($previous) !== $this->normalize($value)) {
                $changes[$field] = ['before' => $this->normalize($previous), 'after' => $this->normalize($value)];
            }
        }

        return $changes;
    }

    private function subjectType(Model $subject): string
    {
        return str((new \ReflectionClass($subject))->getShortName())->snake()->toString();
    }

    /**
     * @param  array<array-key, mixed>  $metadata
     * @return array<array-key, mixed>
     */
    private function sanitize(array $metadata): array
    {
        $sanitized = [];

        foreach ($metadata as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                continue;
            }

            $sanitized[$key] = is_array($value) ? $this->sanitize($value) : $this->normalize($value);
        }

        return $sanitized;
    }

    private function isSensitiveKey(string $key): bool
    {
        $segments = preg_split('/[^a-z0-9]+/', str_replace('api_key', 'apikey', str($key)->snake()->lower()->toString())) ?: [];

        return array_intersect($segments, self::REDACTED_KEY_SEGMENTS) !== [];
    }

    private function normalize(mixed $value): string|int|float|bool|null
    {
        return match (true) {
            $value === null, is_bool($value), is_int($value), is_float($value) => $value,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            $value instanceof \BackedEnum => $value->value,
            is_scalar($value), $value instanceof \Stringable => mb_substr((string) $value, 0, self::MAX_STRING_LENGTH),
            default => null,
        };
    }
}
