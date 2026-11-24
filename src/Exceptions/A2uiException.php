<?php

namespace A2uiLivewire\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A protocol-level failure that maps onto an A2UI `error` message.
 *
 * The `errorCode` is one of the codes defined by the A2UI v1.0 specification
 * (VALIDATION_FAILED, INTEGRITY_ERROR, EXPRESSION_ERROR, EXECUTION_ERROR,
 * INVALID_FUNCTION_CALL, UNALLOWED_PARENT, UNALLOWED_CHILD, ...).
 */
class A2uiException extends RuntimeException
{
    public const VALIDATION_FAILED = 'VALIDATION_FAILED';
    public const INTEGRITY_ERROR = 'INTEGRITY_ERROR';
    public const EXPRESSION_ERROR = 'EXPRESSION_ERROR';
    public const EXECUTION_ERROR = 'EXECUTION_ERROR';
    public const INVALID_FUNCTION_CALL = 'INVALID_FUNCTION_CALL';
    public const UNALLOWED_PARENT = 'UNALLOWED_PARENT';
    public const UNALLOWED_CHILD = 'UNALLOWED_CHILD';
    public const CATALOG_ERROR = 'CATALOG_ERROR';
    public const DATA_ERROR = 'DATA_ERROR';

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?string $surfaceId = null,
        public readonly ?string $path = null,
        public readonly ?string $functionCallId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function validation(string $message, ?string $surfaceId = null, string $path = '/'): self
    {
        return new self(self::VALIDATION_FAILED, $message, $surfaceId, $path);
    }

    public static function integrity(string $message, ?string $surfaceId = null): self
    {
        return new self(self::INTEGRITY_ERROR, $message, $surfaceId);
    }

    public static function expression(string $message, ?string $surfaceId = null, ?Throwable $previous = null): self
    {
        return new self(self::EXPRESSION_ERROR, $message, $surfaceId, null, null, $previous);
    }

    public static function execution(string $message, ?string $surfaceId = null, ?Throwable $previous = null): self
    {
        return new self(self::EXECUTION_ERROR, $message, $surfaceId, null, null, $previous);
    }

    public static function invalidFunctionCall(string $message, ?string $functionCallId = null): self
    {
        return new self(self::INVALID_FUNCTION_CALL, $message, null, null, $functionCallId);
    }

    public function withSurface(?string $surfaceId): self
    {
        if ($surfaceId === null || $this->surfaceId !== null) {
            return $this;
        }

        return new self($this->errorCode, $this->getMessage(), $surfaceId, $this->path, $this->functionCallId, $this->getPrevious());
    }

    /**
     * Builds the payload of a renderer-to-agent `error` message.
     *
     * `surfaceId` and `functionCallId` are mutually exclusive on the wire.
     */
    public function toErrorPayload(): array
    {
        $payload = ['code' => $this->errorCode, 'message' => $this->getMessage()];

        if ($this->functionCallId !== null) {
            $payload['functionCallId'] = $this->functionCallId;
        } elseif ($this->surfaceId !== null) {
            $payload['surfaceId'] = $this->surfaceId;
        }

        if (in_array($this->errorCode, [self::VALIDATION_FAILED, self::UNALLOWED_PARENT, self::UNALLOWED_CHILD], true)) {
            $payload['path'] = $this->path ?? '/';
            $payload['surfaceId'] ??= $this->surfaceId ?? '';
            unset($payload['functionCallId']);
        }

        return $payload;
    }
}
