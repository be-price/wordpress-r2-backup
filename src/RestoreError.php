<?php
declare(strict_types=1);

namespace WordPressR2Backup;

/** A message written for the screen. Only this type's text reaches a person; every other exception becomes a fixed sentence. */
final class RestoreError extends \RuntimeException
{
    private ?string $field;

    public function __construct(string $message, ?string $field = null)
    {
        parent::__construct($message);
        $this->field = $field;
    }

    public function field(): ?string { return $this->field; }
}
