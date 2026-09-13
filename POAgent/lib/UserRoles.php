<?php
// UserRoles.php — classifies each generator_id (desktop "user1"/"user2"/
// "user3", or a mobile phone number) as a field user (FU) or backoffice
// user (BOU). The "maker-checker" split this whole feature is for: an FU
// can create a PO and upload a DN photo, but only a BOU can actually run
// OCR/review/finalize it into a VS — explicit request (Sept 2026): a field
// manager doing their own OCR review isn't trusted to catch mistakes the
// way an office process would, so that step is reserved for the backoffice.
//
// Config: POAgent/user_roles.json — flat {generator_id: "fu"|"bou"} map.
// Anyone NOT listed defaults to FU (the safer default — an unconfigured or
// newly-added user gets the MORE restricted role, never silently inherits
// BOU powers by omission).

define('POAGENT_USER_ROLES_FILE', __DIR__ . '/../user_roles.json');

/** @return 'fu'|'bou' */
function poagent_user_role(string $generatorId): string
{
    static $roles = null;
    if ($roles === null) {
        $roles = [];
        if (is_file(POAGENT_USER_ROLES_FILE)) {
            $decoded = json_decode((string) file_get_contents(POAGENT_USER_ROLES_FILE), true);
            if (is_array($decoded)) {
                $roles = $decoded;
            }
        }
    }
    $role = strtolower(trim((string) ($roles[$generatorId] ?? '')));
    return $role === 'bou' ? 'bou' : 'fu';
}

function poagent_is_bou(string $generatorId): bool
{
    return poagent_user_role($generatorId) === 'bou';
}

function poagent_is_fu(string $generatorId): bool
{
    return !poagent_is_bou($generatorId);
}
