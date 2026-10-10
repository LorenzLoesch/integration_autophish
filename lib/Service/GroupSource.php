<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\IntegrationAutoPhish\Service;

use OCP\IGroupManager;
use RuntimeException;

class GroupSource {
    public function __construct(private IGroupManager $groups) {}

    /** @return array{members:array<string,array>,skipped:int,duplicates:int,removalBlocked:bool} */
    public function read(string $groupId): array {
        $group = $this->groups->get($groupId);
        if ($group === null) {
            throw new RuntimeException('The selected Nextcloud group is unavailable.');
        }
        // getUsers() has no pagination limit: any backend exception aborts the complete read.
        $users = $group->getUsers();
        $members = [];
        $skipped = $duplicates = 0;
        foreach ($users as $user) {
            if (!$user->isEnabled()) {
                continue;
            }
            $email = self::normalizeEmail($user->getEMailAddress());
            if ($email === null) {
                $skipped++;
                continue;
            }
            if (isset($members[$email])) {
                $duplicates++;
                continue;
            }
            $member = ['email' => $email];
            $name = trim($user->getDisplayName());
            if ($name !== '') {
                // Nextcloud exposes a display name, not structured first/last names.
                $member['firstName'] = mb_substr($name, 0, 100);
            }
            $members[$email] = $member;
        }
        ksort($members);
        return ['members' => $members, 'skipped' => $skipped, 'duplicates' => $duplicates,
            'removalBlocked' => $skipped > 0 || $duplicates > 0];
    }

    public static function normalizeEmail(mixed $value): ?string {
        if (!is_string($value)) {
            return null;
        }
        $email = strtolower(trim($value));
        return strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }
}
