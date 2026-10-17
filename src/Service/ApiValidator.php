<?php

namespace App\Service;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ApiValidator
{
    public function __construct(private readonly ValidatorInterface $validator)
    {
    }

    /** @throws ValidationFailedException */
    public function check(mixed $value, ?Constraint $constraint = null): void
    {
        $violations = $this->validator->validate($value, $constraint);
        if (count($violations) === 0) {
            return;
        }
        $map = [];
        foreach ($violations as $v) {
            // "[hero][title]" -> "hero.title" ; on ne garde que le premier message de chaque champ
            $path = ltrim((string) preg_replace('/\[(\w+)\]/', '.$1', $v->getPropertyPath()), '.');
            $map[$path === '' ? '_' : $path] ??= (string) $v->getMessage();
        }

        throw new ValidationFailedException($map);
    }
}
