<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api;

/** Select the reviewed encoding of a generated POST operation's fields. @internal */
enum RequestBodyFormat: string
{
    case Form = 'form';
    case Multipart = 'multipart';
    case Json = 'json';
}
