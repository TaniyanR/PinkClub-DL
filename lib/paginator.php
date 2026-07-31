<?php

declare(strict_types=1);

function paginate(int $total, int $page, int $perPage): array
{
    $pages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($page, $pages));
    $offset = ($page - 1) * $perPage;

    return compact('total', 'page', 'perPage', 'pages', 'offset');
}
