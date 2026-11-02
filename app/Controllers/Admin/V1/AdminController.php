<?php

namespace App\Controllers\Admin\V1;

use App\Controllers\BaseController;
use App\Core\Api\ApiResponse;
use App\Core\Auth\CurrentAdmin;
use App\Core\Support\ChangeContext;
use App\Core\Support\ConfigException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Base of the node admin API (/admin/v1, mTLS). Uses the same services as the CLI (source "admin").
 */
abstract class AdminController extends BaseController
{
    protected function ctx(): ChangeContext
    {
        return CurrentAdmin::context($this->request->getHeaderLine('X-MailRunner-Actor') ?: null);
    }

    /**
     * @return array<string, mixed>
     */
    protected function body(): array
    {
        $data = $this->request->getJSON(true);

        return is_array($data) ? $data : [];
    }

    /** MAILRUNNER_ADMIN_CONFIG=manual: changes wait on the node for mr:config:apply. */
    protected function configManual(): bool
    {
        return config('Mailrunner')->adminConfig === 'manual';
    }

    /** Queues a change (manual mode) and answers 202 with the pending id. */
    protected function queued(string $kind, string $ref, array $body): ResponseInterface
    {
        $id = (new \App\Core\Admin\PendingChanges())->queue($kind, $ref, $body, (string) ($this->ctx()->actor ?? "admin"));

        return ApiResponse::ok(['pending' => true, 'pending_id' => $id,
            'message' => 'Queued: this node applies admin configuration only after approval (mr:config:apply)'], 202);
    }

    /**
     * Runs a config change and maps ConfigException codes to HTTP status codes.
     */
    protected function guard(callable $fn): ResponseInterface
    {
        try {
            return $fn();
        } catch (ConfigException $e) {
            $status = match (true) {
                str_ends_with($e->errorCode, '_NOT_FOUND') => 404,
                str_ends_with($e->errorCode, '_EXISTS')    => 409,
                default                                    => 422,
            };

            return ApiResponse::error($status, $e->errorCode, $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error(422, 'INVALID_REQUEST', $e->getMessage());
        }
    }
}
