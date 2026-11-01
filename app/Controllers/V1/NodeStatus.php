<?php

namespace App\Controllers\V1;

use App\Controllers\BaseController;
use App\Core\Api\ApiResponse;
use App\Core\Node\NodeInfo;
use App\Core\Node\SelfTest;

class NodeStatus extends BaseController
{
    public function show()
    {
        $info = (new NodeInfo(config('Mailrunner')))->toArray();

        return ApiResponse::ok(['version' => $info['version'], 'roles' => $info['roles'], 'selftest' => SelfTest::last(db_connect())]);
    }
}
