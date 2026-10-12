<?php

namespace App\Services\Registry;

enum ManifestLookup: string
{
    case FOUND = 'found';
    case NOT_FOUND = 'not_found';
    case AUTH_REQUIRED = 'auth_required';
    case UNREACHABLE = 'unreachable';
    case INVALID_REFERENCE = 'invalid_reference';
}
