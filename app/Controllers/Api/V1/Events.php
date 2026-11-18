<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use App\Libraries\PayPeriod;
use App\Libraries\PeriodLockRepository;

/**
 * POST /api/v1/events — change notifications from the other HRIS apps.
 *
 * Handled here:
 *   payroll.finalized {period_id, by}  -> lock the period's DTR
 *   payroll.reopened  {period_id}      -> unlock it
 * Other event types are accepted and ignored (202), so senders can add
 * new events without breaking this app.
 */
class Events extends BaseController
{
    public function receive()
    {
        $event = $this->request->getJSON(true);
        $type  = (string) ($event['type'] ?? '');
        $data  = (array) ($event['data'] ?? []);

        if ($type === '') {
            return $this->error(422, 'validation', 'Event type is required.');
        }

        try {
            switch ($type) {
                case 'payroll.finalized':
                case 'payroll.reopened':
                    $periodId = PayPeriod::fromId((string) ($data['period_id'] ?? ''))->id;
                    $locks    = new PeriodLockRepository();
                    $type === 'payroll.finalized'
                        ? $locks->lock($periodId, (string) ($data['by'] ?? ''))
                        : $locks->unlock($periodId);
                    log_message('info', "HRIS event {$type} {$periodId} from " . ($event['source'] ?? '?'));

                    return $this->response->setJSON(['api_version' => 'v1', 'status' => 'applied']);

                default:
                    return $this->response->setStatusCode(202)->setJSON(['api_version' => 'v1', 'status' => 'ignored']);
            }
        } catch (\InvalidArgumentException $e) {
            return $this->error(422, 'validation', $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', "HRIS event {$type} failed: " . $e->getMessage());

            return $this->error(503, 'unavailable', 'Event could not be applied.');
        }
    }

    private function error(int $status, string $code, string $message)
    {
        return $this->response->setStatusCode($status)->setJSON(['error' => ['code' => $code, 'message' => $message]]);
    }
}
