<?php

declare(strict_types=1);

namespace App\Validator;

use Countable;
use Doctrine\Persistence\ManagerRegistry;
use Iterator;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\ConstraintDefinitionException;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use function count;
use function get_class;
use function is_array;
use function is_string;

class UniqueEntityValidator extends ConstraintValidator
{
    private ManagerRegistry $registry;

    public function __construct(ManagerRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * @param mixed $entity
     * @param Constraint $constraint
     */
    public function validate($entity, Constraint $constraint): void
    {
        if (!$constraint instanceof UniqueEntity) {
            throw new UnexpectedTypeException($constraint, UniqueEntity::class);
        }

        if (null !== $constraint->entityClass && !is_string($constraint->entityClass)) {
            throw new UnexpectedTypeException($constraint->entityClass, 'string');
        }

        if (null !== $constraint->repositoryMethod && !is_string($constraint->repositoryMethod)) {
            throw new UnexpectedTypeException($constraint->repositoryMethod, 'string');
        }

        $fields = (array) $constraint->fields;

        if (0 === count($fields)) {
            throw new ConstraintDefinitionException('At least one field has to be specified.');
        }

        if (null === $entity) {
            return;
        }

        if (!class_exists($constraint->entityClass)) {
            throw new ConstraintDefinitionException(sprintf('Entity "%s" does not exist.', $constraint->entityClass));
        }

        $em = $this->registry->getManagerForClass($constraint->entityClass);
        if (!$em) {
            throw new ConstraintDefinitionException(sprintf('Unable to find the object manager associated with an entity of class "%s".', get_class($entity)));
        }

        $class = $em->getClassMetadata($constraint->entityClass);

        $criteria = [];
        $hasNullValue = false;

        foreach ($fields as $fieldName) {
            if (!$class->hasField($fieldName) && !$class->hasAssociation($fieldName)) {
                throw new ConstraintDefinitionException(sprintf('The field "%s" is not mapped by Doctrine, so it cannot be validated for uniqueness.', $fieldName));
            }

            // exist getter?
            if (method_exists($entity, 'get' . ucfirst($fieldName))) {
                $fieldValue = $entity->{'get' . ucfirst($fieldName)}();
            } else {
                $fieldValue = $entity->{$fieldName};
            }

            if (null === $fieldValue) {
                $hasNullValue = true;
            }

            if ($constraint->ignoreNull && null === $fieldValue) {
                continue;
            }

            $criteria[$fieldName] = $fieldValue;

            if (null !== $criteria[$fieldName] && $class->hasAssociation($fieldName)) {
                /* Ensure the Proxy is initialized before using reflection to
                 * read its identifiers. This is necessary because the wrapped
                 * getter methods in the Proxy are being bypassed.
                 */
                $em->initializeObject($criteria[$fieldName]);
            }
        }

        // validation doesn't fail if one of the fields is null and if null values should be ignored
        if ($hasNullValue && $constraint->ignoreNull) {
            return;
        }

        // skip validation if there are no criteria (this can happen when the
        // "ignoreNull" option is enabled and fields to be checked are null
        if (empty($criteria)) {
            return;
        }

        /* Retrieve repository from given entity name.
             * We ensure the retrieved repository can handle the entity
             * by checking the entity is the same, or subclass of the supported entity.
             */
        $repository = $em->getRepository($constraint->entityClass);

        if (!method_exists($repository, $constraint->repositoryMethod)) {
            throw new ConstraintDefinitionException(sprintf('Repository "%s" does not have "%s" method', get_class($repository), $constraint->repositoryMethod));
        }

        $result = $repository->{$constraint->repositoryMethod}($criteria);

        /* If the result is a MongoCursor, it must be advanced to the first
         * element. Rewinding should have no ill effect if $result is another
         * iterator implementation.
         */
        if ($result instanceof Iterator) {
            $result->rewind();
            if ($result instanceof Countable && 1 < count($result)) {
                $result = [$result->current(), $result->current()];
            } else {
                $result = $result->current();
                $result = null === $result ? [] : [$result];
            }
        } elseif (is_array($result)) {
            reset($result);
        } else {
            $result = null === $result ? [] : [$result];
        }

        /* If no entity matched the query criteria or a single entity matched,
         * which is the same as the entity being validated, the criteria is
         * unique.
         */
        if (!$result || (1 === count($result) && current($result) === $entity)) {
            return;
        }

        $errorPath = (string)($constraint->errorPath ?? $fields[0]);
        $invalidValue = $criteria[$errorPath] ?? $criteria[$fields[0]];

        $this->context->buildViolation($constraint->message)
            ->atPath($errorPath)
            ->setInvalidValue($invalidValue)
            ->setCode(UniqueEntity::IS_NOT_UNIQUE)
            ->setCause($result)
            ->addViolation();
    }
}