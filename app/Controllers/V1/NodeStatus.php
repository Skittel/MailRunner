<?php

namespace App\Controllers\V1;

use App\Controllers\BaseController;
use App\Core\Api\ApiResponse;
use App\Core\Node\NodeInfo;

class NodeStatus extends BaseController
{
    public function show()
    {
        $info = (new NodeInfo(config('Mailrunner')))->toArray();

        return ApiResponse::ok(['version' => $info['version'], 'roles' => $info['roles']]);
    }
}
