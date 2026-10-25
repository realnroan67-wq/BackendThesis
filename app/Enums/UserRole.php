<?php

namespace App\Enums;

/**
 * PURPOSE: Authoritative single-role values for Horizon Realty users.
 * CURRENT: Roles are assigned only by trusted backend code, never public input.
 * FUTURE: Registration must explicitly choose an approved non-administrator role.
 * Role eligibility never replaces property ownership or inquiry participant checks.
 */
enum UserRole: string
{
    case ADMINISTRATOR = 'administrator';
    case PROPERTY_PROVIDER = 'property_provider';
    case BUSINESS_USER = 'business_user';
}
