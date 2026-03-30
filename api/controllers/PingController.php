<?php
/**
 * PingController.php — /crufiture
 * Healthcheck requis par le cockpit /ferme.
 * @php 7.4+
 */

use helpers\ResponseHelper;

class PingController
{
    public function ping()
    {
        echo ResponseHelper::jsonResponse('pong', 'success');
    }
}
