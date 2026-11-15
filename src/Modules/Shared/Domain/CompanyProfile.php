<?php

declare(strict_types=1);

namespace Modules\Shared\Domain;

/** Port: the company's identity (name, tax number, address ...) as printed on documents. */
interface CompanyProfile
{
    /** @return array{name:string,tax_id:string,commercial_register:string,address:string,phone:string,email:string} */
    public function get(): array;

    public function name(): string;
}
