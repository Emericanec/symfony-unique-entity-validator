<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 * @Target({"CLASS", "ANNOTATION"})
 */
class UniqueEntity extends Constraint
{
    public const IS_NOT_UNIQUE = 'd15a0455-4c62-4b0c-81fe-fc615a5f6c4b';

    protected static $errorNames = [
        self::IS_NOT_UNIQUE => 'IS_NOT_UNIQUE',
    ];

    public string $message = 'This value is already used.';
    public string $entityClass;
    public string $repositoryMethod = 'findBy';
    public array $fields = [];
    public ?string $errorPath = null;
    public bool $ignoreNull = true;

    public function getRequiredOptions(): array
    {
        return ['entityClass', 'fields'];
    }

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}