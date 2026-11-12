<?php

declare(strict_types=1);

namespace App\Domain\Security\Contracts;

use App\Domain\Assistant\Data\AttachedFile;

interface WorkspaceGuard
{
    /**
     * @param  list<string>  $relativePaths
     * @return list<AttachedFile>
     */
    public function read(array $relativePaths): array;
}
