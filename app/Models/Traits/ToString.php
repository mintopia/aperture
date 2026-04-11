<?php

namespace App\Models\Traits;

use ReflectionClass;

trait ToString
{
    public function __toString(): string
    {
        $rClass = new ReflectionClass($this);
        $className = $rClass->getShortName();

        $str = sprintf('[%s:%s]', $className, $this->id);
        $description = $this->getStringDescription();
        if ($description) {
            $str .= ' '.$description;
        }

        return $str;
    }

    protected function getStringDescription(): ?string
    {
        $str = $this->code ?? $this->name ?? null;
        // @phpstan-ignore function.alreadyNarrowedType
        if (property_exists($this, 'stringDescriptionProperty')) {
            return $this->{$this->stringDescriptionProperty} ?? null;
        }

        return $str;
    }
}
